<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloque de la anamnesis de una consulta (Enfermedad actual, Alergias, ...). No se borra: se retira
 * (activo = false) y se puede reponer. Sus cambios quedan en la auditoría como EDITAR de la consulta.
 */
class BloqueAnamnesis extends Model
{
    use Auditable;

    protected $table = 'bloques_anamnesis';

    protected $fillable = [
        'consulta_id',
        'tipo_bloque_anamnesis_id',
        'contenido',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public static function moduloAuditoria(): string
    {
        return 'HISTORIA_CLINICA';
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function tipoBloqueAnamnesis(): BelongsTo
    {
        return $this->belongsTo(TipoBloqueAnamnesis::class);
    }

    /** Para el log: "Alergias: penicilina", y " (retirado)" si está retirado. */
    public function descripcion(): string
    {
        return "{$this->tipoBloqueAnamnesis->nombre}: {$this->contenido}".($this->activo ? '' : ' (retirado)');
    }
}
