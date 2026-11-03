<?php

namespace App\Support\Grilla;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;

/**
 * Una columna de una grilla (App\Support\Grilla\Grilla). "orden" es lo que se pasa a orderBy (columna,
 * expresión o subconsulta) o null si no se ordena; "filtro" es la columna a comparar o una función
 * fn ($query, $valor) que aplica el filtro, o null si no se filtra.
 */
final class Columna
{
    public const TEXTO = 'texto';

    public const NUMERO = 'numero';

    public const FECHA = 'fecha';

    public const OPCIONES = 'opciones';

    /**
     * @param  string|Expression|Closure|null  $orden
     * @param  string|Expression|Closure|null  $filtro
     * @param  array<string|int, string>  $opciones  valor => etiqueta (desplegable)
     */
    public function __construct(
        public readonly string $clave,
        public readonly string $etiqueta,
        public readonly string $tipo,
        public readonly mixed $orden,
        public readonly mixed $filtro,
        public readonly array $opciones = [],
        public readonly bool $utc = false,
    ) {}

    public function ordenable(): bool
    {
        return $this->orden !== null;
    }

    public function filtrable(): bool
    {
        return $this->filtro !== null;
    }
}
