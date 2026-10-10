<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permiso extends Model
{
    public const ACCIONES = ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR', 'EXPORTAR'];

    /**
     * Módulos en los que solo algunas acciones tienen sentido. Igual existen las 5 filas en
     * permisos (el Administrador tiene siempre módulos × 5), pero la matriz de perfiles muestra las
     * demás como "no aplica" y al guardar se ignoran. Auditoría es de solo lectura; en la historia
     * clínica y en las recetas no se desactiva ni se exporta nada (no se borran: se anulan).
     */
    public const ACCIONES_POR_MODULO = [
        'AUDITORIA' => ['VER', 'EXPORTAR'],
        'HISTORIA_CLINICA' => ['VER', 'CREAR', 'EDITAR'],
        'RECETAS' => ['VER', 'CREAR', 'EDITAR'],
        'PREPARACION' => ['VER', 'CREAR', 'EDITAR'],
    ];

    /** Acciones que se pueden asignar en la matriz para ese módulo. */
    public static function accionesDe(string $codigoModulo): array
    {
        return self::ACCIONES_POR_MODULO[$codigoModulo] ?? self::ACCIONES;
    }

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
