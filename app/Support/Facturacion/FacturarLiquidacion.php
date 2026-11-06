<?php

namespace App\Support\Facturacion;

use App\Exceptions\AccionRechazada;
use App\Models\DetalleFactura;
use App\Models\Estado;
use App\Models\Factura;
use App\Models\LineaLiquidacion;
use App\Models\Liquidacion;
use App\Models\Persona;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * La factura de la liquidación a la aseguradora (docs/facturacion.md): CRÉDITO, una por liquidación aprobada (también con pagos, PARCIAL
 * o PAGADO) mientras siga pendiente de facturar.
 *
 * - Cliente: la aseguradora (el responsable de pago), con el nombre y el RUC de su persona (copiados). Sin RUC no se emite. Sin
 *   paciente ni caja.
 * - Una línea por prestación de la liquidación (parte ASEGURADORA): el procedimiento, cantidad 1, lo que paga la aseguradora (el monto
 *   de la línea de la liquidación) y la tasa del procedimiento. El total es el de la liquidación. La parte de la paciente (el copago) se
 *   factura aparte, en Caja: el índice por parte (`detalles_factura_una_por_parte`) impide facturar dos veces la misma.
 * - El mismo timbrado y la misma numeración que la contado (EmitirFactura::registrar, con el timbrado bloqueado): sin saltos ni
 *   repetidos, aunque se emitan dos a la vez. Una sola no anulada por liquidación (`facturas_una_por_liquidacion`).
 * - La liquidación pasa a FACTURADA. Si ya está pagada, la factura queda COBRADA en la misma operación; después sigue a los pagos
 *   (`sincronizarCobro`, desde Liquidaciones\PagosAseguradora).
 *
 * Queda en Auditoría: CREAR de la factura y de cada línea ("Factura 001-001-…"), EDITAR de la liquidación ("Liquidación facturada:
 * factura …") y, si corresponde, EDITAR de la factura ("Factura … cobrada"). El permiso (CREAR sobre FACTURAS_LIQUIDACION) lo controla
 * la ruta.
 */
final class FacturarLiquidacion
{
    public const NO_APROBADA = 'Solo se factura una liquidación aprobada (con o sin pagos).';

    public const YA_FACTURADA = 'Esta liquidación ya tiene su factura.';

    public const PRESTACION_FACTURADA = 'Una de las prestaciones ya tiene facturada la parte de la aseguradora.';

    public static function sinRuc(string $aseguradora): string
    {
        return "La aseguradora {$aseguradora} no tiene RUC: cárguelo en los datos de su persona (Personas) antes de emitir la factura.";
    }

    /** Por qué no se puede emitir ahora (para avisar en la pantalla, sin bloquear), o null si se puede. */
    public static function faltaParaEmitir(Liquidacion $liquidacion): ?string
    {
        $liquidacion->loadMissing('responsablePago.persona');

        return match (true) {
            ! $liquidacion->aprobada() => self::NO_APROBADA,
            ! $liquidacion->pendienteDeFacturar() => self::YA_FACTURADA,
            blank($liquidacion->responsablePago?->persona?->ruc) => self::sinRuc($liquidacion->aseguradoraTexto()),
            default => EmitirFactura::timbradoParaEmitir()[1],
        };
    }

    public static function emitir(User $usuario, Liquidacion $liquidacion): Factura
    {
        try {
            return DB::transaction(function () use ($usuario, $liquidacion) {
                // La liquidación primero (el mismo orden que los pagos y la anulación de la factura); después, el timbrado.
                $actual = Liquidacion::query()->whereKey($liquidacion->getKey())->lockForUpdate()->firstOrFail();
                if (! $actual->aprobada()) {
                    throw new AccionRechazada(self::NO_APROBADA);
                }
                if (! $actual->pendienteDeFacturar() || $actual->facturaVigente()->exists()) {
                    throw new AccionRechazada(self::YA_FACTURADA);
                }
                $persona = Persona::query()->findOrFail($actual->responsablePago->persona_id);
                if (blank($persona->ruc)) {
                    throw new AccionRechazada(self::sinRuc($actual->aseguradoraTexto()));
                }
                [$timbrado, $aviso] = EmitirFactura::timbradoParaEmitir(bloquear: true);
                if ($timbrado === null) {
                    throw new AccionRechazada($aviso);
                }

                $lineas = $actual->lineas()->whereNull('liberada_en')->with('prestacion.procedimiento')->orderBy('fecha_servicio')->orderBy('id')->get();
                if ($lineas->isEmpty()) {
                    throw new AccionRechazada('La liquidación no tiene prestaciones para facturar.');
                }
                if ($lineas->contains(fn (LineaLiquidacion $linea) => $linea->prestacion->detalleFacturaAseguradora()->exists())) {
                    throw new AccionRechazada(self::PRESTACION_FACTURADA);
                }

                $factura = EmitirFactura::registrar($timbrado, $usuario, [
                    'condicion' => Factura::CREDITO, 'liquidacion_id' => $actual->id,
                    'cliente_nombre' => mb_substr((string) $persona->nombre_y_apellido, 0, 200), 'cliente_documento' => 'RUC '.$persona->ruc,
                ], DetalleFactura::ASEGURADORA, $lineas->map(fn (LineaLiquidacion $linea) => [
                    'prestacion' => $linea->prestacion,
                    'monto' => $linea->montoEntero(),
                    'tasa' => (int) ($linea->prestacion->procedimiento?->tasa_iva ?? 10),
                    'descripcion' => self::descripcion($linea),
                ])->all());
                if ($factura->entero('total') !== $actual->totalEntero()) {
                    throw new AccionRechazada('El total de la factura no coincide con el de la liquidación: revise la liquidación.');
                }

                Auditoria::conDetalle("Liquidación facturada: factura {$factura->numero_completo}", fn () => $actual->forceFill(['facturacion' => Liquidacion::FACTURADA])->save());
                // Ya pagada: la factura nace cobrada.
                self::sincronizarCobro($actual);

                return $factura->fresh();
            });
        } catch (QueryException $e) {
            // Otra emisión de la misma liquidación ganó por el índice único parcial.
            if (str_contains($e->getMessage(), 'facturas_una_por_liquidacion') || str_contains($e->getMessage(), 'facturas.liquidacion_id')) {
                throw new AccionRechazada(self::YA_FACTURADA);
            }
            throw $e;
        }
    }

    /**
     * La factura de crédito sigue a la liquidación (ya bloqueada por quien llama): COBRADA cuando la liquidación queda PAGADA y, si deja
     * de estarlo (se anuló un pago), de vuelta a EMITIDA. Solo cambia el estado; cada cambio queda en Auditoría.
     */
    public static function sincronizarCobro(Liquidacion $liquidacion): void
    {
        $factura = Factura::query()->where('liquidacion_id', $liquidacion->id)->whereNull('anulada_en')->lockForUpdate()->first();
        if ($factura === null) {
            return;
        }
        $pagada = Estado::idDe(Estado::PAGADO) === (int) $liquidacion->estado_id;
        $hacia = match (true) {
            $pagada && $factura->tieneEstado(Estado::EMITIDO) => Estado::COBRADO,
            ! $pagada && $factura->tieneEstado(Estado::COBRADO) => Estado::EMITIDO,
            default => null,
        };
        if ($hacia !== null) {
            $factura->forceFill(['estado_id' => Estado::idDe($hacia)])->save();
            $factura->unsetRelation('estado');
        }
    }

    /** "Consulta (06/10/2026, visación N.º V-1)": el procedimiento, con la fecha de servicio y la visación. Sin nada clínico. */
    private static function descripcion(LineaLiquidacion $linea): string
    {
        $visacion = filled($linea->numero_visacion) ? ", visación N.º {$linea->numero_visacion}" : '';

        return "{$linea->procedimiento} (".Fecha::mostrar($linea->fecha_servicio)."{$visacion})";
    }
}
