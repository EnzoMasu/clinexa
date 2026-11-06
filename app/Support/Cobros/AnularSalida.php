<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Estado;
use App\Models\MovimientoCaja;
use App\Models\SalidaCaja;
use App\Models\SolicitudReversionCobro;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La anulación de una salida de caja (docs/cobros-caja.md), con el esquema de la anulación de facturas. Nada se borra: la salida queda
 * ANULADA con el motivo, quién y cuándo, y su egreso se compensa con un reingreso (MovimientoCaja::ANULACION_SALIDA, en positivo) en
 * la MISMA caja. Solo mientras esa caja sigue ABIERTA y es la de hoy: con la caja pendiente de recepción, devuelta o recibida, la
 * salida ya no se anula. No hay edición: se corrige anulando y registrando otra.
 *
 * - Directa (`anular`): quien la registró, en su caja abierta, dentro del plazo desde el registro
 *   (`config('clinexa.caja.plazo_anulacion_salida_minutos')`, 10), con motivo.
 * - Con aprobación: pasado el plazo, quien la registró la pide (`solicitar`, con motivo) con la misma solicitud de las reversiones de
 *   cobro (SolicitudReversionCobro con `salida_caja_id`), y Gerencia la aprueba (`aprobar`: ahí se anula) o la rechaza con nota
 *   (`rechazar`), en la misma pestaña. Nadie revisa una solicitud propia. Una sola pendiente por salida.
 *
 * Queda en Auditoría: ANULAR de la salida (con el motivo); CREAR de la solicitud y EDITAR al aprobarla o rechazarla. Los permisos
 * (DESACTIVAR sobre SALIDAS_CAJA para anular y solicitar; EDITAR sobre REVERSIONES_COBRO para revisar) los controla la ruta.
 */
final class AnularSalida
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public const YA_ANULADA = 'Esta salida ya está anulada.';

    public const YA_SOLICITADA = 'Ya hay una solicitud de anulación pendiente para esta salida.';

    public const PROPIA = 'No puede aprobar ni rechazar su propia solicitud.';

    public const ES_DIRECTA = 'Esta salida es suya y está dentro del plazo: anúlela directamente.';

    public const AJENA = 'Solo quien registró la salida puede anularla o solicitar su anulación.';

    public const CAJA_CERRADA = 'La caja de esta salida ya no está abierta (o no es la de hoy): la salida ya no se anula.';

    public static function plazoMinutos(): int
    {
        return max(0, (int) config('clinexa.caja.plazo_anulacion_salida_minutos', 10));
    }

    public static function necesitaSolicitud(): string
    {
        return 'Pasaron más de '.self::plazoMinutos().' minutos desde el registro: solicite la anulación a Gerencia.';
    }

    /** Hasta cuándo se puede anular directamente (desde el registro). */
    public static function limite(SalidaCaja $salida): Carbon
    {
        return $salida->registrada_en->copy()->addMinutes(self::plazoMinutos());
    }

    public static function dentroDelPlazo(SalidaCaja $salida): bool
    {
        return now()->lte(self::limite($salida));
    }

    /** Si la caja de la salida sigue abierta y es la de hoy (la única en que se anula). */
    public static function cajaAbiertaDeHoy(Caja $caja): bool
    {
        return $caja->cerrada_en === null && $caja->abierta() && $caja->fecha->format('Y-m-d') === Fecha::hoy()->format('Y-m-d');
    }

    /** Si el usuario la anula sin aprobación: vigente, registrada por él, en su caja abierta de hoy y dentro del plazo. */
    public static function esDirecta(User $usuario, SalidaCaja $salida): bool
    {
        return ! $salida->anulada() && (int) $salida->usuario_id === (int) $usuario->id && (int) $salida->caja->usuario_id === (int) $usuario->id
            && self::cajaAbiertaDeHoy($salida->caja) && self::dentroDelPlazo($salida);
    }

    /** Si el usuario puede pedir la anulación: vigente, suya, con la caja abierta de hoy, fuera del plazo y sin otra pendiente. */
    public static function puedeSolicitar(User $usuario, SalidaCaja $salida): bool
    {
        return ! $salida->anulada() && (int) $salida->usuario_id === (int) $usuario->id && self::cajaAbiertaDeHoy($salida->caja)
            && ! self::dentroDelPlazo($salida) && ! $salida->solicitudPendiente()->exists();
    }

    public static function anular(User $usuario, SalidaCaja $salida, ?string $motivo): SalidaCaja
    {
        $motivo = self::motivo($motivo);

        return DB::transaction(function () use ($usuario, $salida, $motivo) {
            $actual = self::bloquearVigente($salida);
            if ((int) $actual->usuario_id !== (int) $usuario->id) {
                throw new AccionRechazada(self::AJENA);
            }
            if ($actual->solicitudPendiente()->exists()) {
                throw new AccionRechazada(self::YA_SOLICITADA);
            }
            $caja = self::bloquearCaja($actual);
            if (! self::dentroDelPlazo($actual)) {
                throw new AccionRechazada(self::necesitaSolicitud());
            }

            return self::registrar($actual, $caja, $usuario, $motivo);
        });
    }

    public static function solicitar(User $usuario, SalidaCaja $salida, ?string $motivo): SolicitudReversionCobro
    {
        $motivo = self::motivo($motivo);

        try {
            return DB::transaction(function () use ($usuario, $salida, $motivo) {
                $actual = self::bloquearVigente($salida);
                if ((int) $actual->usuario_id !== (int) $usuario->id) {
                    throw new AccionRechazada(self::AJENA);
                }
                self::bloquearCaja($actual);
                if (self::dentroDelPlazo($actual)) {
                    throw new AccionRechazada(self::ES_DIRECTA);
                }
                if ($actual->solicitudPendiente()->exists()) {
                    throw new AccionRechazada(self::YA_SOLICITADA);
                }

                return Auditoria::conDetalle("Solicitud de anulación de la salida de caja {$actual->id}", function () use ($usuario, $actual, $motivo) {
                    $solicitud = new SolicitudReversionCobro(['salida_caja_id' => $actual->id, 'usuario_id' => $usuario->id, 'solicitada_en' => now(), 'motivo' => $motivo]);
                    $solicitud->estado_id = Estado::idDe(Estado::PENDIENTE);
                    $solicitud->save();

                    return $solicitud;
                });
            });
        } catch (QueryException $e) {
            // Otra solicitud de la misma salida ganó por el índice único parcial.
            if (str_contains($e->getMessage(), 'solicitudes_anulacion_salida_una_pendiente') || str_contains($e->getMessage(), 'solicitudes_reversion_cobro.salida_caja_id')) {
                throw new AccionRechazada(self::YA_SOLICITADA);
            }
            throw $e;
        }
    }

    /** Aprueba la solicitud y anula la salida: el reingreso va a la misma caja, que tiene que seguir abierta y ser la de hoy. */
    public static function aprobar(User $revisor, SolicitudReversionCobro $solicitud, ?string $nota = null): SolicitudReversionCobro
    {
        $nota = trim((string) $nota);

        return DB::transaction(function () use ($revisor, $solicitud, $nota) {
            // La salida primero (el mismo orden que la anulación directa y la solicitud).
            $salida = self::bloquearVigente(self::salidaDe($solicitud));
            $actual = self::bloquearPendiente($revisor, $solicitud);
            $caja = self::bloquearCaja($salida, 'La caja de esta salida ya no está abierta (o no es la de hoy): la anulación no se puede aprobar; rechace la solicitud.');

            return Auditoria::conDetalle("Anulación de la salida de caja {$salida->id}", function () use ($revisor, $actual, $salida, $caja, $nota) {
                self::registrar($salida, $caja, $revisor, $actual->motivo);

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
        self::salidaDe($solicitud);

        return DB::transaction(function () use ($revisor, $solicitud, $nota) {
            $actual = self::bloquearPendiente($revisor, $solicitud);

            return self::revisar($revisor, $actual, Estado::RECHAZADO, $nota);
        });
    }

    private static function salidaDe(SolicitudReversionCobro $solicitud): SalidaCaja
    {
        if (! $solicitud->esDeSalida()) {
            throw new \LogicException('La solicitud no es de una salida de caja.');
        }

        return $solicitud->salida;
    }

    private static function motivo(?string $motivo): string
    {
        $motivo = trim((string) $motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('Indique el motivo de la anulación (al menos '.self::MOTIVO_MINIMO.' caracteres).');
        }

        return mb_substr($motivo, 0, self::MOTIVO_MAXIMO);
    }

    private static function bloquearVigente(SalidaCaja $salida): SalidaCaja
    {
        $actual = SalidaCaja::query()->whereKey($salida->getKey())->lockForUpdate()->firstOrFail();
        if ($actual->anulada()) {
            throw new AccionRechazada(self::YA_ANULADA);
        }

        return $actual;
    }

    /** La caja de la salida, bloqueada; tiene que seguir abierta y ser la de hoy. */
    private static function bloquearCaja(SalidaCaja $salida, string $mensaje = self::CAJA_CERRADA): Caja
    {
        $caja = Caja::query()->whereKey($salida->caja_id)->lockForUpdate()->firstOrFail();
        if (! self::cajaAbiertaDeHoy($caja)) {
            throw new AccionRechazada($mensaje);
        }

        return $caja;
    }

    private static function bloquearPendiente(User $revisor, SolicitudReversionCobro $solicitud): SolicitudReversionCobro
    {
        $actual = SolicitudReversionCobro::query()->whereKey($solicitud->getKey())->lockForUpdate()->firstOrFail();
        if (! $actual->pendiente()) {
            throw new AccionRechazada('Esta solicitud ya fue revisada.');
        }
        if ((int) $actual->usuario_id === (int) $revisor->id) {
            throw new AccionRechazada(self::PROPIA);
        }

        return $actual;
    }

    /** La anulación: la salida ANULADA y el reingreso que compensa su egreso, en la misma caja. */
    private static function registrar(SalidaCaja $salida, Caja $caja, User $anula, string $motivo): SalidaCaja
    {
        $ahora = now();
        $salida->forceFill([
            'estado_id' => Estado::idDe(Estado::ANULADO),
            'anulada_en' => $ahora,
            'anulada_por' => $anula->id,
            'motivo_anulacion' => $motivo,
        ])->save();
        $salida->unsetRelation('estado');

        MovimientoCaja::create([
            'caja_id' => $caja->id, 'tipo' => MovimientoCaja::ANULACION_SALIDA, 'medio_pago_id' => $salida->medio_pago_id, 'monto' => (int) $salida->monto,
            'salida_caja_id' => $salida->id, 'usuario_id' => $anula->id, 'registrado_en' => $ahora,
        ]);

        return $salida;
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
