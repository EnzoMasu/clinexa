<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Franja semanal en la que un profesional atiende en un consultorio (p. ej. los martes de 08:00
 * a 12:00, turnos de 30 minutos, desde el 01/10/2026).
 */
class Disponibilidad extends Model
{
    use Auditable, TieneEstado;

    /** Días de la semana, en el orden ISO (lunes = 1). */
    public const DIAS = ['LUN' => 'Lunes', 'MAR' => 'Martes', 'MIE' => 'Miércoles', 'JUE' => 'Jueves', 'VIE' => 'Viernes', 'SAB' => 'Sábado', 'DOM' => 'Domingo'];

    protected $table = 'disponibilidades';

    protected $fillable = [
        'profesional_id',
        'consultorio_id',
        'dia_semana',
        'hora_desde',
        'hora_hasta',
        'duracion_turno_minutos',
        'vigencia_desde',
        'vigencia_hasta',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'DISPONIBILIDAD';
    }

    protected function casts(): array
    {
        return [
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
            'duracion_turno_minutos' => 'integer',
        ];
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function consultorio(): BelongsTo
    {
        return $this->belongsTo(Consultorio::class);
    }

    /** Código del día de la semana de una fecha: martes -> MAR. */
    public static function diaDe(CarbonInterface $fecha): string
    {
        return array_keys(self::DIAS)[$fecha->dayOfWeekIso - 1];
    }

    /** Las que rigen en esa fecha: su día de semana y dentro de la vigencia (vigencia_hasta incluida). */
    public function scopeVigentesEn(Builder $query, CarbonInterface $fecha): void
    {
        $dia = $fecha->format('Y-m-d');

        $query->where('dia_semana', self::diaDe($fecha))
            ->whereDate('vigencia_desde', '<=', $dia)
            ->where(fn ($query) => $query->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $dia));
    }

    /** "08:00" (la base guarda "08:00:00"). */
    public static function hora(?string $hora): string
    {
        return substr((string) $hora, 0, 5);
    }
}
