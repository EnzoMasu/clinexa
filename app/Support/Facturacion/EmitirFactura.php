<?php

namespace App\Support\Facturacion;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Clinica;
use App\Models\DetalleFactura;
use App\Models\Estado;
use App\Models\Factura;
use App\Models\Moneda;
use App\Models\Paciente;
use App\Models\Prestacion;
use App\Models\Sucursal;
use App\Models\Timbrado;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use App\Support\HojaReceta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La emisión de la factura individual CONTADO (docs/facturacion.md). La llama Cobros\Cobrar, en su transacción y ANTES de registrar el
 * cobro: si algo falla después, no queda ni la factura ni el número usado.
 *
 * - Emisor: la clínica (Clinica::actual, con su RUC) y la sucursal principal (un solo punto: el timbrado vigente de esa sucursal).
 * - Timbrado: vigente, dentro de su vigencia y con números disponibles. La numeración avanza de a uno con el timbrado bloqueado: sin
 *   saltos ni repetidos (`facturas_numero_unico`). Un número anulado no se reutiliza.
 * - Cliente: la paciente (nombre en orden natural y documento principal, copiados). Sin factura "sin nombre": sin documento, no se emite.
 * - Una línea por prestación: el procedimiento, cantidad 1 y lo que paga la paciente (particular o copago; lo de la aseguradora nunca
 *   entra: parte PACIENTE), con la tasa del procedimiento copiada. Totales por tasa (Iva). La parte de la paciente se factura una
 *   sola vez (`detalles_factura_una_por_parte`).
 *
 * Queda en Auditoría: CREAR de la factura y de cada línea ("Factura 001-001-0000001"). El permiso (CREAR sobre FACTURAS) lo controla la
 * ruta del cobro.
 */
final class EmitirFactura
{
    /** Desde el hito, cada cobro nuevo se registra contra una factura; los anteriores no tienen y nunca se facturan. */
    public const HITO = 'FACTURACION';

    public const SIN_TIMBRADO = 'No hay un timbrado vigente para emitir la factura: Gerencia o el Administrador lo cargan en Facturas → Timbrados.';

    public const VARIOS_PUNTOS = 'La sucursal tiene más de un punto de expedición vigente: deje uno solo vigente (Facturas → Timbrados) para emitir.';

    public const SIN_DOCUMENTO = 'La paciente no tiene documento (CI, pasaporte o RUC): cárguelo en su ficha antes de facturar.';

    public const YA_FACTURADA = 'Una de las prestaciones ya está facturada.';

    /** Si los cobros nuevos se facturan: desde el hito FACTURACION (lo pone la migración). */
    public static function activa(): bool
    {
        $hito = DB::table('hitos_sistema')->where('codigo', self::HITO)->value('fecha_hora');

        return $hito !== null && now()->gte(Carbon::parse($hito, 'UTC'));
    }

    /**
     * El timbrado con el que se emitiría ahora, o el aviso de por qué no se puede (sin bloquear: para avisar en la pantalla).
     *
     * @return array{0: ?Timbrado, 1: ?string}
     */
    public static function timbradoParaEmitir(bool $bloquear = false): array
    {
        if (($falta = Clinica::faltaParaEmitir($clinica = Clinica::actual())) !== null) {
            return [null, $falta];
        }
        $sucursal = Sucursal::principal();
        if ($sucursal === null) {
            return [null, 'No hay una sucursal activa para emitir la factura.'];
        }
        $timbrados = Timbrado::query()->vigentes()->where('sucursal_id', $sucursal->id)->where('clinica_id', $clinica->id)
            ->orderBy('id')->when($bloquear, fn ($query) => $query->lockForUpdate())->get();
        if ($timbrados->isEmpty()) {
            return [null, self::SIN_TIMBRADO];
        }
        if ($timbrados->count() > 1) {
            return [null, self::VARIOS_PUNTOS];
        }
        $timbrado = $timbrados->first();
        $hoy = Fecha::hoy();

        return match (true) {
            $hoy->format('Y-m-d') < $timbrado->vigente_desde->format('Y-m-d') => [null, "El timbrado N.º {$timbrado->numero} rige desde el ".Fecha::mostrar($timbrado->vigente_desde).': todavía no se puede emitir con él.'],
            $hoy->format('Y-m-d') > $timbrado->vigente_hasta->format('Y-m-d') => [null, "El timbrado N.º {$timbrado->numero} venció el ".Fecha::mostrar($timbrado->vigente_hasta).': Gerencia o el Administrador cargan el nuevo en Facturas → Timbrados.'],
            $timbrado->agotado() => [null, "El timbrado N.º {$timbrado->numero} llegó a su último número ({$timbrado->numero_final}): Gerencia o el Administrador cargan el nuevo en Facturas → Timbrados."],
            default => [$timbrado, null],
        };
    }

    /**
     * Emite la factura de las prestaciones (ya bloqueadas y verificadas por el cobro). Debe correr dentro de una transacción.
     *
     * @param  list<Prestacion>  $prestaciones
     */
    public static function contado(User $cajero, Caja $caja, int $pacienteId, array $prestaciones, ?Factura $reemplazaA = null): Factura
    {
        [$timbrado, $aviso] = self::timbradoParaEmitir(bloquear: true);
        if ($timbrado === null) {
            throw new AccionRechazada($aviso);
        }
        $clinica = $timbrado->clinica;
        $sucursal = $timbrado->sucursal;
        $persona = Paciente::with('persona.tipoDocumento')->findOrFail($pacienteId)->persona;
        $documento = $persona->documentoPrincipal();
        if ($documento === '') {
            throw new AccionRechazada(self::SIN_DOCUMENTO);
        }
        foreach ($prestaciones as $prestacion) {
            if ($prestacion->detalleFactura()->exists()) {
                throw new AccionRechazada(self::YA_FACTURADA);
            }
        }
        if ($reemplazaA !== null) {
            self::verificarReemplazo($reemplazaA, $pacienteId);
        }

        // El número siguiente, con el timbrado bloqueado. Sin evento propio en Auditoría: queda en la factura.
        $numero = $timbrado->numero_actual + 1;
        $avanzo = DB::table('timbrados')->where('id', $timbrado->id)->where('numero_actual', $timbrado->numero_actual)
            ->update(['numero_actual' => $numero, 'updated_at' => now()]);
        if ($avanzo !== 1) {
            throw new AccionRechazada('Otra factura tomó el mismo número: vuelva a intentar.');
        }

        $lineas = array_map(function (Prestacion $prestacion) {
            $prestacion->loadMissing('procedimiento');
            $monto = $prestacion->montoPaciente();
            $tasa = (int) ($prestacion->procedimiento?->tasa_iva ?? 10);

            return ['prestacion' => $prestacion, 'monto' => $monto, 'tasa' => $tasa, ...Iva::desglose($monto, $tasa)];
        }, $prestaciones);
        $totales = Iva::totales($lineas);
        $emisor = app(EmisorDocumentos::class);
        $numeroCompleto = Timbrado::numeroCompleto($timbrado->establecimiento, $timbrado->punto_expedicion, $numero);

        return Auditoria::conDetalle("Factura {$numeroCompleto}", function () use ($cajero, $caja, $pacienteId, $timbrado, $clinica, $sucursal, $persona, $documento, $numero, $numeroCompleto, $lineas, $totales, $emisor, $reemplazaA) {
            $factura = new Factura([
                'timbrado_id' => $timbrado->id, 'sucursal_id' => $sucursal->id, 'numero_timbrado' => $timbrado->numero,
                'timbrado_desde' => $timbrado->vigente_desde, 'timbrado_hasta' => $timbrado->vigente_hasta,
                'establecimiento' => $timbrado->establecimiento, 'punto_expedicion' => $timbrado->punto_expedicion,
                'numero' => $numero, 'numero_completo' => $numeroCompleto, 'emitida_en' => now(), 'condicion' => Factura::CONTADO,
                'emisor_razon_social' => $clinica->razon_social, 'emisor_nombre_fantasia' => $clinica->nombre_fantasia, 'emisor_ruc' => $clinica->ruc,
                'emisor_actividad' => $clinica->actividad_economica, 'emisor_direccion' => $clinica->direccion_fiscal,
                'emisor_telefono' => $sucursal->telefono ?: $clinica->telefono,
                'paciente_id' => $pacienteId, 'cliente_nombre' => mb_substr(HojaReceta::nombreNatural($persona), 0, 200), 'cliente_documento' => $documento,
                'moneda_id' => Moneda::guarani()->id, ...$totales,
                'usuario_id' => $cajero->id, 'caja_id' => $caja->id, 'simulada' => $emisor->simulado(), 'emisor_documentos' => $emisor->codigo(),
                'reemplaza_a_id' => $reemplazaA?->id,
            ]);
            $factura->estado_id = Estado::idDe(Estado::EMITIDO);
            $factura->save();
            foreach ($lineas as $linea) {
                DetalleFactura::create([
                    'factura_id' => $factura->id, 'prestacion_id' => $linea['prestacion']->id, 'parte' => DetalleFactura::PACIENTE,
                    'descripcion' => mb_substr($linea['prestacion']->procedimiento?->nombre ?? 'Prestación', 0, 150),
                    'cantidad' => 1, 'precio_unitario' => $linea['monto'], 'monto' => $linea['monto'], 'tasa_iva' => $linea['tasa'],
                    'gravado' => $linea['gravado'], 'iva' => $linea['iva'],
                ]);
            }
            $emisor->emitir($factura);

            return $factura;
        });
    }

    /** La que se reemplaza: anulada, de la misma paciente y todavía sin reemplazo. */
    private static function verificarReemplazo(Factura $anterior, int $pacienteId): void
    {
        $anterior = Factura::query()->whereKey($anterior->getKey())->lockForUpdate()->firstOrFail();
        if (! $anterior->anulada() || (int) $anterior->paciente_id !== $pacienteId) {
            throw new AccionRechazada('Solo se reemplaza una factura anulada de la misma paciente.');
        }
        if ($anterior->reemplazadaPor()->exists()) {
            throw new AccionRechazada("La factura {$anterior->numero_completo} ya tiene otra que la reemplaza.");
        }
    }
}
