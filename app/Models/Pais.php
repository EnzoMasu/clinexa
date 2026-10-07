<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

class Pais extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'paises';

    protected $fillable = [
        'codigo',
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'GEOGRAFIA';
    }

    public function departamentos(): HasMany
    {
        return $this->hasMany(Departamento::class);
    }

    /**
     * Países para un select: los ACTIVOS, más el que el registro ya tenía aunque esté inactivo.
     *
     * @return array<int, string> id => nombre
     */
    public static function opciones(?int $actual = null): array
    {
        return self::query()
            ->where(fn ($query) => $query->activos()->when($actual, fn ($query) => $query->orWhere('id', $actual)))
            ->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    /**
     * Regla para un país opcional: vacío, un país ACTIVO, o el que el registro ya tenía.
     */
    public static function reglaOpcional(?int $actual = null): array
    {
        return ['nullable', 'integer', Rule::exists('paises', 'id')->where(fn ($query) => $query
            ->where('estado_id', Estado::idDe(Estado::ACTIVO))
            ->when($actual, fn ($query) => $query->orWhere('id', $actual)))];
    }

    /** id de Paraguay (nacionalidad preelegida al cargar una persona), o null si no está cargado. */
    public static function idParaguay(): ?int
    {
        return self::where('nombre', 'Paraguay')->value('id');
    }
}
