<?php

namespace App\Enums;

/**
 * Acciones que registra la auditoría (logs_auditoria.accion). No existe ELIMINAR: el sistema
 * nunca borra, la baja es DESACTIVAR. ANULAR y REVERTIR quedan definidas para los documentos que
 * se anulan (todavía no hay ninguno).
 */
enum AccionAuditoria: string
{
    case CREAR = 'CREAR';
    case EDITAR = 'EDITAR';
    case DESACTIVAR = 'DESACTIVAR';
    case ANULAR = 'ANULAR';
    case REVERTIR = 'REVERTIR';
    case VER = 'VER';
    case INICIO_SESION = 'INICIO_SESION';
    case INICIO_SESION_FALLIDO = 'INICIO_SESION_FALLIDO';
    case CIERRE_SESION = 'CIERRE_SESION';
    case BLOQUEO = 'BLOQUEO';
    case CAMBIO_CONTRASENA = 'CAMBIO_CONTRASENA';

    /** Texto para la pantalla de auditoría. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::CREAR => 'Crear',
            self::EDITAR => 'Editar',
            self::DESACTIVAR => 'Desactivar',
            self::ANULAR => 'Anular',
            self::REVERTIR => 'Revertir',
            self::VER => 'Ver',
            self::INICIO_SESION => 'Inicio de sesión',
            self::INICIO_SESION_FALLIDO => 'Inicio de sesión fallido',
            self::CIERRE_SESION => 'Cierre de sesión',
            self::BLOQUEO => 'Bloqueo',
            self::CAMBIO_CONTRASENA => 'Cambio de contraseña',
        };
    }
}
