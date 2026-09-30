<?php

namespace App\Models;

use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ModuloSistema extends Model
{
    use TieneEstado;

    protected $table = 'modulos_sistema';

    protected $fillable = [
        'codigo',
        'nombre',
        'es_sensible',
        'usa_tipos_documento',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'MODULOS_SISTEMA';
    }

    protected function casts(): array
    {
        return [
            'es_sensible' => 'boolean',
            'usa_tipos_documento' => 'boolean',
        ];
    }

    public function permisos(): HasMany
    {
        return $this->hasMany(Permiso::class, 'modulo_sistema_id');
    }

    /**
     * Estados que pueden tomar los registros de este módulo (estado_modulo), con cuál es el inicial.
     */
    public function estados(): BelongsToMany
    {
        return $this->belongsToMany(Estado::class, 'estado_modulo', 'modulo_sistema_id', 'estado_id')
            ->withPivot('es_inicial');
    }

    /**
     * Tipos de documento que acepta este módulo (tipo_documento_modulo), con cuál es el predeterminado.
     */
    public function tiposDocumento(): BelongsToMany
    {
        return $this->belongsToMany(TipoDocumento::class, 'tipo_documento_modulo', 'modulo_sistema_id', 'tipo_documento_id')
            ->withPivot('es_predeterminado');
    }

    /**
     * Módulos que ofrecen tipos de documento: los que se pueden habilitar desde el formulario de
     * Tipos de documento. Un módulo nuevo aparece ahí con solo marcarlo en ModuloSistemaSeeder.
     */
    public function scopeUsanTiposDocumento(Builder $query): void
    {
        $query->where('usa_tipos_documento', true);
    }

    /**
     * Habilita tipos de documento para el módulo (por código); el primero es el predeterminado.
     * Idempotente: deja exactamente esos tipos. Marca al módulo como usuario de tipos de documento.
     *
     * @param  list<string>  $codigos
     */
    public function configurarTiposDocumento(array $codigos): void
    {
        $ids = $this->idsTiposDocumento($codigos);

        $this->update(['usa_tipos_documento' => true]);

        // Sin predeterminado primero: el índice único admite uno solo por módulo en cada momento.
        $this->tiposDocumento()->newPivotStatement()->where('modulo_sistema_id', $this->id)->update(['es_predeterminado' => false]);
        $this->tiposDocumento()->sync(collect($codigos)->values()->mapWithKeys(
            fn (string $codigo, int $i) => [$ids[$codigo] => ['es_predeterminado' => $i === 0]]
        )->all());
    }

    /**
     * Versión SOLO ADITIVA de configurarTiposDocumento, para seeders que corren sobre la base real:
     * agrega los tipos que falten sin quitar ni modificar las habilitaciones existentes (las
     * cargadas a mano desde Tipos de documento incluidas). El primero de la lista queda como
     * predeterminado solo si el módulo todavía no tiene ninguno.
     *
     * @param  list<string>  $codigos
     */
    public function habilitarTiposDocumento(array $codigos): void
    {
        $ids = $this->idsTiposDocumento($codigos);

        $this->update(['usa_tipos_documento' => true]);

        $yaHabilitados = $this->tiposDocumento()->pluck('tipos_documento.id')->all();
        $this->tiposDocumento()->attach(array_diff($ids->values()->all(), $yaHabilitados));

        if (! $this->tiposDocumento()->wherePivot('es_predeterminado', true)->exists()) {
            $this->tiposDocumento()->updateExistingPivot($ids[$codigos[0]], ['es_predeterminado' => true]);
        }
    }

    /**
     * ids de los tipos de documento por código; falla si falta alguno.
     *
     * @param  list<string>  $codigos
     * @return Collection<string, int>
     */
    private function idsTiposDocumento(array $codigos): Collection
    {
        $ids = TipoDocumento::whereIn('codigo', $codigos)->pluck('id', 'codigo');
        $faltantes = array_diff($codigos, $ids->keys()->all());
        if ($faltantes) {
            throw new \RuntimeException('No existen los tipos de documento: '.implode(', ', $faltantes));
        }

        return $ids;
    }

    /**
     * Habilita los estados del módulo en estado_modulo; el primero de la lista es el inicial.
     * Idempotente: deja exactamente esos estados.
     *
     * @param  list<string>  $codigos
     */
    public function configurarEstados(array $codigos): void
    {
        $this->estados()->sync(collect($codigos)->values()->mapWithKeys(
            fn (string $codigo, int $i) => [Estado::idDe($codigo) => ['es_inicial' => $i === 0]]
        )->all());
    }
}
