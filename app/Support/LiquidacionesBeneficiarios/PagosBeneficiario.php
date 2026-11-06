<?php

namespace App\Support\LiquidacionesBeneficiarios;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Estado;
use App\Models\LiquidacionBeneficiario;
use App\Models\MedioPago;
use App\Models\PagoBeneficiario;
use App\Models\SalidaCaja;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Cobros\AnularSalida;
use App\Support\Cobros\RegistrarSalida;
use App\Support\Fecha;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los pagos a un beneficiario sobre su liquidación aprobada con saldo (docs/liquidacion-beneficiarios.md): totales o parciales, en
 * guaraníes enteros, con fecha (no futura), medio activo del catálogo y el comprobante si el medio lo exige. Siempre aparte de la caja
 * de cobros. La suma de los pagos vigentes no supera el total; el saldo se calcula. La liquidación pasa a PARCIAL o PAGADO.
 *
 * - SIN CAJA: solo queda registrado. Lo anula, con motivo, quien lo registró o Gerencia (EDITAR sobre APROBACION_LIQ_BENEFICIARIOS).
 * - EN EFECTIVO DESDE UNA CAJA: la caja abierta de hoy de quien paga (Tesorería abre la suya). Sale como una salida de esa caja, con la
 *   categoría de gasto elegida, y se anula con las reglas de la salida (docs/cobros-caja.md): quien lo registró, dentro del plazo; después,
 *   por solicitud que aprueba Gerencia; con la caja cerrada ya no se anula. Anular la salida (desde aquí o desde la caja) anula el pago.
 *
 * Queda en Auditoría: CREAR del pago (y de la salida) con el detalle, ANULAR con el motivo y el cambio de estado de la liquidación. Los
 * permisos (CREAR y DESACTIVAR sobre PAGOS_BENEFICIARIOS) los controla la ruta.
 */
final class PagosBeneficiario
{
    public const REFERENCIA_MAXIMA = 100;

    public const NO_APROBADA = 'Solo se registran pagos de una liquidación aprobada que tiene saldo a pagar.';

    public const CAJA_SOLO_EFECTIVO = 'Desde una caja solo se paga en efectivo: elija Efectivo o no elija caja.';

    public const CAJA_HOY = 'Un pago desde la caja es de hoy: la salida se registra en la caja abierta de hoy.';

    public const SIN_CATEGORIA = 'Elija la categoría de gasto de la salida de caja.';

    public const AJENO = 'Solo quien registró el pago o Gerencia pueden anularlo.';

    public const CAJA_CERRADA = 'La caja de la que salió este pago ya no está abierta (o no es la de hoy): el pago ya no se anula.';

    public const SOLICITADA = 'Pasó el plazo para anular el pago directamente: se envió la solicitud de anulación de su salida de caja a Gerencia. El pago se anula cuando la aprueben.';

    /**
     * @param  array{monto: int, fecha: Carbon, medio_pago_id: int, referencia: ?string, caja_id?: ?int, categoria_gasto_id?: ?int}  $datos
     */
    public static function registrar(User $usuario, LiquidacionBeneficiario $liquidacion, array $datos): PagoBeneficiario
    {
        $monto = (int) $datos['monto'];
        $fecha = $datos['fecha'];
        $referencia = trim((string) ($datos['referencia'] ?? ''));
        $cajaId = $datos['caja_id'] ?? null;
        if ($monto < 1) {
            throw new AccionRechazada('El monto debe ser mayor que cero.');
        }
        if ($fecha->format('Y-m-d') > Fecha::hoy()->format('Y-m-d')) {
            throw new AccionRechazada('La fecha del pago no puede ser posterior a hoy.');
        }
        $medio = MedioPago::query()->whereKey((int) $datos['medio_pago_id'])->activos()->first() ?? throw new AccionRechazada('Elija un medio de pago activo.');
        if ($medio->exigeComprobante() && $referencia === '') {
            throw new AccionRechazada($medio->mensajeComprobanteFaltante());
        }
        if ($cajaId !== null) {
            if (! $medio->esEfectivo()) {
                throw new AccionRechazada(self::CAJA_SOLO_EFECTIVO);
            }
            if ($fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d')) {
                throw new AccionRechazada(self::CAJA_HOY);
            }
            if (empty($datos['categoria_gasto_id'])) {
                throw new AccionRechazada(self::SIN_CATEGORIA);
            }
        }

        return DB::transaction(function () use ($usuario, $liquidacion, $monto, $fecha, $medio, $referencia, $cajaId, $datos) {
            $actual = LiquidacionBeneficiario::query()->whereKey($liquidacion->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->aprobada() || $actual->saldo() < 1) {
                throw new AccionRechazada(self::NO_APROBADA);
            }
            $saldo = $actual->saldo();
            if ($monto > $saldo) {
                throw new AccionRechazada("El pago supera el saldo de la liquidación ({$actual->formatear($saldo)}).");
            }

            return Auditoria::conDetalle("Pago al beneficiario, liquidación N.º {$actual->id}", function () use ($usuario, $actual, $monto, $fecha, $medio, $referencia, $cajaId, $datos) {
                $salida = null;
                if ($cajaId !== null) {
                    $caja = Caja::query()->whereKey($cajaId)->firstOrFail();
                    $salida = RegistrarSalida::ejecutar($usuario, $caja, [
                        'categoria_gasto_id' => (int) $datos['categoria_gasto_id'],
                        'beneficiario' => $actual->beneficiarioTexto(),
                        'comprobante' => $referencia === '' ? null : $referencia,
                        'concepto' => "Pago de la liquidación a beneficiario N.º {$actual->id}",
                        'monto' => $monto,
                    ]);
                }
                $pago = PagoBeneficiario::create([
                    'liquidacion_beneficiario_id' => $actual->id, 'monto' => $monto, 'fecha' => $fecha->format('Y-m-d'), 'medio_pago_id' => $medio->id,
                    'referencia' => $referencia === '' ? null : mb_substr($referencia, 0, self::REFERENCIA_MAXIMA),
                    'caja_id' => $salida?->caja_id, 'salida_caja_id' => $salida?->id, 'categoria_gasto_id' => $salida?->categoria_gasto_id,
                    'usuario_id' => $usuario->id,
                ]);
                self::recalcular($actual);

                return $pago;
            });
        });
    }

    /** Las cajas desde las que este usuario puede pagar en efectivo: su caja abierta de hoy, si tiene. */
    public static function cajasDisponibles(User $usuario): \Illuminate\Support\Collection
    {
        return Caja::query()->where('usuario_id', $usuario->id)->whereNull('cerrada_en')->whereDate('fecha', Fecha::hoy()->format('Y-m-d'))
            ->with('sucursal')->get()->filter(fn (Caja $caja) => AnularSalida::cajaAbiertaDeHoy($caja))->values();
    }

    /** Si el usuario puede anular el pago (o pedir que se anule su salida): para mostrar el botón. */
    public static function puedeAnular(User $usuario, PagoBeneficiario $pago): bool
    {
        if ($pago->anulado()) {
            return false;
        }
        if (! $pago->conCaja()) {
            return (int) $pago->usuario_id === (int) $usuario->id || $usuario->tienePermiso('APROBACION_LIQ_BENEFICIARIOS', 'EDITAR');
        }
        $salida = $pago->salida;

        return AnularSalida::esDirecta($usuario, $salida) || AnularSalida::puedeSolicitar($usuario, $salida);
    }

    /**
     * Anula el pago. Sin caja, en el acto. Con caja, por la salida: directa dentro del plazo, o envía la solicitud a Gerencia.
     *
     * @return string el mensaje para la pantalla
     */
    public static function anular(User $usuario, PagoBeneficiario $pago, ?string $motivo): string
    {
        $motivo = trim((string) $motivo);
        if (mb_strlen($motivo) < FlujoLiquidacionBeneficiario::MOTIVO_MINIMO) {
            throw new AccionRechazada('Indique el motivo de la anulación (al menos '.FlujoLiquidacionBeneficiario::MOTIVO_MINIMO.' caracteres).');
        }
        $motivo = mb_substr($motivo, 0, FlujoLiquidacionBeneficiario::MOTIVO_MAXIMO);
        if ($pago->anulado()) {
            throw new AccionRechazada('Este pago ya está anulado.');
        }

        if ($pago->conCaja()) {
            $salida = $pago->salida()->with('caja')->firstOrFail();
            if ($salida->anulada()) {
                throw new AccionRechazada('Este pago ya está anulado.');
            }
            if (! AnularSalida::cajaAbiertaDeHoy($salida->caja)) {
                throw new AccionRechazada(self::CAJA_CERRADA);
            }
            if ((int) $salida->usuario_id !== (int) $usuario->id) {
                throw new AccionRechazada('Solo quien registró el pago lo anula (o pide la anulación): salió de su caja.');
            }
            if (AnularSalida::dentroDelPlazo($salida)) {
                AnularSalida::anular($usuario, $salida, $motivo);

                return 'Pago anulado: su salida de caja quedó anulada y el efectivo volvió a la caja.';
            }
            AnularSalida::solicitar($usuario, $salida, $motivo);

            return self::SOLICITADA;
        }

        if ((int) $pago->usuario_id !== (int) $usuario->id && ! $usuario->tienePermiso('APROBACION_LIQ_BENEFICIARIOS', 'EDITAR')) {
            throw new AccionRechazada(self::AJENO);
        }
        DB::transaction(function () use ($usuario, $pago, $motivo) {
            $liquidacion = LiquidacionBeneficiario::query()->whereKey($pago->liquidacion_beneficiario_id)->lockForUpdate()->firstOrFail();
            $actual = PagoBeneficiario::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();
            if ($actual->anulado()) {
                throw new AccionRechazada('Este pago ya está anulado.');
            }
            self::marcarAnulado($actual, $liquidacion, $usuario, $motivo);
        });

        return 'Pago anulado.';
    }

    /**
     * Al anularse una salida de caja (directa o por la solicitud aprobada, desde el pago o desde la caja): si es la de un pago a
     * beneficiario, ese pago queda anulado en la misma transacción. Lo llama AnularSalida.
     */
    public static function alAnularSalida(SalidaCaja $salida, User $anula, string $motivo): void
    {
        $pago = PagoBeneficiario::query()->where('salida_caja_id', $salida->id)->whereNull('anulado_en')->lockForUpdate()->first();
        if ($pago === null) {
            return;
        }
        $liquidacion = LiquidacionBeneficiario::query()->whereKey($pago->liquidacion_beneficiario_id)->lockForUpdate()->firstOrFail();
        self::marcarAnulado($pago, $liquidacion, $anula, mb_substr("Salida de caja anulada: {$motivo}", 0, FlujoLiquidacionBeneficiario::MOTIVO_MAXIMO + 30));
    }

    private static function marcarAnulado(PagoBeneficiario $pago, LiquidacionBeneficiario $liquidacion, User $usuario, string $motivo): void
    {
        Auditoria::conDetalle("Pago al beneficiario anulado. Motivo: {$motivo}", function () use ($pago, $liquidacion, $usuario, $motivo) {
            $pago->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulado_en' => now(), 'anulado_por' => $usuario->id, 'motivo_anulacion' => $motivo])->save();
            $pago->unsetRelation('estado');
            self::recalcular($liquidacion);
        });
    }

    /** El estado según lo pagado: sin pagos, APROBADO; con saldo, PARCIAL; sin saldo, PAGADO. */
    private static function recalcular(LiquidacionBeneficiario $liquidacion): void
    {
        $liquidacion->unsetRelation('estado');
        $pagado = (int) round((float) $liquidacion->pagosVigentes()->sum('monto'));
        $estado = match (true) {
            $pagado === 0 => Estado::APROBADO,
            $pagado >= $liquidacion->totalEntero() => Estado::PAGADO,
            default => Estado::PARCIAL,
        };
        if ((int) $liquidacion->estado_id !== Estado::idDe($estado)) {
            $liquidacion->forceFill(['estado_id' => Estado::idDe($estado)])->save();
            $liquidacion->unsetRelation('estado');
        }
    }
}
