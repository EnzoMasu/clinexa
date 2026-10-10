<?php

namespace App\Models\Builders;

use App\Exceptions\RegistroClinicoNoSeBorra;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Consultas Eloquent de los modelos de la historia clínica (ProtegidoPorCierre). Los cambios y borrados
 * EN MASA (Modelo::where(...)->update(), ->delete(), ->forceDelete(), truncate()) no disparan los eventos
 * del modelo, así que se saltearían la regla de cierre y la de "nada se borra": se rechazan. Solo pasa la
 * consulta interna con la que un registro se guarda o se borra a sí mismo (save/delete de una instancia:
 * ProtegidoPorCierre la marca con $deUnRegistro), donde rigen esas reglas.
 */
class ConsultaClinica extends Builder
{
    /** La marca el modelo para su propio guardado o borrado (un solo registro, con sus eventos). */
    public bool $deUnRegistro = false;

    public function update(array $values)
    {
        if (! $this->deUnRegistro) {
            throw new LogicException('Los registros de la historia clínica se modifican de a uno (save), no en masa.');
        }

        return parent::update($values);
    }

    public function delete()
    {
        if (! $this->deUnRegistro) {
            throw new RegistroClinicoNoSeBorra;
        }

        return parent::delete();
    }

    public function forceDelete()
    {
        throw new RegistroClinicoNoSeBorra;
    }

    public function truncate()
    {
        throw new RegistroClinicoNoSeBorra;
    }
}
