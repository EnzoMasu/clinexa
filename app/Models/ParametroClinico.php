<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parámetro clínico de un signo vital (catálogo fijo, módulo PARAMETROS_CLINICOS): tres bandas anidadas
 * (imposible ⊇ muy alterado ⊇ normal; las dos últimas pueden faltar) que usa EvaluadorSignos.
 *
 * - Estado PENDIENTE o APROBADO. Solo los APROBADOS marcan "fuera de lo normal" o piden confirmación; el
 *   límite "imposible" rige siempre.
 * - Lo aprueba un Profesional activo con EDITAR sobre el módulo (aprobar()); editar uno aprobado lo devuelve a
 *   PENDIENTE (y borra quién y cuándo lo aprobó).
 * - No es contenido clínico (lo ve quien ve la auditoría). Rangos para adultos (docs/triaje.md).
 */
class ParametroClinico extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'parametros_clinicos';

    public const BANDAS = ['imposible', 'muy_alterado', 'normal'];

    /** Los campos que, al cambiar, devuelven el parámetro a PENDIENTE. */
    public const CAMPOS_CLINICOS = ['unidad', 'imposible_min', 'imposible_max', 'muy_alterado_min', 'muy_alterado_max', 'normal_min', 'normal_max'];

    protected $fillable = [
        'unidad',
        'imposible_min',
        'imposible_max',
        'muy_alterado_min',
        'muy_alterado_max',
        'normal_min',
        'normal_max',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'imposible_min' => 'float',
            'imposible_max' => 'float',
            'muy_alterado_min' => 'float',
            'muy_alterado_max' => 'float',
            'normal_min' => 'float',
            'normal_max' => 'float',
            'aprobado_en' => 'datetime',
        ];
    }

    public static function moduloEstado(): string
    {
        return 'PARAMETROS_CLINICOS';
    }

    protected static function booted(): void
    {
        // Editar un parámetro aprobado lo devuelve a PENDIENTE: lo tiene que volver a aprobar un médico.
        static::updating(function (ParametroClinico $parametro) {
            if ($parametro->isDirty(self::CAMPOS_CLINICOS) && ! $parametro->isDirty('aprobado_en')) {
                $parametro->estado_id = Estado::idDe(Estado::PENDIENTE);
                $parametro->aprobado_por = null;
                $parametro->aprobado_en = null;
            }
        });
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function aprobado(): bool
    {
        return $this->tieneEstado(Estado::APROBADO);
    }

    /** Lo aprueba (quién y cuándo). La regla de quién puede está en el controlador (EDITAR + Profesional activo). */
    public function aprobar(User $usuario): void
    {
        $this->forceFill(['estado_id' => Estado::idDe(Estado::APROBADO), 'aprobado_por' => $usuario->id, 'aprobado_en' => now()])->save();
        $this->unsetRelation('estado');
    }

    /** Una banda como texto: "36 a 37,5 °C", "hasta 100 %", "sin definir". */
    public function banda(string $banda): string
    {
        [$minimo, $maximo] = [$this->{"{$banda}_min"}, $this->{"{$banda}_max"}];
        $numero = fn (float $valor) => rtrim(rtrim(number_format($valor, 2, ',', '.'), '0'), ',');

        return match (true) {
            $minimo === null && $maximo === null => 'sin definir',
            $minimo === null => 'hasta '.$numero($maximo).' '.$this->unidad,
            $maximo === null => 'desde '.$numero($minimo).' '.$this->unidad,
            default => $numero($minimo).' a '.$numero($maximo).' '.$this->unidad,
        };
    }
}
