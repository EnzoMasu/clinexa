<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PerfilAcceso extends Model
{
    use Auditable, TieneEstado;

    /**
     * Código del perfil con acceso total. Sus permisos no se editan desde el panel (siempre tiene todas las
     * acciones en todos los módulos, también los futuros), no se desactiva y su nombre no cambia.
     */
    public const ADMINISTRADOR = PerfilesPredefinidos::ADMINISTRADOR;

    protected $table = 'perfiles_acceso';

    // codigo y predefinido los ponen la migración y PerfilesPredefinidos; el formulario no los envía.
    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'predefinido',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'predefinido' => 'boolean',
        ];
    }

    public static function moduloEstado(): string
    {
        return 'PERFILES_ACCESO';
    }

    /** En el log, la matriz como "MÓDULO: ACCIÓN" ordenada. */
    public function relacionesAuditadas(): array
    {
        return [
            'permisos' => fn (Collection $permisos) => $permisos->loadMissing('moduloSistema')
                ->map(fn (Permiso $permiso) => "{$permiso->moduloSistema->codigo}: {$permiso->accion}")
                ->sort()->values()->all(),
        ];
    }

    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(Permiso::class, 'perfil_permiso', 'perfil_acceso_id', 'permiso_id');
    }

    /** Los usuarios que tienen este perfil (usuario_perfil). */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'usuario_perfil', 'perfil_acceso_id', 'usuario_id')->using(UsuarioPerfil::class)->withTimestamps();
    }

    /** Sus permisos como ["MÓDULO:ACCIÓN", ...] (para comparar contra los de un usuario). */
    public function clavesPermisos(): array
    {
        return $this->permisos()->with('moduloSistema')->get()
            ->map(fn (Permiso $permiso) => "{$permiso->moduloSistema->codigo}:{$permiso->accion}")->all();
    }

    public function esAdministrador(): bool
    {
        return $this->codigo === self::ADMINISTRADOR;
    }

    /**
     * Crea el perfil Administrador si no existe y le asigna las 5 acciones en todos los módulos,
     * creando los permisos que falten. Se puede llamar las veces que sea: completa, nunca quita.
     * Se lo busca por código; si todavía no tiene, adopta el que se llama "Administrador".
     */
    public static function asegurarAdministrador(): self
    {
        $nombre = PerfilesPredefinidos::NOMBRES[self::ADMINISTRADOR];
        $perfil = self::where('codigo', self::ADMINISTRADOR)->first()
            ?? self::whereNull('codigo')->where('nombre', $nombre)->first()
            ?? new self(['nombre' => $nombre, 'descripcion' => PerfilesPredefinidos::DESCRIPCIONES[self::ADMINISTRADOR]]);
        if (! $perfil->exists || $perfil->codigo === null) {
            $perfil->fill(['codigo' => self::ADMINISTRADOR, 'predefinido' => true])->save();
        }

        // El perfil Administrador no se puede desactivar: si por algún motivo quedó inactivo, se reactiva.
        if (! $perfil->estaActivo()) {
            $perfil->update(['estado_id' => Estado::idDe(Estado::ACTIVO)]);
        }

        $permisoIds = ModuloSistema::all()->flatMap(fn (ModuloSistema $modulo) => collect(Permiso::ACCIONES)->map(
            fn (string $accion) => Permiso::firstOrCreate(['modulo_sistema_id' => $modulo->id, 'accion' => $accion])->id
        ));

        $perfil->permisos()->syncWithoutDetaching($permisoIds->all());

        return $perfil;
    }
}
