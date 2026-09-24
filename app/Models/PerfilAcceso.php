<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerfilAcceso extends Model
{
    /**
     * Perfil con acceso total. Se identifica por nombre, por eso el nombre no se puede cambiar,
     * y sus permisos no se editan desde el panel: siempre tiene todas las acciones en todos los módulos.
     */
    public const ADMINISTRADOR = 'Administrador';

    protected $table = 'perfiles_acceso';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(Permiso::class, 'perfil_permiso', 'perfil_acceso_id', 'permiso_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'perfil_acceso_id');
    }

    public function esAdministrador(): bool
    {
        return $this->nombre === self::ADMINISTRADOR;
    }

    /**
     * Crea el perfil Administrador si no existe y le asigna las 5 acciones en todos los módulos,
     * creando los permisos que falten. Se puede llamar las veces que sea: completa, nunca quita.
     */
    public static function asegurarAdministrador(): self
    {
        $perfil = self::firstOrCreate(['nombre' => self::ADMINISTRADOR], ['descripcion' => 'Acceso total al sistema']);

        $permisoIds = ModuloSistema::all()->flatMap(fn (ModuloSistema $modulo) => collect(Permiso::ACCIONES)->map(
            fn (string $accion) => Permiso::firstOrCreate(['modulo_sistema_id' => $modulo->id, 'accion' => $accion])->id
        ));

        $perfil->permisos()->syncWithoutDetaching($permisoIds->all());

        return $perfil;
    }
}
