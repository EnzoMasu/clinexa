<?php

namespace App\Support\Prestaciones;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\Prestacion;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\BuscarTarifa;
use Illuminate\Support\Facades\DB;

/**
 * "Tarifar" una prestación PENDIENTE: con la tarifa que se aplica a su fecha de servicio (la del profesional o la general).
 * El monto queda fijado: no cambia si la tarifa cambia después. Sin monto manual. Queda en Auditoría como EDITAR con el
 * detalle "Tarifado". Después calcula su reparto (RepartirPrestacion::alFijarMonto), sin frenar el tarifado.
 */
final class TarifarPrestacion
{
    public const NO_PENDIENTE = 'Esta prestación ya no está pendiente de tarifar.';

    public static function ejecutar(User $usuario, Prestacion $prestacion): Prestacion
    {
        return DB::transaction(function () use ($usuario, $prestacion) {
            $actual = Prestacion::query()->whereKey($prestacion->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->pendiente()) {
                throw new AccionRechazada(self::NO_PENDIENTE);
            }
            if ($actual->procedimiento_id === null) {
                throw new AccionRechazada('La prestación no tiene procedimiento: no se puede tarifar.');
            }
            $tarifa = BuscarTarifa::para($actual->procedimiento_id, $actual->profesional_id, $actual->fecha_servicio->format('Y-m-d'))
                ?? throw new AccionRechazada('No hay tarifa para ese procedimiento el '.Fecha::mostrar($actual->fecha_servicio).'. Cárguela en Tarifas.');

            Auditoria::conDetalle('Tarifado', fn () => $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::TARIFADO),
                'monto' => $tarifa->monto,
                'moneda_id' => $tarifa->moneda_id,
                'tarifa_id' => $tarifa->id,
                'tarifada_en' => now(),
                'tarifada_por' => $usuario->id,
            ])->save());
            $actual->unsetRelation('estado');
            // Su reparto, con los acuerdos de la fecha de servicio (docs/reparto-economico.md): nunca impide tarifar.
            RepartirPrestacion::alFijarMonto($actual);

            return $actual;
        });
    }
}
