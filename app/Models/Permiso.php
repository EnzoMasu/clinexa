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
        // Cajas: ver, abrir (CREAR) y cerrar (EDITAR) la propia. La aprobación de cierres: ver todas las cajas y aprobar o devolver
        // (EDITAR). Cobros: ver, cobrar (CREAR) y revertir o solicitar la reversión (DESACTIVAR); un cobro no se edita. La
        // aprobación de reversiones: ver las solicitudes y aprobarlas o rechazarlas (EDITAR).
        'CAJAS' => ['VER', 'CREAR', 'EDITAR'],
        'CIERRES_CAJA' => ['VER', 'EDITAR'],
        'COBROS' => ['VER', 'CREAR', 'DESACTIVAR'],
        'REVERSIONES_COBRO' => ['VER', 'EDITAR'],
        // Convenios (docs/convenios-seguros.md): convenios, planes y tarifas del convenio (alta, cambio y baja; nada se borra).
        // Coberturas: ver, cargar (CREAR, también la que reemplaza a la vigente) y dar de baja (EDITAR). Visaciones: todas (EDITAR =
        // aplicar una visación y la firma recibida; DESACTIVAR = anular; EXPORTAR = el ticket).
        'CONVENIOS' => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'],
        'COBERTURAS' => ['VER', 'CREAR', 'EDITAR'],
        // Liquidaciones a aseguradoras (docs/liquidaciones-aseguradoras.md): ver, armar (CREAR), quitar líneas y enviar a aprobación
        // (EDITAR), anular (DESACTIVAR) y la planilla (EXPORTAR). La aprobación: ver y aprobar o devolver (EDITAR). Los pagos de la
        // aseguradora: ver, registrar (CREAR) y anular (DESACTIVAR); un pago no se edita.
        'APROBACION_LIQUIDACIONES' => ['VER', 'EDITAR'],
        'PAGOS_ASEGURADORAS' => ['VER', 'CREAR', 'DESACTIVAR'],
        // Facturación (docs/facturacion.md): facturas (ver, emitir al cobrar = CREAR, anular o solicitar la anulación = DESACTIVAR, el
        // PDF = EXPORTAR; una factura no se edita), la aprobación de anulaciones (ver y aprobar o rechazar = EDITAR), los timbrados
        // (ver, cargar = CREAR, pasar a histórico = EDITAR), los datos fiscales (la clínica y las tasas de IVA: ver y EDITAR) y el
        // catálogo de motivos.
        'FACTURAS' => ['VER', 'CREAR', 'DESACTIVAR', 'EXPORTAR'],
        'ANULACIONES_FACTURA' => ['VER', 'EDITAR'],
        'TIMBRADOS' => ['VER', 'CREAR', 'EDITAR'],
        'DATOS_FISCALES' => ['VER', 'EDITAR'],
        'MOTIVOS_ANULACION' => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'],
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
