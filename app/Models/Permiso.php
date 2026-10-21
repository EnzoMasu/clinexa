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
        // Parámetros clínicos: un juego fijo (no se crean ni se desactivan); se editan y se aprueban (con EDITAR).
        'PARAMETROS_CLINICOS' => ['VER', 'EDITAR'],
        // PDF clínico: solo se genera (EXPORTAR); no hay nada que crear, editar ni desactivar.
        'PDF_CLINICO' => ['EXPORTAR'],
        // Reposo médico: se ve (y se saca su PDF), se emite y se anula; no se desactiva ni se borra.
        'REPOSO_MEDICO' => ['VER', 'CREAR', 'EDITAR'],
        // Orden de estudio: igual que el reposo (se ve, se emite y se anula).
        'ORDENES_ESTUDIO' => ['VER', 'CREAR', 'EDITAR'],
        // Tarifas: alta (CREAR) y "Cambiar tarifa" (EDITAR, cierra la vigente y abre otra); no se desactivan ni se borran.
        'TARIFAS' => ['VER', 'CREAR', 'EDITAR'],
        // Prestaciones: nacen solas al finalizar la consulta; se tarifan (EDITAR) y se anulan con motivo (DESACTIVAR).
        'PRESTACIONES' => ['VER', 'EDITAR', 'DESACTIVAR'],
        // Acuerdos de reparto: alta (CREAR), "Cambiar porcentaje" y "Repartir" una prestación pendiente (EDITAR); no se borran.
        'ACUERDOS_REPARTO' => ['VER', 'CREAR', 'EDITAR'],
        // Repartos de las prestaciones: nacen solos; solo se ven (el detalle dentro de la prestación).
        'REPARTOS' => ['VER'],
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
