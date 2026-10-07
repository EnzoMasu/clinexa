<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Consultas de LogAuditoria: además de impedir modificar o borrar un registro (eventos del
 * modelo), impide hacerlo en masa (LogAuditoria::where(...)->delete(), ->update(), truncate()).
 * Lo que se haga por fuera de Eloquent lo frena el trigger de PostgreSQL.
 */
class ConsultaLogAuditoria extends Builder
{
    public function update(array $values)
    {
        $this->rechazar();
    }

    public function delete()
    {
        $this->rechazar();
    }

    public function forceDelete()
    {
        $this->rechazar();
    }

    public function truncate()
    {
        $this->rechazar();
    }

    private function rechazar(): never
    {
        throw new LogicException('El log de auditoría no se puede modificar ni borrar.');
    }
}
