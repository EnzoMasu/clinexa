<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

class Ciudad extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'ciudades';

    protected $fillable = [
        'departamento_id',
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'GEOGRAFIA';
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    /** Motivo por el que no se puede reactivar (su departamento está inactivo), o null si se puede. */
    public function bloqueoDeAlta(): ?string
    {
        return $this->departamento->estaActivo() ? null
            : "No se puede reactivar {$this->nombre}: su departamento, {$this->departamento->nombre}, está inactivo. Reactive primero el departamento.";
    }

    /**
     * Regla para un ciudad_id opcional: vacío, una ciudad ACTIVA, o la que el registro ya tenía.
     */
    public static function reglaOpcional(?int $actual = null): array
    {
        return ['nullable', 'integer', Rule::exists('ciudades', 'id')->where(fn ($query) => $query
            ->where('estado_id', Estado::idDe(Estado::ACTIVO))
            ->when($actual, fn ($query) => $query->orWhere('id', $actual)))];
    }
}
