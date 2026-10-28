<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Estado;
use App\Models\MovimientoCaja;
use App\Models\SolicitudReversionCobro;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * La reversión de un cobro (docs/cobros-caja.md), siempre del cobro COMPLETO y una sola vez. Nada se borra: el cobro queda
 * REVERTIDO con el motivo, quién y cuándo; sus prestaciones vuelven a estar sin cobrar (`cobro_prestaciones.revertido_en`).
 *
 * - Directa (`ejecutar`): el cajero revierte un cobro de SU caja, que sigue ABIERTA (y es la de hoy). Sin aprobación.
 * - Con aprobación: si el cobro es de una caja cerrada, devuelta o aprobada, o de otro cajero, se pide (`solicitar`) con motivo;
 *   Gerencia la aprueba (`aprobar`, y ahí se revierte) o la rechaza con nota (`rechazar`). Nadie revisa una solicitud propia. Una
 *   sola pendiente por cobro (`solicitudes_reversion_una_pendiente`); mientras haya una, el cobro no se revierte directo.
 *
 * Un cobro con factura (docs/facturacion.md) no se revierte por separado: solo al anular su factura (Facturacion\AnularFactura, que
 * usa `porAnulacionDeFactura`, con estas mismas reglas de caja). Los anteriores a la facturación siguen con estas reglas.
 *
 * El dinero devuelto es un EGRESO (MovimientoCaja::REVERSION, en negativo) por cada línea del cobro, con su medio y monto, en la caja
 * abierta de hoy de quien devuelve (el cajero que revierte o el solicitante): lo esperado al cerrar lo descuenta. Si en esa caja no
 * alcanza el efectivo, se rechaza; en los demás medios lo esperado puede quedar negativo. Las cajas cerradas o aprobadas no se tocan nunca. Queda en Auditoría: REVERTIR del
 * cobro (con el motivo) y EDITAR de cada prestación cubierta; CREAR de la solicitud y EDITAR al aprobarla o rechazarla. Los
 * permisos (DESACTIVAR sobre COBROS para revertir y solicitar; EDITAR sobre REVERSIONES_COBRO para revisar) los controla la ruta.
 */
final class RevertirCobro
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public const YA_REVERTIDO = 'Este cobro ya está revertido.';

    public const YA_SOLICITADA = 'Ya hay una solicitud de reversión pendiente para este cobro.';

    public const NECESITA_APROBACION = 'Este cobro es de una caja cerrada o de otro cajero: solicite la reversión.';

    public const ES_DIRECTA = 'Este cobro es de su caja abierta: reviértalo directamente.';

    public const PROPIA = 'No puede aprobar ni rechazar su propia solicitud.';

    public const SIN_CAJA = 'Abra su caja antes de devolver un cobro.';

    public const CON_FACTURA = 'Este cobro tiene factura: se revierte anulando la factura (desde el detalle de la factura).';

    /** Si el usuario puede revertirlo sin aprobación: un cobro vigente de su propia caja, que sigue abierta. */
    public static function esDirecta(User $usuario, Cobro $cobro): bool
    {
        $caja = $cobro->caja;

        return ! $cobro->revertido() && $caja->usuario_id === $usuario->id && $caja->cerrada_en === null && $caja->abierta();
    }

    /**
     * La reversión que pide la anulación de la factura del cobro (Facturacion\AnularFactura), con el cobro y la caja ya bloqueados: el
     * egreso por línea en la caja de quien devuelve, con las mismas reglas (el efectivo tiene que alcanzar). Debe correr dentro de la
     * transacción de la anulación.
     */
    public static function porAnulacionDeFactura(Cobro $cobro, Caja $caja, User $devuelve, string $motivo): Cobro
    {
        $actual = self::bloquearVigente($cobro);

        return Auditoria::conDetalle("Reversión del cobro {$actual->id}", fn () => self::registrar($actual, $caja, $devuelve, mb_substr($motivo, 0, self::MOTIVO_MAXIMO)));
    }

    /** La caja abierta de hoy de quien devuelve, bloqueada (para la anulación de una factura). */
    public static function cajaDeHoy(User $usuario): Caja
    {
        return self::cajaParaDevolver($usuario);
    }

    public static function ejecutar(User $cajero, Cobro $cobro, ?string $motivo): Cobro
    {
        $motivo = self::motivo($motivo);

        return DB::transaction(function () use ($cajero, $cobro, $motivo) {
            $actual = self::bloquearSinFactura($cobro);
            if ($actual->solicitudPendiente()->exists()) {
                throw new AccionRechazada(self::YA_SOLICITADA);
            }
            if (! self::esDirecta($cajero, $actual)) {
                throw new AccionRechazada(self::NECESITA_APROBACION);
            }

            return Auditoria::conDetalle("Reversión del cobro {$actual->id}", fn () => self::registrar($actual, self::cajaParaDevolver($cajero), $cajero, $motivo));
        });
    }

    public static function solicitar(User $usuario, Cobro $cobro, ?string $motivo): SolicitudReversionCobro
    {
        $motivo = self::motivo($motivo);

        try {
            return DB::transaction(function () use ($usuario, $cobro, $motivo) {
                $actual = self::bloquearSinFactura($cobro);
                if (self::esDirecta($usuario, $actual)) {
                    throw new AccionRechazada(self::ES_DIRECTA);
                }
                if ($actual->solicitudPendiente()->exists()) {
                    throw new AccionRechazada(self::YA_SOLICITADA);
                }

                return Auditoria::conDetalle("Solicitud de reversión del cobro {$actual->id}", function () use ($usuario, $actual, $motivo) {
                    $solicitud = new SolicitudReversionCobro(['cobro_id' => $actual->id, 'usuario_id' => $usuario->id, 'solicitada_en' => now(), 'motivo' => $motivo]);
                    $solicitud->estado_id = Estado::idDe(Estado::PENDIENTE);
                    $solicitud->save();

                    return $solicitud;
                });
            });
        } catch (QueryException $e) {
            // Otra solicitud del mismo cobro ganó por el índice único parcial.
            if (str_contains($e->getMessage(), 'solicitudes_reversion_una_pendiente') || str_contains($e->getMessage(), 'solicitudes_reversion_cobro.cobro_id')) {
                throw new AccionRechazada(self::YA_SOLICITADA);
            }
            throw $e;
        }
    }

    /** Aprueba la solicitud y revierte el cobro: el egreso va a la caja abierta de hoy del solicitante (si no tiene, se rechaza). */
    public static function aprobar(User $revisor, SolicitudReversionCobro $solicitud, ?string $nota = null): SolicitudReversionCobro
    {
        $nota = trim((string) $nota);

        return DB::transaction(function () use ($revisor, $solicitud, $nota) {
            // El cobro primero (el mismo orden que la reversión directa y la solicitud).
            $cobro = self::bloquearSinFactura($solicitud->cobro);
            $actual = self::bloquearPendiente($revisor, $solicitud);
            $solicitante = $actual->solicitante;
            $caja = Caja::query()->abiertaDe($solicitante)->lockForUpdate()->first();
            $nombre = $solicitante->persona?->nombre_completo ?? $solicitante->email;
            if ($caja === null || $caja->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d')) {
                throw new AccionRechazada(self::sinCajaDelSolicitante($nombre));
            }

            return Auditoria::conDetalle("Reversión del cobro {$cobro->id}", function () use ($revisor, $actual, $cobro, $caja, $solicitante, $nota) {
                self::registrar($cobro, $caja, $solicitante, $actual->motivo);

                return self::revisar($revisor, $actual, Estado::APROBADO, $nota);
            });
        });
    }

    public static function rechazar(User $revisor, SolicitudReversionCobro $solicitud, ?string $nota): SolicitudReversionCobro
    {
        $nota = trim((string) $nota);
        if (mb_strlen($nota) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('Indique por qué se rechaza (al menos '.self::MOTIVO_MINIMO.' caracteres).');
        }

        return DB::transaction(function () use ($revisor, $solicitud, $nota) {
            $actual = self::bloquearPendiente($revisor, $solicitud);

            return self::revisar($revisor, $actual, Estado::RECHAZADO, $nota);
        });
    }

    public static function sinCajaDelSolicitante(string $nombre): string
    {
        return "{$nombre} no tiene abierta la caja de hoy: la reversión se puede aprobar cuando la abra (el dinero se devuelve desde su caja).";
    }

    private static function motivo(?string $motivo): string
    {
        $motivo = trim((string) $motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('Indique el motivo de la reversión (al menos '.self::MOTIVO_MINIMO.' caracteres).');
        }

        return mb_substr($motivo, 0, self::MOTIVO_MAXIMO);
    }

    private static function bloquearVigente(Cobro $cobro): Cobro
    {
        $actual = Cobro::query()->whereKey($cobro->getKey())->lockForUpdate()->firstOrFail();
        if ($actual->revertido()) {
            throw new AccionRechazada(self::YA_REVERTIDO);
        }

        return $actual;
    }

    /** El cobro vigente y sin factura (uno con factura solo se revierte anulándola). */
    private static function bloquearSinFactura(Cobro $cobro): Cobro
    {
        $actual = self::bloquearVigente($cobro);
        if ($actual->factura_id !== null) {
            throw new AccionRechazada(self::CON_FACTURA);
        }

        return $actual;
    }

    private static function bloquearPendiente(User $revisor, SolicitudReversionCobro $solicitud): SolicitudReversionCobro
    {
        $actual = SolicitudReversionCobro::query()->whereKey($solicitud->getKey())->lockForUpdate()->firstOrFail();
        if (! $actual->pendiente()) {
            throw new AccionRechazada('Esta solicitud ya fue revisada.');
        }
        if ($actual->usuario_id === $revisor->id) {
            throw new AccionRechazada(self::PROPIA);
        }

        return $actual;
    }

    /** La caja abierta de hoy de quien devuelve, bloqueada. */
    private static function cajaParaDevolver(User $usuario): Caja
    {
        $caja = Caja::query()->abiertaDe($usuario)->lockForUpdate()->first();
        if ($caja === null) {
            throw new AccionRechazada(self::SIN_CAJA);
        }
        if ($caja->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d')) {
            throw new AccionRechazada('Su caja está abierta desde el '.Fecha::mostrar($caja->fecha).': ciérrela y abra la de hoy para devolver el cobro.');
        }

        return $caja;
    }

    /** La reversión: el cobro REVERTIDO, sus prestaciones sin cobrar y un egreso por línea en la caja de quien devuelve. */
    private static function registrar(Cobro $cobro, Caja $caja, User $devuelve, string $motivo): Cobro
    {
        $lineas = $cobro->lineas()->with('medioPago')->orderBy('id')->get();
        $esperado = $caja->esperadoPorMedio();
        // Solo el efectivo sale del cajón: tiene que alcanzar. En los demás medios lo esperado puede quedar negativo.
        foreach ($lineas->filter(fn ($linea) => $linea->medioPago->esEfectivo())->groupBy('medio_pago_id') as $medioId => $delMedio) {
            $devolver = $delMedio->sum(fn ($linea) => (int) round((float) $linea->monto));
            if (($esperado[$medioId] ?? 0) < $devolver) {
                $moneda = $caja->moneda;
                throw new AccionRechazada('No alcanza el efectivo de la caja para devolver '.$moneda->formatear($devolver)
                    .' (hay '.$moneda->formatear($esperado[$medioId] ?? 0).').');
            }
        }

        $ahora = now();
        $cobro->forceFill([
            'estado_id' => Estado::idDe(Estado::REVERTIDO),
            'revertido_en' => $ahora,
            'revertido_por' => $devuelve->id,
            'motivo_reversion' => $motivo,
            'caja_reversion_id' => $caja->id,
        ])->save();
        $cobro->unsetRelation('estado');
        foreach ($cobro->prestaciones()->whereNull('revertido_en')->get() as $cubierta) {
            $cubierta->forceFill(['revertido_en' => $ahora])->save();
        }
        foreach ($lineas as $linea) {
            MovimientoCaja::create([
                'caja_id' => $caja->id, 'tipo' => MovimientoCaja::REVERSION, 'medio_pago_id' => $linea->medio_pago_id, 'monto' => -1 * (int) round((float) $linea->monto),
                'cobro_id' => $cobro->id, 'linea_cobro_id' => $linea->id, 'usuario_id' => $devuelve->id, 'registrado_en' => $ahora,
            ]);
        }

        return $cobro;
    }

    private static function revisar(User $revisor, SolicitudReversionCobro $solicitud, string $estado, string $nota): SolicitudReversionCobro
    {
        $solicitud->forceFill([
            'estado_id' => Estado::idDe($estado),
            'revisado_por' => $revisor->id,
            'revisado_en' => now(),
            'nota_revision' => $nota === '' ? null : mb_substr($nota, 0, self::MOTIVO_MAXIMO),
        ])->save();
        $solicitud->unsetRelation('estado');

        return $solicitud;
    }
}
