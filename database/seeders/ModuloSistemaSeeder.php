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
            'TIPOS_INDICACION' => ['Tipos de indicación', self::ACTIVO_INACTIVO],
            // Dietas: catálogo que se copia a la indicación general ("Insertar dieta"). Ver docs/indicaciones-generales.md.
            'DIETAS' => ['Dietas', self::ACTIVO_INACTIVO],
            // Estudios: catálogo que se ofrece en la orden de estudio. Ver docs/ordenes-estudio.md.
            'ESTUDIOS' => ['Estudios', self::ACTIVO_INACTIVO],
            // Cargos del personal de salud (catálogo) y los parámetros clínicos de los signos vitales (los aprueba un médico).
            'CARGOS_SALUD' => ['Cargos de salud', self::ACTIVO_INACTIVO],
            'PARAMETROS_CLINICOS' => ['Parámetros clínicos', [Estado::PENDIENTE, Estado::APROBADO]],
            // Roles de negocio sobre Persona.
            'PACIENTES' => ['Pacientes', self::ACTIVO_INACTIVO],
            'PROFESIONALES' => ['Profesionales', self::ACTIVO_INACTIVO],
            // Personal de salud: enfermería, obstetricia... (con el rol activo carga el triaje).
            'PERSONAL_SALUD' => ['Personal de salud', self::ACTIVO_INACTIVO],
            'PROVEEDORES' => ['Proveedores', self::ACTIVO_INACTIVO],
            'RESPONSABLES_PAGO' => ['Responsables de pago', self::ACTIVO_INACTIVO],
            // Agenda.
            'CONSULTORIOS' => ['Consultorios', self::ACTIVO_INACTIVO],
            'ORIGENES_TURNO' => ['Orígenes de turno', self::ACTIVO_INACTIVO],
            'DISPONIBILIDAD' => ['Disponibilidades', self::ACTIVO_INACTIVO],
            'TURNOS' => ['Turnos', [Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO, Estado::EN_CONSULTA, Estado::ATENDIDO, Estado::CANCELADO, Estado::AUSENTE, Estado::RETIRADO]],
            // Historia clínica: historias, consultas, anamnesis, examen físico y diagnósticos (solo VER, CREAR y EDITAR).
            // Los estados de este módulo son los de la CONSULTA (Consulta usa HISTORIA_CLINICA como módulo de
            // estados): así no hace falta un módulo aparte con permisos que no tendrían efecto.
            'HISTORIA_CLINICA' => ['Historia clínica', [Estado::EN_PREPARACION, Estado::EN_CURSO, Estado::FINALIZADO, Estado::ANULADO]],
            // Triaje (antes "Preparación"): anamnesis y signos vitales antes de entrar. Solo VER, CREAR y EDITAR. El código
            // sigue siendo PREPARACION (permisos, perfiles y auditoría lo usan): ver docs/triaje.md.
            'PREPARACION' => ['Triaje', self::ACTIVO_INACTIVO],
            // Recetas de las consultas: borrador (PENDIENTE), emitida y anulada (solo VER, CREAR y EDITAR).
            'RECETAS' => ['Recetas', [Estado::PENDIENTE, Estado::EMITIDO, Estado::ANULADO]],
            // Constancias de retiro (Recepción) y de estado del turno (profesional del turno o Supervisión): documentos inmutables.
            'CONSTANCIAS_RETIRO' => ['Constancias de retiro', [Estado::EMITIDO, Estado::ANULADO]],
            'CONSTANCIAS_ESTADO' => ['Constancias de estado del turno', [Estado::EMITIDO, Estado::ANULADO]],
            // PDF clínico (triaje y consulta en curso o cerrada): se genera al vuelo; solo EXPORTAR. Ver docs/pdf-clinico.md.
            'PDF_CLINICO' => ['PDF clínico (triaje y consulta)', self::ACTIVO_INACTIVO],
            // Certificado de reposo médico: documento inmutable, nace emitido y solo se anula. Ver docs/reposo-medico.md.
            'REPOSO_MEDICO' => ['Certificados de reposo médico', [Estado::EMITIDO, Estado::ANULADO]],
            // Orden de estudio: documento inmutable, nace emitido y solo se anula. Ver docs/ordenes-estudio.md.
            'ORDENES_ESTUDIO' => ['Órdenes de estudio', [Estado::EMITIDO, Estado::ANULADO]],
            // Económico (docs/tarifas-prestaciones.md): tarifas versionadas (la abierta es VIGENTE) y la prestación de cada consulta.
            'TARIFAS' => ['Tarifas', [Estado::VIGENTE, Estado::HISTORICO]],
            'PRESTACIONES' => ['Prestaciones', [Estado::PENDIENTE, Estado::TARIFADO, Estado::ANULADO]],
            // Reparto (docs/reparto-economico.md): acuerdos versionados (el abierto es VIGENTE), el reparto de cada prestación
            // (pendiente, repartido o anulado) y los equipos con su catálogo de tipos.
            'ACUERDOS_REPARTO' => ['Acuerdos de reparto', [Estado::VIGENTE, Estado::HISTORICO]],
            'REPARTOS' => ['Repartos de prestaciones', [Estado::PENDIENTE, Estado::REPARTIDO, Estado::ANULADO]],
            'EQUIPOS' => ['Equipos', self::ACTIVO_INACTIVO],
            'TIPOS_EQUIPO' => ['Tipos de equipo', self::ACTIVO_INACTIVO],
            // Cobros y caja (docs/cobros-caja.md): la caja de cada cajero (abierta, cerrada, aprobada o devuelta), la aprobación de
            // sus cierres (pendiente, aprobado o devuelto) y los cobros de las prestaciones (vigentes; la reversión es de la fase 3b).
            'CAJAS' => ['Cajas', [Estado::ABIERTO, Estado::CERRADO, Estado::APROBADO, Estado::DEVUELTO]],
            'CIERRES_CAJA' => ['Aprobación de cierres de caja', [Estado::PENDIENTE, Estado::APROBADO, Estado::DEVUELTO]],
            'COBROS' => ['Cobros', [Estado::VIGENTE]],
            // Correcciones de una consulta cerrada: adendas, desbloqueos y versiones. Sensible; los estados son los del desbloqueo.
            'CORRECCIONES_HC' => ['Correcciones de la historia clínica', [Estado::VIGENTE, Estado::REALIZADO, Estado::VENCIDO, Estado::CANCELADO]],
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
