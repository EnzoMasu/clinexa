<?php

namespace Database\Seeders;

use App\Models\Estado;
use App\Models\ModuloSistema;
use Illuminate\Database\Seeder;

/**
 * Módulos del sistema (filas de la matriz de permisos) y los estados que admite cada uno
 * (estado_modulo; el primero es el inicial). Idempotente: se puede correr de nuevo cuando se
 * agreguen módulos, actualiza por código sin duplicar.
 */
class ModuloSistemaSeeder extends Seeder
{
    /** Estados de casi todos los módulos: ACTIVO (inicial) e INACTIVO. */
    private const ACTIVO_INACTIVO = [Estado::ACTIVO, Estado::INACTIVO];

    /**
     * Módulos que ofrecen tipos de documento: aparecen en el formulario de Tipos de documento para
     * habilitarlos. Al agregar Facturación, Compras, etc., basta con sumar su código acá.
     */
    private const USAN_TIPOS_DOCUMENTO = ['PERSONAS'];

    public function run(): void
    {
        $modulos = [
            'USUARIOS' => ['Usuarios', [Estado::ACTIVO, Estado::INACTIVO, Estado::BLOQUEADO]],
            'PERFILES_ACCESO' => ['Perfiles de acceso', self::ACTIVO_INACTIVO],
            'PERSONAS' => ['Personas', self::ACTIVO_INACTIVO],
            'ESPECIALIDADES' => ['Especialidades', self::ACTIVO_INACTIVO],
            'SUCURSALES' => ['Sucursales', self::ACTIVO_INACTIVO],
            'TIPOS_DOCUMENTO' => ['Tipos de documento', self::ACTIVO_INACTIVO],
            'CIE10' => ['Catálogo CIE-10', self::ACTIVO_INACTIVO],
            'MEDIOS_PAGO' => ['Medios de pago', self::ACTIVO_INACTIVO],
            'CATEGORIAS_GASTO' => ['Categorías de gasto', self::ACTIVO_INACTIVO],
            'CATEGORIAS_PROVEEDOR' => ['Categorías de proveedor', self::ACTIVO_INACTIVO],
            'TIPOS_RED_SOCIAL' => ['Tipos de red social', self::ACTIVO_INACTIVO],
            'PROCEDIMIENTOS' => ['Procedimientos', self::ACTIVO_INACTIVO],
            'TIPOS_BLOQUE_ANAMNESIS' => ['Tipos de bloque de anamnesis', self::ACTIVO_INACTIVO],
            // Roles de negocio sobre Persona.
            'PACIENTES' => ['Pacientes', self::ACTIVO_INACTIVO],
            'PROFESIONALES' => ['Profesionales', self::ACTIVO_INACTIVO],
            'PROVEEDORES' => ['Proveedores', self::ACTIVO_INACTIVO],
            'RESPONSABLES_PAGO' => ['Responsables de pago', self::ACTIVO_INACTIVO],
            // Agenda.
            'CONSULTORIOS' => ['Consultorios', self::ACTIVO_INACTIVO],
            'ORIGENES_TURNO' => ['Orígenes de turno', self::ACTIVO_INACTIVO],
            'DISPONIBILIDAD' => ['Disponibilidades', self::ACTIVO_INACTIVO],
            'TURNOS' => ['Turnos', [Estado::PENDIENTE, Estado::CONFIRMADO, Estado::ATENDIDO, Estado::CANCELADO, Estado::AUSENTE]],
            // Historia clínica: historias, consultas, anamnesis, examen físico y diagnósticos (solo VER, CREAR y EDITAR).
            'HISTORIA_CLINICA' => ['Historia clínica', self::ACTIVO_INACTIVO],
            // Consulta del log de auditoría (solo VER; EXPORTAR cuando haya exportación).
            'AUDITORIA' => ['Auditoría', self::ACTIVO_INACTIVO],
            // Un solo módulo para las tres pantallas: Países, Departamentos y Ciudades.
            'GEOGRAFIA' => ['Geografía (países, departamentos y ciudades)', self::ACTIVO_INACTIVO],
            // Sin pantalla propia todavía: existe para que su tabla tenga estados configurados.
            'MODULOS_SISTEMA' => ['Módulos del sistema', self::ACTIVO_INACTIVO],
        ];

        foreach ($modulos as $codigo => [$nombre, $estados]) {
            ModuloSistema::updateOrCreate(['codigo' => $codigo], [
                'nombre' => $nombre,
                'usa_tipos_documento' => in_array($codigo, self::USAN_TIPOS_DOCUMENTO, true),
            ])
                ->configurarEstados($estados);
        }
    }
}
