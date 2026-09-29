<?php

namespace App\Models\Concerns;

use App\Models\Estado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * Estado de un registro vía estado_id → estados. Cada modelo indica su módulo (código en
 * modulos_sistema) con moduloEstado(); de ahí salen los estados permitidos y el inicial.
 *
 * @mixin Model
 */
trait TieneEstado
{
    /**
     * Código del módulo en modulos_sistema (p. ej. 'PERSONAS'), para buscar sus estados en estado_modulo.
     */
    abstract public static function moduloEstado(): string;

    public static function bootTieneEstado(): void
    {
        // Un registro nuevo sin estado toma el inicial de su módulo (o ACTIVO si no hay configurado).
        static::creating(function (Model $modelo) {
            $modelo->estado_id ??= Estado::inicialDe(static::moduloEstado()) ?? Estado::idDe(Estado::ACTIVO);
        });
    }

    public function initializeTieneEstado(): void
    {
        // El estado se muestra en todos los listados (badge): se carga siempre junto con el registro.
        $this->with[] = 'estado';
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class);
    }

    public function tieneEstado(string $codigo): bool
    {
        return $this->estado?->codigo === $codigo;
    }

    public function estaActivo(): bool
    {
        return $this->tieneEstado(Estado::ACTIVO);
    }

    public function desactivar(): void
    {
        $this->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);
    }

    public function scopeActivos(Builder $query): void
    {
        $query->where($this->qualifyColumn('estado_id'), Estado::idDe(Estado::ACTIVO));
    }

    /**
     * @return Collection<int, Estado>
     */
    public static function estadosPermitidos(): Collection
    {
        return Estado::delModulo(static::moduloEstado());
    }

    /**
     * Regla de validación para estado_id: solo estados habilitados para el módulo.
     */
    public static function reglaEstado(): array
    {
        return ['required', 'integer', Rule::in(static::estadosPermitidos()->modelKeys())];
    }
}
