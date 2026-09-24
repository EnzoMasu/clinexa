<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModuloSistema extends Model
{
    protected $table = 'modulos_sistema';

    protected $fillable = [
        'codigo',
        'nombre',
        'es_sensible',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'es_sensible' => 'boolean',
        ];
    }

    public function permisos(): HasMany
    {
        return $this->hasMany(Permiso::class, 'modulo_sistema_id');
    }
}
