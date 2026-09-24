<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerfilAcceso extends Model
{
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
}
