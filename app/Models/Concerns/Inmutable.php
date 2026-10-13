<?php

namespace App\Models\Concerns;

use App\Exceptions\RegistroInmutable;

/** Se crea una vez: no se modifica ni se elimina (adendas y versiones de una consulta). */
trait Inmutable
{
    public static function bootInmutable(): void
    {
        static::updating(fn () => throw new RegistroInmutable);
        static::deleting(fn () => throw new RegistroInmutable);
    }
}
