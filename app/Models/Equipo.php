<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Equipo (docs/reparto-economico.md): nombre, tipo, dueño (un proveedor) y los procedimientos que lo usan. Su dueño cobra el
 * porcentaje de su acuerdo en cada prestación de esos procedimientos. En esta fase un procedimiento usa como máximo UN equipo
 * ACTIVO (lo controla EquipoController al guardar). No hay reserva ni uso por paciente: eso queda para más adelante.
 */
class Equipo extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'equipos';

    protected $fillable = [
        'nombre',
        'tipo_equipo_id',
        'proveedor_id',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'EQUIPOS';
    }

    /** Los procedimientos que lo usan, en la auditoría como la lista de nombres de antes y de después. */
    public function relacionesAuditadas(): array
    {
        return [
            'procedimientos' => fn (Collection $procedimientos) => $procedimientos->map(fn (Procedimiento $p) => "{$p->nombre} ({$p->codigo})")->sort()->values()->all(),
        ];
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoEquipo::class, 'tipo_equipo_id');
    }

    public function dueno(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function procedimientos(): BelongsToMany
    {
        return $this->belongsToMany(Procedimiento::class, 'equipo_procedimiento')->withTimestamps();
    }

    /** El equipo ACTIVO que usa ese procedimiento (a lo sumo uno), o null. */
    public static function delProcedimiento(?int $procedimientoId): ?self
    {
        return $procedimientoId === null ? null : self::query()->activos()
            ->whereHas('procedimientos', fn ($query) => $query->whereKey($procedimientoId))
            ->orderBy('id')->first();
    }

    public function duenoTexto(): string
    {
        return (string) $this->dueno?->persona?->nombre_completo;
    }
}
