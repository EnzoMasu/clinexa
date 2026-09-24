<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permiso extends Model
{
    protected $table = 'permisos';

    protected $fillable = [
        'modulo_sistema_id',
        'accion',
    ];

    public function moduloSistema(): BelongsTo
    {
        return $this->belongsTo(ModuloSistema::class, 'modulo_sistema_id');
    }

    public function perfilesAcceso(): BelongsToMany
    {
        return $this->belongsToMany(PerfilAcceso::class, 'perfil_permiso', 'permiso_id', 'perfil_acceso_id');
    }
}
