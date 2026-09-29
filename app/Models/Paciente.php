<?php

namespace App\Models;

use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class Paciente extends Model
{
    use EsRolDePersona, TieneEstado;

    protected $table = 'pacientes';

    protected $fillable = [
        'persona_id',
        'nro_ficha',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'PACIENTES';
    }

    public static function tiposPersona(): array
    {
        return ['FISICA'];
    }

    /**
     * Número de ficha que se propone al crear: el mayor número de ficha numérico + 1, con ceros
     * a la izquierda (000124). Es editable: las fichas en papel pueden migrarse con su número.
     */
    public static function siguienteNroFicha(): string
    {
        $mayor = self::query()->withoutEagerLoads()->pluck('nro_ficha')
            ->filter(fn (string $nro) => ctype_digit($nro))
            ->map(fn (string $nro) => (int) $nro)
            ->max() ?? 0;

        return str_pad((string) ($mayor + 1), 6, '0', STR_PAD_LEFT);
    }
}
