<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ProtegidoPorCierre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Indicación general de una consulta (reposo, dieta, control...), que sale en la hoja de la receta
 * para el paciente. No se borra: se retira (activo = false) y se puede reponer. Sus cambios quedan
 * en la auditoría como EDITAR de la consulta.
 */
class Indicacion extends Model
{
    use Auditable, ProtegidoPorCierre;

    protected $table = 'indicaciones';

    protected $fillable = [
        'consulta_id',
        'tipo_indicacion_id',
        'descripcion',
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

    public function tipoIndicacion(): BelongsTo
    {
        return $this->belongsTo(TipoIndicacion::class);
    }

    /** Para el log: "Reposo: 48 horas" (o solo el texto si no tiene tipo), y " (retirado)" si está retirada. */
    public function descripcionAuditoria(): string
    {
        return ($this->tipoIndicacion ? "{$this->tipoIndicacion->nombre}: " : '').$this->descripcion.($this->activo ? '' : ' (retirado)');
    }

    /** Regla de cierre (ProtegidoPorCierre): la consulta de la que cuelga, leída de la base. */
    protected function consultaDeCierre(): ?Consulta
    {
        return $this->consulta_id ? Consulta::query()->select(['id', 'estado_id'])->find($this->consulta_id) : null;
    }
}
