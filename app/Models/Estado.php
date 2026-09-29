<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RuntimeException;

/**
 * Catálogo único de estados del sistema. Qué estados puede tomar cada módulo (y cuál es el
 * inicial) se define en estado_modulo; la lógica compara siempre por codigo, nunca por nombre.
 */
class Estado extends Model
{
    public const ACTIVO = 'ACTIVO';

    public const INACTIVO = 'INACTIVO';

    public const BLOQUEADO = 'BLOQUEADO';

    protected $table = 'estados';

    protected $fillable = [
        'codigo',
        'nombre',
    ];

    /**
     * ids por código, cargados una vez: el catálogo lo siembra una migración y no cambia en runtime.
     *
     * @var array<string, int>
     */
    private static array $ids = [];

    public static function idDe(string $codigo): int
    {
        return self::$ids[$codigo] ??= self::where('codigo', $codigo)->value('id')
            ?? throw new RuntimeException("No existe el estado {$codigo} en la tabla estados.");
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(ModuloSistema::class, 'estado_modulo', 'estado_id', 'modulo_sistema_id')
            ->withPivot('es_inicial');
    }

    /**
     * Estados habilitados para un módulo (por código de modulos_sistema), el inicial primero.
     * Si el módulo todavía no tiene estados configurados, ACTIVO e INACTIVO.
     *
     * @return Collection<int, Estado>
     */
    public static function delModulo(string $modulo): Collection
    {
        $estados = self::query()
            ->join('estado_modulo', 'estado_modulo.estado_id', '=', 'estados.id')
            ->join('modulos_sistema', 'modulos_sistema.id', '=', 'estado_modulo.modulo_sistema_id')
            ->where('modulos_sistema.codigo', $modulo)
            ->orderByDesc('estado_modulo.es_inicial')
            ->orderBy('estados.id')
            ->get(['estados.*']);

        return $estados->isNotEmpty()
            ? $estados
            : self::whereIn('codigo', [self::ACTIVO, self::INACTIVO])->orderBy('id')->get();
    }

    /**
     * Estado inicial de un módulo (es_inicial en estado_modulo), o null si no tiene configurado.
     */
    public static function inicialDe(string $modulo): ?int
    {
        return self::query()
            ->join('estado_modulo', 'estado_modulo.estado_id', '=', 'estados.id')
            ->join('modulos_sistema', 'modulos_sistema.id', '=', 'estado_modulo.modulo_sistema_id')
            ->where('modulos_sistema.codigo', $modulo)
            ->where('estado_modulo.es_inicial', true)
            ->value('estados.id');
    }
}
