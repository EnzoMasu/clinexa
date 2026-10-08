<?php

namespace App\Models;

use App\Enums\TipoDiagnostico;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diagnóstico CIE-10 de una consulta. Una consulta puede tener varios; entre los activos, uno es el
 * principal. No se borra: se retira (activo = false) y se puede reponer. Sus cambios quedan en la
 * auditoría como EDITAR de la consulta.
 */
class Diagnostico extends Model
{
    use Auditable;

    protected $table = 'diagnosticos';

    protected $fillable = [
        'consulta_id',
        'codigo_cie10',
        'tipo',
        'principal',
        'descripcion_adicional',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDiagnostico::class,
            'principal' => 'boolean',
            'activo' => 'boolean',
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

    public function cie10(): BelongsTo
    {
        return $this->belongsTo(CatalogoCIE10::class, 'codigo_cie10', 'codigo');
    }

    /** "J06.9 — Rinofaringitis aguda". */
    public function codigoYDescripcion(): string
    {
        return $this->codigo_cie10.' — '.($this->cie10?->descripcion ?? '');
    }

    /** Para el log: "J06.9 — Rinofaringitis aguda (CONFIRMADO, principal): detalle", y "retirado". */
    public function descripcion(): string
    {
        $marcas = array_filter([$this->tipo->value, $this->principal ? 'principal' : null, $this->activo ? null : 'retirado']);

        return $this->codigoYDescripcion().' ('.implode(', ', $marcas).')'
            .($this->descripcion_adicional ? ": {$this->descripcion_adicional}" : '');
    }
}
