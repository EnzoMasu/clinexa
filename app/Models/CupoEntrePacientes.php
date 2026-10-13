<?php

namespace App\Models;

use App\Enums\TipoTurno;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cupo de pacientes "entre pacientes" de un profesional en un día (0 a 30; sin fila vale 0). Lo fija recepción
 * con permiso sobre DISPONIBILIDAD (CupoEntrePacientesController). Se audita en ese módulo.
 */
class CupoEntrePacientes extends Model
{
    use Auditable;

    public const MAXIMO = 30;

    /** Días hacia adelante en los que se puede fijar. */
    public const DIAS_ADELANTE = 60;

    protected $table = 'cupos_entre_pacientes';

    protected $fillable = [
        'profesional_id',
        'fecha',
        'cantidad',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'cantidad' => 'integer',
        ];
    }

    public static function moduloAuditoria(): string
    {
        return 'DISPONIBILIDAD';
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    /** Turnos ENTRE_PACIENTES del profesional ese día que no están CANCELADO. */
    public static function usados(int $profesionalId, string $fecha): int
    {
        return Turno::where('tipo', TipoTurno::ENTRE_PACIENTES->value)
            ->where('profesional_id', $profesionalId)->whereDate('fecha', $fecha)
            ->where('estado_id', '!=', Estado::idDe(Estado::CANCELADO))
            ->count();
    }
}
