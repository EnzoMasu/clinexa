<?php

namespace App\Support\Facturacion;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Estado;
use App\Models\Factura;
use App\Models\MotivoAnulacionFactura;
use App\Models\SolicitudAnulacionFactura;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Cobros\RevertirCobro;
use App\Support\Fecha;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La anulación de una factura (docs/facturacion.md). Nada se borra: la factura queda ANULADA con su número (que no se reutiliza), el
 * motivo del catálogo (y un detalle), quién y cuándo; su cobro se revierte (la plata sale de una caja como reversión, con las reglas
 * de Cobros\RevertirCobro) y sus líneas se liberan: las prestaciones vuelven a quedar libres para facturarse y cobrarse.
 *
 * - Directa (`anular`): el cajero anula una factura SUYA, de SU caja abierta (la de hoy), sin documentos vinculados, dentro del plazo
 *   desde la EMISIÓN (`config('clinexa.facturacion.plazo_anulacion_minutos')`, 10). En una sola acción: reversión y anulación.
 * - Con aprobación: pasado el plazo, con la caja cerrada o si es de otro cajero, se pide (`solicitar`); Gerencia (o el Administrador)
 *   la aprueba (`aprobar`: ahí se revierte el cobro, desde la caja abierta de hoy del solicitante, y se anula) o la rechaza con nota.
 *   Nadie revisa una solicitud propia. Una sola pendiente por factura (`solicitudes_anulacion_una_pendiente`).
 *
 * Queda en Auditoría: ANULAR de la factura (con el motivo), EDITAR de cada línea liberada, REVERTIR del cobro; CREAR de la solicitud y
 * EDITAR al aprobarla o rechazarla. Los permisos (DESACTIVAR sobre FACTURAS para anular y solicitar; EDITAR sobre ANULACIONES_FACTURA
 * para revisar) los controla la ruta.
 */
final class AnularFactura
{
    public const DETALLE_MINIMO = 5;

    public const DETALLE_MAXIMO = 300;

    public const YA_ANULADA = 'Esta factura ya está anulada.';

    public const YA_SOLICITADA = 'Ya hay una solicitud de anulación pendiente para esta factura.';

    public const PROPIA = 'No puede aprobar ni rechazar su propia solicitud.';

    public const ES_DIRECTA = 'Esta factura es suya, de su caja abierta y está dentro del plazo: anúlela directamente.';

    public const CON_DOCUMENTOS = 'Esta factura tiene documentos vinculados: no se puede anular.';

    public static function plazoMinutos(): int
    {
        return max(0, (int) config('clinexa.facturacion.plazo_anulacion_minutos', 10));
    }

    public static function necesitaSolicitud(): string
    {
        return 'Pasaron más de '.self::plazoMinutos().' minutos desde la emisión, su caja está cerrada o la factura es de otro cajero: solicite la anulación.';
    }

    /** Hasta cuándo se puede anular directamente (desde la emisión). */
    public static function limite(Factura $factura): Carbon
    {
        return $factura->emitida_en->copy()->addMinutes(self::plazoMinutos());
    }

    public static function dentroDelPlazo(Factura $factura): bool
    {
        return now()->lte(self::limite($factura));
    }

    /** Si el usuario la anula sin aprobación: suya, de su caja abierta de hoy, en el plazo y sin documentos vinculados. */
    public static function esDirecta(User $usuario, Factura $factura): bool
    {
        $caja = $factura->caja;

        return ! $factura->anulada() && (int) $factura->usuario_id === (int) $usuario->id && (int) $caja->usuario_id === (int) $usuario->id
            && $caja->cerrada_en === null && $caja->abierta() && $caja->fecha->format('Y-m-d') === Fecha::hoy()->format('Y-m-d')
            && self::dentroDelPlazo($factura) && ! $factura->tieneDocumentosVinculados();
    }

    public static function anular(User $cajero, Factura $factura, ?int $motivoId, ?string $detalle): Factura
    {
        [$motivo, $detalle] = self::motivo($motivoId, $detalle);

        return DB::transaction(function () use ($cajero, $factura, $motivo, $detalle) {
            $actual = self::bloquearNoAnulada($factura);
            if ($actual->solicitudPendiente()->exists()) {
                throw new AccionRechazada(self::YA_SOLICITADA);
            }
            if ($actual->tieneDocumentosVinculados()) {
                throw new AccionRechazada(self::CON_DOCUMENTOS);
            }
            if (! self::esDirecta($cajero, $actual)) {
                throw new AccionRechazada(self::necesitaSolicitud());
            }

            return self::ejecutar($actual, RevertirCobro::cajaDeHoy($cajero), $cajero, $cajero, $motivo, $detalle);
        });
    }

    public static function solicitar(User $usuario, Factura $factura, ?int $motivoId, ?string $detalle): SolicitudAnulacionFactura
    {
        [$motivo, $detalle] = self::motivo($motivoId, $detalle);

        try {
            return DB::transaction(function () use ($usuario, $factura, $motivo, $detalle) {
                $actual = self::bloquearNoAnulada($factura);
                if ($actual->tieneDocumentosVinculados()) {
                    throw new AccionRechazada(self::CON_DOCUMENTOS);
                }
                if (self::esDirecta($usuario, $actual)) {
                    throw new AccionRechazada(self::ES_DIRECTA);
                }
                if ($actual->solicitudPendiente()->exists()) {
                    throw new AccionRechazada(self::YA_SOLICITADA);
                }

                return Auditoria::conDetalle("Solicitud de anulación de la factura {$actual->numero_completo}", function () use ($usuario, $actual, $motivo, $detalle) {
                    $solicitud = new SolicitudAnulacionFactura(['factura_id' => $actual->id, 'usuario_id' => $usuario->id, 'solicitada_en' => now(),
                        'motivo_anulacion_id' => $motivo->id, 'detalle' => $detalle]);
                    $solicitud->estado_id = Estado::idDe(Estado::PENDIENTE);
                    $solicitud->save();

                    return $solicitud;
                });
            });
        } catch (QueryException $e) {
            // Otra solicitud de la misma factura ganó por el índice único parcial.
            if (str_contains($e->getMessage(), 'solicitudes_anulacion_una_pendiente') || str_contains($e->getMessage(), 'solicitudes_anulacion_factura.factura_id')) {
                throw new AccionRechazada(self::YA_SOLICITADA);
            }
            throw $e;
        }
    }

    /** Aprueba la solicitud: revierte el cobro (el egreso, en la caja abierta de hoy del solicitante) y anula la factura. */
    public static function aprobar(User $revisor, SolicitudAnulacionFactura $solicitud, ?string $nota = null): SolicitudAnulacionFactura
    {
        $nota = trim((string) $nota);

        return DB::transaction(function () use ($revisor, $solicitud, $nota) {
            // La factura primero (el mismo orden que la anulación directa y la solicitud).
            $factura = self::bloquearNoAnulada($solicitud->factura);
            $actual = self::bloquearPendiente($revisor, $solicitud);
            $solicitante = $actual->solicitante;
            $caja = null;
            if ($factura->cobro !== null && ! $factura->cobro->revertido()) {
                $caja = Caja::query()->abiertaDe($solicitante)->lockForUpdate()->first();
                if ($caja === null || $caja->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d')) {
                    throw new AccionRechazada(self::sinCajaDelSolicitante($solicitante->persona?->nombre_completo ?? $solicitante->email));
                }
            }
            self::ejecutar($factura, $caja, $solicitante, $revisor, $actual->motivo, $actual->detalle);

            return self::revisar($revisor, $actual, Estado::APROBADO, $nota);
        });
    }

    public static function rechazar(User $revisor, SolicitudAnulacionFactura $solicitud, ?string $nota): SolicitudAnulacionFactura
    {
        $nota = trim((string) $nota);
        if (mb_strlen($nota) < self::DETALLE_MINIMO) {
            throw new AccionRechazada('Indique por qué se rechaza (al menos '.self::DETALLE_MINIMO.' caracteres).');
        }

        return DB::transaction(fn () => self::revisar($revisor, self::bloquearPendiente($revisor, $solicitud), Estado::RECHAZADO, $nota));
    }

    public static function sinCajaDelSolicitante(string $nombre): string
    {
        return "{$nombre} no tiene abierta la caja de hoy: la anulación se puede aprobar cuando la abra (el dinero se devuelve desde su caja).";
    }

    /**
     * El motivo activo del catálogo y el detalle (obligatorio si el motivo lo pide: "Otro (detallar)").
     *
     * @return array{0: MotivoAnulacionFactura, 1: ?string}
     */
    private static function motivo(?int $motivoId, ?string $detalle): array
    {
        $motivo = $motivoId ? MotivoAnulacionFactura::find($motivoId) : null;
        if ($motivo === null || ! $motivo->estaActivo()) {
            throw new AccionRechazada('Elija el motivo de la anulación.');
        }
        $detalle = trim((string) $detalle);
        if ($motivo->requiere_detalle && mb_strlen($detalle) < self::DETALLE_MINIMO) {
            throw new AccionRechazada('Detalle el motivo de la anulación (al menos '.self::DETALLE_MINIMO.' caracteres).');
        }

        return [$motivo, $detalle === '' ? null : mb_substr($detalle, 0, self::DETALLE_MAXIMO)];
    }

    private static function bloquearNoAnulada(Factura $factura): Factura
    {
        $actual = Factura::query()->whereKey($factura->getKey())->lockForUpdate()->firstOrFail();
        if ($actual->anulada()) {
            throw new AccionRechazada(self::YA_ANULADA);
        }

        return $actual;
    }

    private static function bloquearPendiente(User $revisor, SolicitudAnulacionFactura $solicitud): SolicitudAnulacionFactura
    {
        $actual = SolicitudAnulacionFactura::query()->whereKey($solicitud->getKey())->lockForUpdate()->firstOrFail();
        if (! $actual->pendiente()) {
            throw new AccionRechazada('Esta solicitud ya fue revisada.');
        }
        if ((int) $actual->usuario_id === (int) $revisor->id) {
            throw new AccionRechazada(self::PROPIA);
        }

        return $actual;
    }

    /** La reversión del cobro (si sigue vigente) y la anulación, con las líneas liberadas. */
    private static function ejecutar(Factura $factura, ?Caja $caja, User $devuelve, User $anula, MotivoAnulacionFactura $motivo, ?string $detalle): Factura
    {
        $cobro = $factura->cobro;
        if ($cobro !== null && ! $cobro->revertido()) {
            RevertirCobro::porAnulacionDeFactura($cobro, $caja, $devuelve,
                "Anulación de la factura {$factura->numero_completo}. Motivo: {$motivo->nombre}".($detalle ? " ({$detalle})" : ''));
        }

        $ahora = now();
        $factura->forceFill([
            'estado_id' => Estado::idDe(Estado::ANULADO),
            'anulada_en' => $ahora,
            'anulada_por' => $anula->id,
            'motivo_anulacion_id' => $motivo->id,
            'detalle_anulacion' => $detalle,
        ])->save();
        $factura->unsetRelation('estado');
        Auditoria::conDetalle("Liberada por la anulación de la factura {$factura->numero_completo}", function () use ($factura, $ahora) {
            foreach ($factura->detalles()->whereNull('liberada_en')->get() as $linea) {
                $linea->forceFill(['liberada_en' => $ahora])->save();
            }
        });
        app(EmisorDocumentos::class)->anular($factura);

        return $factura;
    }

    private static function revisar(User $revisor, SolicitudAnulacionFactura $solicitud, string $estado, string $nota): SolicitudAnulacionFactura
    {
        $solicitud->forceFill([
            'estado_id' => Estado::idDe($estado),
            'revisado_por' => $revisor->id,
            'revisado_en' => now(),
            'nota_revision' => $nota === '' ? null : mb_substr($nota, 0, self::DETALLE_MAXIMO),
        ])->save();
        $solicitud->unsetRelation('estado');

        return $solicitud;
    }
}
