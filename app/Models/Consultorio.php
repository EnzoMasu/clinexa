<?php

namespace App\Models;

use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consultorio extends Model
{
    use TieneEstado;

    protected $table = 'consultorios';

    protected $fillable = [
        'sucursal_id',
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'CONSULTORIOS';
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /** "Consultorio 1 — Plenitud Mujer", para los selectores. */
    public function getNombreCompletoAttribute(): string
    {
        return "{$this->nombre} — {$this->sucursal->nombre}";
    }
}
