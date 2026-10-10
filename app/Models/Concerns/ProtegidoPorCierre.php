<?php

namespace App\Models\Concerns;

use App\Exceptions\ConsultaCerrada;
use App\Exceptions\RegistroClinicoNoSeBorra;
use App\Models\Builders\ConsultaClinica;
use App\Models\Consulta;

/**
 * Regla de cierre de la historia clínica, en el modelo (vale desde cualquier código: controladores,
 * servicios, tinker):
 *
 * - Una consulta cerrada (FINALIZADA o ANULADA) y todo lo que cuelga de ella (anamnesis, examen físico,
 *   diagnósticos, indicaciones, recetas y sus renglones) rechazan crear, modificar o borrar
 *   (ConsultaCerrada, 409). Se mira el estado GUARDADO de la consulta: la transición EN_CURSO → FINALIZADO
 *   de Finalizar sale de una consulta abierta y pasa. Cada modelo puede permitir un cambio puntual con la
 *   consulta cerrada (permitidoConConsultaCerrada): solo la anulación de una receta EMITIDA.
 * - Nada se borra (RegistroClinicoNoSeBorra), salvo lo que el modelo permita (sePuedeBorrar): solo
 *   "Quitar" un renglón de una receta en BORRADOR.
 * - Sin cambios ni borrados en masa (ConsultaClinica).
 */
trait ProtegidoPorCierre
{
    public static function bootProtegidoPorCierre(): void
    {
        foreach (['creating', 'updating'] as $evento) {
            static::$evento(function (self $modelo) {
                if ($modelo->consultaCerradaDeCierre() && ! $modelo->permitidoConConsultaCerrada()) {
                    throw new ConsultaCerrada;
                }
            });
        }

        static::deleting(function (self $modelo) {
            if ($modelo->consultaCerradaDeCierre()) {
                throw new ConsultaCerrada;
            }
            if (! $modelo->sePuedeBorrar()) {
                throw new RegistroClinicoNoSeBorra;
            }
        });
    }

    /** La consulta de la que cuelga este registro (la propia, si es una consulta), o null. */
    abstract protected function consultaDeCierre(): ?Consulta;

    /** El cambio en curso se permite aunque la consulta esté cerrada. */
    protected function permitidoConConsultaCerrada(): bool
    {
        return false;
    }

    /** Este registro se puede borrar (por defecto, no: nada se elimina). */
    protected function sePuedeBorrar(): bool
    {
        return false;
    }

    /** La consulta de la que cuelga está cerrada, según su estado guardado (no el que se está por guardar). */
    private function consultaCerradaDeCierre(): bool
    {
        $consulta = $this->consultaDeCierre();
        if (! $consulta) {
            return false;
        }
        $estado = $consulta->getRawOriginal('estado_id') ?? $consulta->estado_id;

        return in_array((int) $estado, Consulta::estadosCerrados(), true);
    }

    public function newEloquentBuilder($query): ConsultaClinica
    {
        return new ConsultaClinica($query);
    }

    /** El guardado de este registro (con sus eventos) es lo único que puede usar update() del builder. */
    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        if ($query instanceof ConsultaClinica) {
            $query->deUnRegistro = true;
        }

        return parent::performUpdate($query);
    }

    /** Ídem para el borrado de este registro (los eventos ya decidieron si se permite). */
    protected function performDeleteOnModel()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());
        $query->deUnRegistro = true;
        $query->delete();

        $this->exists = false;
    }
}
