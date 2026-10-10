<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ProtegidoPorCierre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Historia clínica de un paciente (una por paciente). Se crea sola al darse de alta el paciente
 * (Paciente::booted), con fecha de apertura = su fecha de alta.
 */
class HistoriaClinica extends Model
{
    use Auditable, ProtegidoPorCierre;

    protected $table = 'historias_clinicas';

    protected $fillable = [
        'paciente_id',
        'fecha_apertura',
    ];

    protected function casts(): array
    {
        return [
            'fecha_apertura' => 'date',
        ];
    }

    public static function moduloAuditoria(): string
    {
        return 'HISTORIA_CLINICA';
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function consultas(): HasMany
    {
        return $this->hasMany(Consulta::class);
    }

    /** Regla de cierre (ProtegidoPorCierre): la historia no cuelga de una consulta; solo rige "nada se borra". */
    protected function consultaDeCierre(): ?Consulta
    {
        return null;
    }
}
