<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Model;

class Paciente extends Model
{
    use Auditable, EsRolDePersona, TieneEstado;

    protected $table = 'pacientes';

    /** Prefijo y ancho del número de ficha: "FP-0000003". */
    public const PREFIJO_FICHA = 'FP-';

    public const DIGITOS_FICHA = 7;

    // fecha_alta no es asignable: se completa sola al crear (ver booted).
    protected $fillable = [
        'persona_id',
        'nro_ficha',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_alta' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Paciente $paciente) {
            $paciente->fecha_alta ??= Fecha::hoy()->format('Y-m-d');
        });
    }

    public static function moduloEstado(): string
    {
        return 'PACIENTES';
    }

    public static function tiposPersona(): array
    {
        return ['FISICA'];
    }

    /**
     * Número de ficha que se propone al crear: el mayor número FP- (o solo dígitos) + 1, con el
     * formato "FP-0000124". Es editable: las fichas en papel pueden migrarse con su número.
     */
    public static function siguienteNroFicha(): string
    {
        $mayor = self::query()->withoutEagerLoads()->pluck('nro_ficha')
            ->map(fn (string $nro) => str_starts_with($nro, self::PREFIJO_FICHA) ? substr($nro, strlen(self::PREFIJO_FICHA)) : $nro)
            ->filter(fn (string $numero) => ctype_digit($numero))
            ->map(fn (string $numero) => (int) $numero)
            ->max() ?? 0;

        return self::nroFicha($mayor + 1);
    }

    /** 3 -> "FP-0000003". */
    public static function nroFicha(int $numero): string
    {
        return self::PREFIJO_FICHA.str_pad((string) $numero, self::DIGITOS_FICHA, '0', STR_PAD_LEFT);
    }
}
