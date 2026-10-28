<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\CobroPrestacion;
use App\Models\Estado;
use App\Models\Factura;
use App\Models\LineaCobro;
use App\Models\MedioPago;
use App\Models\Moneda;
use App\Models\MovimientoCaja;
use App\Models\Prestacion;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Facturacion\EmitirFactura;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * El cobro de una o varias prestaciones de una paciente (docs/cobros-caja.md), siempre completo:
 *
 * - En la caja ABIERTA del cajero, del día de hoy (una de un día anterior se cierra primero).
 * - Prestaciones de la misma paciente, con monto (tarifadas), no anuladas, sin cobro vigente y con algo a cargo de la paciente. El
 *   reparto no importa. Con aseguradora (docs/convenios-seguros.md) se cobra SOLO la parte de la paciente; una con su parte en cero
 *   no se ofrece ("A cargo de aseguradora").
 * - Total = la suma exacta de lo que paga la paciente en cada una; la suma de las líneas de pago (medio activo, monto, referencia opcional) = el total. Sin
 *   pagos parciales, saldos ni vuelto. Cada línea es un movimiento de la caja.
 *
 * Desde la facturación (docs/facturacion.md, hito FACTURACION) la factura va PRIMERO: en la misma transacción se emite la factura
 * individual CONTADO de esas prestaciones (Facturacion\EmitirFactura, con su número) y después se registra el cobro contra ella, que
 * la deja COBRADA. Si algo falla, no queda nada (ni el número usado). Los cobros anteriores al hito no tienen factura.
 *
 * Las prestaciones se bloquean mientras se cobra (también frente a su anulación) y `cobro_prestaciones_un_cobro_vigente` frena un
 * segundo cobro aunque algo se saltee la verificación. Queda en Auditoría como CREAR del cobro, de cada prestación cubierta y de
 * cada línea ("Cobro N"). El permiso (CREAR sobre COBROS) lo controla la ruta.
 */
final class Cobrar
{
    public const SIN_CAJA = 'Abra su caja antes de cobrar.';

    public const YA_COBRADA = 'La prestación ya está cobrada.';

    public const SUMA_NO_CIERRA = 'La suma de los pagos (:pagos) debe ser igual al total de las prestaciones elegidas (:total).';

    public const REFERENCIA_MAXIMA = 100;

    /** Lo que paga la paciente en cada prestación: su parte (en las anteriores a los convenios, el monto). */
    public const PARTE_PACIENTE = 'COALESCE(prestaciones.monto_paciente, prestaciones.monto)';

    public const A_CARGO_DE_ASEGURADORA = 'Esta prestación está a cargo de la aseguradora: no hay nada que cobrarle a la paciente.';

    /** Lo que se puede cobrar: con monto, no anuladas, sin un cobro vigente y con algo a cargo de la paciente. */
    public static function pendientes(): Builder
    {
        return Prestacion::query()
            ->whereNotNull('prestaciones.monto')
            ->whereRaw(self::PARTE_PACIENTE.' > 0')
            ->where('prestaciones.estado_id', '!=', Estado::idDe(Estado::ANULADO))
            ->whereDoesntHave('cobroVigente');
    }

    /**
     * @param  list<int>  $prestacionIds
     * @param  list<array{medio_pago_id: int, monto: int, referencia: ?string}>  $lineas
     */
    public static function ejecutar(User $cajero, int $pacienteId, array $prestacionIds, array $lineas, ?Factura $reemplazaA = null): Cobro
    {
        $prestacionIds = array_values(array_unique(array_map('intval', $prestacionIds)));
        if ($prestacionIds === []) {
            throw new AccionRechazada('Elija al menos una prestación para cobrar.');
        }
        if ($lineas === []) {
            throw new AccionRechazada('Indique al menos un medio de pago.');
        }

        try {
            return DB::transaction(function () use ($cajero, $pacienteId, $prestacionIds, $lineas, $reemplazaA) {
                $caja = self::cajaParaCobrar($cajero);

                // Bloqueadas (en orden, para no cruzarse con otro cobro ni con una anulación).
                $prestaciones = Prestacion::query()->whereKey($prestacionIds)->orderBy('id')->lockForUpdate()->get();
                if ($prestaciones->count() !== count($prestacionIds)) {
                    throw new AccionRechazada('Una de las prestaciones elegidas no existe.');
                }
                foreach ($prestaciones as $prestacion) {
                    self::verificarPrestacion($prestacion, $pacienteId);
                }

                $total = $prestaciones->sum(fn (Prestacion $prestacion) => $prestacion->montoPaciente());
                $medios = MedioPago::query()->whereKey(array_column($lineas, 'medio_pago_id'))->with('estado')->get()->keyBy('id');
                $pagos = 0;
                foreach ($lineas as $linea) {
                    $medio = $medios->get((int) $linea['medio_pago_id']);
                    if ($medio === null || ! $medio->estaActivo()) {
                        throw new AccionRechazada('Uno de los medios de pago elegidos no existe o no está activo.');
                    }
                    if ((int) $linea['monto'] < 1) {
                        throw new AccionRechazada('El monto de cada pago debe ser mayor que cero.');
                    }
                    if (mb_strlen((string) ($linea['referencia'] ?? '')) > self::REFERENCIA_MAXIMA) {
                        throw new AccionRechazada('La referencia no puede tener más de '.self::REFERENCIA_MAXIMA.' caracteres.');
                    }
                    $pagos += (int) $linea['monto'];
                }
                $moneda = Moneda::guarani();
                if ($pagos !== $total) {
                    throw new AccionRechazada(strtr(self::SUMA_NO_CIERRA, [':pagos' => $moneda->formatear($pagos), ':total' => $moneda->formatear($total)]));
                }

                // La factura primero (desde el hito); el cobro, contra ella.
                $factura = EmitirFactura::activa() ? EmitirFactura::contado($cajero, $caja, $pacienteId, $prestaciones->all(), $reemplazaA) : null;
                $cobro = self::registrar($cajero, $caja, $pacienteId, $prestaciones->all(), $lineas, $total, $moneda, $factura);
                if ($factura !== null) {
                    Auditoria::conDetalle("Factura {$factura->numero_completo} cobrada", fn () => $factura->forceFill(['estado_id' => Estado::idDe(Estado::COBRADO)])->save());
                    $factura->unsetRelation('estado');
                }

                return $cobro;
            });
        } catch (QueryException $e) {
            // Otro cobro de la misma prestación ganó por el índice único parcial.
            if (str_contains($e->getMessage(), 'cobro_prestaciones_un_cobro_vigente') || str_contains($e->getMessage(), 'cobro_prestaciones.prestacion_id')) {
                throw new AccionRechazada(self::YA_COBRADA);
            }
            // Otra factura de la misma prestación ganó por el suyo.
            if (str_contains($e->getMessage(), 'detalles_factura_una_por_parte') || str_contains($e->getMessage(), 'detalles_factura.prestacion_id')) {
                throw new AccionRechazada(EmitirFactura::YA_FACTURADA);
            }
            throw $e;
        }
    }

    /** La caja abierta del cajero, bloqueada, si puede cobrar hoy con ella; si no, el motivo. */
    public static function cajaParaCobrar(User $cajero): Caja
    {
        $caja = Caja::query()->abiertaDe($cajero)->lockForUpdate()->first();
        if ($caja === null) {
            throw new AccionRechazada(self::SIN_CAJA);
        }
        if ($caja->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d')) {
            throw new AccionRechazada(self::deOtroDia($caja));
        }

        return $caja;
    }

    public static function deOtroDia(Caja $caja): string
    {
        return 'Su caja está abierta desde el '.Fecha::mostrar($caja->fecha).': ciérrela y abra la de hoy para cobrar.';
    }

    private static function verificarPrestacion(Prestacion $prestacion, int $pacienteId): void
    {
        if ((int) $prestacion->paciente_id !== $pacienteId) {
            throw new AccionRechazada('Las prestaciones elegidas deben ser de la misma paciente.');
        }
        if ($prestacion->anulada()) {
            throw new AccionRechazada('Una de las prestaciones elegidas está anulada.');
        }
        if ($prestacion->monto === null) {
            throw new AccionRechazada('Una de las prestaciones elegidas no está tarifada: tarifela antes de cobrarla.');
        }
        if ($prestacion->montoPaciente() === 0) {
            throw new AccionRechazada(self::A_CARGO_DE_ASEGURADORA);
        }
        if ($prestacion->cobroVigente()->exists()) {
            throw new AccionRechazada(self::YA_COBRADA);
        }
    }

    /** @param  list<Prestacion>  $prestaciones */
    private static function registrar(User $cajero, Caja $caja, int $pacienteId, array $prestaciones, array $lineas, int $total, Moneda $moneda, ?Factura $factura): Cobro
    {
        $ahora = now();
        $cobro = new Cobro([
            'caja_id' => $caja->id, 'paciente_id' => $pacienteId, 'cobrado_en' => $ahora,
            'total' => $total, 'moneda_id' => $moneda->id, 'usuario_id' => $cajero->id,
        ]);
        if ($factura !== null) {
            $cobro->factura_id = $factura->id;
        }
        $cobro->estado_id = Estado::idDe(Estado::VIGENTE);

        return Auditoria::conDetalle('Cobro de '.count($prestaciones).(count($prestaciones) === 1 ? ' prestación' : ' prestaciones'), function () use ($cobro, $cajero, $caja, $prestaciones, $lineas, $ahora) {
            $cobro->save();
            foreach ($prestaciones as $prestacion) {
                CobroPrestacion::create(['cobro_id' => $cobro->id, 'prestacion_id' => $prestacion->id, 'monto' => $prestacion->montoPaciente()]);
            }
            foreach ($lineas as $linea) {
                $referencia = trim((string) ($linea['referencia'] ?? ''));
                $lineaCobro = LineaCobro::create([
                    'cobro_id' => $cobro->id, 'medio_pago_id' => (int) $linea['medio_pago_id'], 'monto' => (int) $linea['monto'],
                    'referencia' => $referencia === '' ? null : $referencia,
                ]);
                MovimientoCaja::create([
                    'caja_id' => $caja->id, 'tipo' => MovimientoCaja::COBRO, 'medio_pago_id' => $lineaCobro->medio_pago_id, 'monto' => $lineaCobro->monto,
                    'cobro_id' => $cobro->id, 'linea_cobro_id' => $lineaCobro->id, 'usuario_id' => $cajero->id, 'registrado_en' => $ahora,
                ]);
            }

            return $cobro;
        });
    }
}
