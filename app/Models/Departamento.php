<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Departamento extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'departamentos';

    protected $fillable = [
        'pais_id',
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'GEOGRAFIA';
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    /** Motivo por el que no se puede desactivar (tiene ciudades activas), o null si se puede. */
    public function bloqueoDeBaja(): ?string
    {
        $n = $this->ciudades()->activos()->count();

        return $n === 0 ? null : sprintf('No se puede desactivar %s: tiene %s. Desactívelas antes.', $this->nombre,
            $n === 1 ? 'una ciudad activa' : "{$n} ciudades activas");
    }

    /** Motivo por el que no se puede reactivar (su país está inactivo), o null si se puede. */
    public function bloqueoDeAlta(): ?string
    {
        return $this->pais->estaActivo() ? null
            : "No se puede reactivar {$this->nombre}: su país, {$this->pais->nombre}, está inactivo. Reactive primero el país.";
    }

    public function ciudades(): HasMany
    {
        return $this->hasMany(Ciudad::class);
    }
}
