<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TipoDocumento extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'tipos_documento';

    protected $fillable = [
        'codigo',
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'TIPOS_DOCUMENTO';
    }

    /**
     * Módulos que aceptan este tipo de documento (tipo_documento_modulo).
     */
    /** En el log, los módulos habilitados: "Personas (predeterminado)". */
    public function relacionesAuditadas(): array
    {
        return [
            'modulos' => fn (Collection $modulos) => $modulos
                ->map(fn (ModuloSistema $modulo) => $modulo->nombre.($modulo->pivot->es_predeterminado ? ' (predeterminado)' : ''))
                ->sort()->values()->all(),
        ];
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(ModuloSistema::class, 'tipo_documento_modulo', 'tipo_documento_id', 'modulo_sistema_id')
            ->withPivot('es_predeterminado');
    }

    /**
     * Tipos habilitados para un módulo (código de modulos_sistema), el predeterminado primero.
     */
    public function scopeHabilitadosPara(Builder $query, string $modulo): void
    {
        $query->join('tipo_documento_modulo', 'tipo_documento_modulo.tipo_documento_id', '=', 'tipos_documento.id')
            ->join('modulos_sistema', 'modulos_sistema.id', '=', 'tipo_documento_modulo.modulo_sistema_id')
            ->where('modulos_sistema.codigo', $modulo)
            ->select('tipos_documento.*', 'tipo_documento_modulo.es_predeterminado')
            ->orderByDesc('tipo_documento_modulo.es_predeterminado')
            ->orderBy('tipos_documento.nombre');
    }

    /**
     * id del tipo predeterminado para un módulo, o null si no tiene. Un predeterminado INACTIVO
     * conserva la marca pero no cuenta: vuelve a ser el predeterminado si se reactiva.
     */
    public static function predeterminadoPara(string $modulo): ?int
    {
        return self::query()->habilitadosPara($modulo)->activos()
            ->where('tipo_documento_modulo.es_predeterminado', true)
            ->value('tipos_documento.id');
    }
}
