<?php

namespace App\Support;

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use Illuminate\Support\Facades\DB;

/**
 * Los perfiles predefinidos de la clínica y su matriz inicial (perfil => módulo => acciones): es el ÚNICO
 * lugar donde está. Cada perfil se identifica por su código (perfiles_acceso.codigo), no por el nombre: se
 * puede renombrar desde la pantalla sin que el seeder cree un duplicado.
 *
 * - asegurar() (PerfilesPredefinidosSeeder) crea los que falten con su matriz, o adopta uno existente sin
 *   código con el mismo nombre (o uno de ADOPTA). NUNCA cambia los permisos de un perfil que ya existe: lo
 *   que se cambió desde la pantalla queda.
 * - Un módulo NUEVO se suma a los perfiles que corresponda desde la migración (o el seeder) que lo crea, una
 *   sola vez, con sumarModulo(). No alcanza con agregarlo acá: la matriz solo se aplica al crear un perfil.
 * - ADMINISTRADOR no está en la matriz: tiene siempre todo (PerfilAcceso::asegurarAdministrador).
 *
 * Ver docs/perfiles.md.
 */
class PerfilesPredefinidos
{
    public const ADMINISTRADOR = 'ADMINISTRADOR';

    public const GERENCIA = 'GERENCIA';

    public const MEDICO = 'MEDICO';

    public const ENFERMERIA = 'ENFERMERIA';

    public const RECEPCION = 'RECEPCION';

    public const CAJA = 'CAJA';

    public const FACTURACION = 'FACTURACION';

    public const COMPRAS_TESORERIA = 'COMPRAS_TESORERIA';

    public const SUPERVISION_MEDICA = 'SUPERVISION_MEDICA';

    public const AUDITOR = 'AUDITOR';

    /** Nombre visible con el que se crea cada perfil (después se puede renombrar). */
    public const NOMBRES = [
        self::ADMINISTRADOR => 'Administrador',
        self::GERENCIA => 'Gerencia',
        self::MEDICO => 'Médico',
        self::ENFERMERIA => 'Enfermería',
        self::RECEPCION => 'Recepción',
        self::CAJA => 'Caja',
        self::FACTURACION => 'Facturación y convenios',
        self::COMPRAS_TESORERIA => 'Compras y tesorería',
        self::SUPERVISION_MEDICA => 'Supervisión médica',
        self::AUDITOR => 'Auditor',
    ];

    public const DESCRIPCIONES = [
        self::ADMINISTRADOR => 'Acceso total al sistema',
        self::GERENCIA => 'Lectura de lo operativo y de la auditoría, sin contenido clínico',
        self::MEDICO => 'Consulta, historia clínica, recetas y triaje',
        self::ENFERMERIA => 'Triaje (anamnesis y signos vitales)',
        self::RECEPCION => 'Agenda, turnos, disponibilidades y alta de personas y pacientes',
        self::CAJA => 'Lectura de pacientes, medios de pago y procedimientos',
        self::FACTURACION => 'Convenios con aseguradoras, visaciones y lectura de responsables de pago, procedimientos y medios de pago',
        self::COMPRAS_TESORERIA => 'Proveedores y sus categorías, categorías de gasto',
        self::SUPERVISION_MEDICA => 'Lectura de historias clínicas, recetas, pacientes, profesionales y auditoría',
        self::AUDITOR => 'Lectura de la auditoría',
    ];

    /**
     * Perfiles existentes, sin código, que se adoptan como el predefinido (en vez de crear otro), además del
     * que tenga su nombre visible. Al adoptarlo se le pone el código y el nombre visible; sus permisos quedan
     * como están, salvo en los de COMPLETA_AL_ADOPTAR.
     */
    public const ADOPTA = [
        self::ENFERMERIA => ['Enfermera'],
        self::RECEPCION => ['Recepcionista'],
    ];

    /**
     * Al adoptarlos, se les SUMA lo que les falte de la matriz (nunca se les quita nada). Pasa una sola vez:
     * después de adoptado el perfil tiene código y no se vuelve a adoptar, así que el seeder no repite ni pisa
     * lo que se cambie desde la pantalla.
     */
    public const COMPLETA_AL_ADOPTAR = [self::RECEPCION];

    private const VER = ['VER'];

    private const EXPORTAR = ['EXPORTAR'];

    private const VER_CREAR_EDITAR = ['VER', 'CREAR', 'EDITAR'];

    private const TODO = ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'];

    /** Visaciones: VER, CREAR (cargar), EDITAR (aplicar y firma recibida), DESACTIVAR (anular) y EXPORTAR (el ticket). */
    private const VISACIONES_TODO = ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR', 'EXPORTAR'];

    /** Liquidaciones: VER, CREAR (armar), EDITAR (quitar líneas, enviar), DESACTIVAR (anular) y EXPORTAR (la planilla). */
    private const LIQUIDACIONES_TODO = ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR', 'EXPORTAR'];

    /**
     * La matriz: solo acciones que tienen efecto hoy (EXPORTAR solo en PDF_CLINICO, en VISACIONES, el ticket, y en LIQUIDACIONES, la planilla; TURNOS no tiene
     * DESACTIVAR: un turno se cancela, con EDITAR). Ningún perfil, salvo ADMINISTRADOR, escribe en USUARIOS
     * ni en PERFILES_ACCESO (lo verifica PerfilesPredefinidosTest).
     */
    public const MATRIZ = [
        // Solo lectura de lo operativo y de la auditoría. Sin historia clínica, recetas, preparación,
        // pacientes, personas, usuarios ni perfiles. Ve la auditoría sin el contenido clínico (no tiene VER de
        // HISTORIA_CLINICA ni de RECETAS).
        self::GERENCIA => [
            'TURNOS' => self::VER, 'DISPONIBILIDAD' => self::VER, 'CONSULTORIOS' => self::VER, 'ORIGENES_TURNO' => self::VER,
            'PROFESIONALES' => self::VER, 'PROVEEDORES' => self::VER, 'CATEGORIAS_PROVEEDOR' => self::VER, 'TIPOS_RED_SOCIAL' => self::VER,
            'ESPECIALIDADES' => self::VER, 'SUCURSALES' => self::VER, 'TIPOS_DOCUMENTO' => self::VER, 'PROCEDIMIENTOS' => self::VER,
            'MEDIOS_PAGO' => self::VER, 'CATEGORIAS_GASTO' => self::VER, 'CIE10' => self::VER, 'TIPOS_BLOQUE_ANAMNESIS' => self::VER,
            'TIPOS_INDICACION' => self::VER, 'GEOGRAFIA' => self::VER, 'AUDITORIA' => self::VER,
            // Triaje (2026-10-13): ver el personal de salud, sus cargos y los parámetros clínicos.
            'PERSONAL_SALUD' => self::VER, 'CARGOS_SALUD' => self::VER, 'PARAMETROS_CLINICOS' => self::VER,
            // Económico: administra las tarifas, tarifa y anula prestaciones.
            'TARIFAS' => self::VER_CREAR_EDITAR, 'PRESTACIONES' => ['VER', 'EDITAR', 'DESACTIVAR'],
            // Reparto (docs/reparto-economico.md): administra los acuerdos, los equipos y sus tipos, y ve los repartos.
            'ACUERDOS_REPARTO' => self::VER_CREAR_EDITAR, 'REPARTOS' => self::VER, 'EQUIPOS' => self::TODO, 'TIPOS_EQUIPO' => self::TODO,
            // Cobros y caja (docs/cobros-caja.md): ve los cobros y todas las cajas, y aprueba o devuelve los cierres. No cobra.
            'COBROS' => self::VER, 'CAJAS' => self::VER, 'CIERRES_CAJA' => ['VER', 'EDITAR'],
            // Reversiones de cobro: aprueba o rechaza las solicitudes. No revierte (no tiene caja).
            'REVERSIONES_COBRO' => ['VER', 'EDITAR'],
            // Convenios (docs/convenios-seguros.md): administra convenios, planes y sus tarifas; ve coberturas y visaciones y genera el ticket.
            'CONVENIOS' => self::TODO, 'COBERTURAS' => self::VER, 'VISACIONES' => ['VER', 'EXPORTAR'],
            // Liquidaciones (docs/liquidaciones-aseguradoras.md): las ve todas, saca la planilla, aprueba o devuelve y anula; ve los
            // pagos. No arma ni registra pagos.
            'LIQUIDACIONES' => ['VER', 'DESACTIVAR', 'EXPORTAR'], 'APROBACION_LIQUIDACIONES' => ['VER', 'EDITAR'], 'PAGOS_ASEGURADORAS' => self::VER,
        ],
        // TURNOS: solo VER. "No se presentó", "Pasar a ausente" y "Cerrar jornada" de la pantalla Consulta se
        // autorizan con CREAR sobre HISTORIA_CLINICA y solo para sus propios turnos (ConsultaPolicy); los botones
        // de la pantalla Turnos (EDITAR sobre TURNOS) no los tiene. Los catálogos que usa la consulta (CIE-10,
        // tipos de bloque y de indicación) se cargan sin permiso de su módulo: no hacen falta.
        self::MEDICO => [
            'HISTORIA_CLINICA' => self::VER_CREAR_EDITAR, 'RECETAS' => self::VER_CREAR_EDITAR, 'PREPARACION' => self::VER_CREAR_EDITAR,
            'TURNOS' => self::VER, 'DISPONIBILIDAD' => self::VER, 'CONSULTORIOS' => self::VER, 'PACIENTES' => self::VER,
            'PARAMETROS_CLINICOS' => self::VER, 'CONSTANCIAS_ESTADO' => self::VER_CREAR_EDITAR,
            'PDF_CLINICO' => self::EXPORTAR, 'REPOSO_MEDICO' => self::VER_CREAR_EDITAR,
            // Dietas: puede listarlas y usarlas en la indicación; no las administra (eso es de Supervisión médica).
            'DIETAS' => self::VER,
            // Órdenes de estudio: las emite y anula; el catálogo de estudios solo lo usa.
            'ORDENES_ESTUDIO' => self::VER_CREAR_EDITAR, 'ESTUDIOS' => self::VER,
        ],
        self::ENFERMERIA => [
            'PREPARACION' => self::VER_CREAR_EDITAR, 'PDF_CLINICO' => self::EXPORTAR,
        ],
        // Agenda y alta de personas y pacientes. Sus formularios (tipo de documento, país, departamento,
        // ciudad, sucursal) no piden permiso de esos catálogos: no se los da.
        self::RECEPCION => [
            'TURNOS' => self::VER_CREAR_EDITAR, 'DISPONIBILIDAD' => self::TODO,
            'CONSULTORIOS' => self::VER, 'ORIGENES_TURNO' => self::VER, 'PROFESIONALES' => self::VER, 'ESPECIALIDADES' => self::VER,
            'PROCEDIMIENTOS' => self::VER,
            'PACIENTES' => self::VER_CREAR_EDITAR, 'PERSONAS' => self::VER_CREAR_EDITAR, 'RESPONSABLES_PAGO' => self::VER_CREAR_EDITAR,
            // Constancia de retiro: sin contenido clínico (se suma por la migración de las constancias).
            'CONSTANCIAS_RETIRO' => self::VER_CREAR_EDITAR,
            // Económico: solo ver tarifas y prestaciones.
            'TARIFAS' => self::VER, 'PRESTACIONES' => self::VER,
            // Convenios (docs/convenios-seguros.md): carga la cobertura y las visaciones, aplica visaciones, marca la firma y genera el ticket.
            'COBERTURAS' => self::VER_CREAR_EDITAR, 'VISACIONES' => self::VISACIONES_TODO,
        ],
        // Caja: abre y cierra su caja, cobra y revierte (o solicita revertir) un cobro (docs/cobros-caja.md). Facturación ve los cobros. Compras: sus módulos todavía no
        // existen. Nada clínico.
        self::CAJA => [
            'PACIENTES' => self::VER, 'MEDIOS_PAGO' => self::VER, 'PROCEDIMIENTOS' => self::VER,
            'TARIFAS' => self::VER, 'PRESTACIONES' => self::VER,
            'CAJAS' => self::VER_CREAR_EDITAR, 'COBROS' => ['VER', 'CREAR', 'DESACTIVAR'],
        ],
        self::FACTURACION => [
            'RESPONSABLES_PAGO' => self::VER, 'PROCEDIMIENTOS' => self::VER, 'MEDIOS_PAGO' => self::VER,
            'TARIFAS' => self::VER, 'PRESTACIONES' => self::VER,
            'COBROS' => self::VER,
            // Convenios (docs/convenios-seguros.md): los administra, y también carga coberturas y visaciones, la firma y el ticket.
            'CONVENIOS' => self::TODO, 'COBERTURAS' => self::VER_CREAR_EDITAR, 'VISACIONES' => self::VISACIONES_TODO,
            // Liquidaciones (docs/liquidaciones-aseguradoras.md): arma, quita líneas, envía a aprobación, anula la que no está aprobada,
            // saca la planilla y registra y anula los pagos de la aseguradora. No aprueba.
            'LIQUIDACIONES' => self::LIQUIDACIONES_TODO, 'PAGOS_ASEGURADORAS' => ['VER', 'CREAR', 'DESACTIVAR'],
        ],
        self::COMPRAS_TESORERIA => [
            'PROVEEDORES' => self::TODO, 'CATEGORIAS_PROVEEDOR' => self::TODO, 'CATEGORIAS_GASTO' => self::VER,
        ],
        // Además, CORRECCIONES_HC (adendas y desbloqueo de consultas cerradas): se suma por la migración de las correcciones.
        self::SUPERVISION_MEDICA => [
            'HISTORIA_CLINICA' => self::VER, 'RECETAS' => self::VER, 'PACIENTES' => self::VER, 'PROFESIONALES' => self::VER,
            'AUDITORIA' => self::VER, 'CORRECCIONES_HC' => self::VER_CREAR_EDITAR, 'CONSTANCIAS_ESTADO' => self::VER_CREAR_EDITAR,
            // Triaje: administra el personal de salud, ve los cargos y define y aprueba los parámetros clínicos.
            'PERSONAL_SALUD' => self::VER_CREAR_EDITAR, 'CARGOS_SALUD' => self::VER, 'PARAMETROS_CLINICOS' => ['VER', 'EDITAR'],
            'PDF_CLINICO' => self::EXPORTAR,
            // Reposo médico: solo ver y sacar el PDF (no emite ni anula).
            'REPOSO_MEDICO' => self::VER,
            // Catálogo de dietas: lo administra (el Médico solo las ve y usa: VER).
            'DIETAS' => self::TODO,
            // Órdenes de estudio: solo ver y sacar el PDF (no emite ni anula). Catálogo de estudios: lo administra.
            'ORDENES_ESTUDIO' => self::VER, 'ESTUDIOS' => self::TODO,
        ],
        self::AUDITOR => [
            'AUDITORIA' => self::VER,
        ],
    ];

    /**
     * Lo que la migración de cobros y caja (2026_10_28_100001) suma a los perfiles existentes: módulo => perfil => acciones. Lo
     * mismo que la matriz para esos tres módulos. Recepción, Médico, Enfermería y los demás, nada.
     */
    public const PERMISOS_COBROS_Y_CAJA = [
        'CAJAS' => [self::CAJA => self::VER_CREAR_EDITAR, self::GERENCIA => self::VER],
        'CIERRES_CAJA' => [self::GERENCIA => ['VER', 'EDITAR']],
        'COBROS' => [self::CAJA => ['VER', 'CREAR'], self::GERENCIA => self::VER, self::FACTURACION => self::VER],
    ];

    /**
     * Lo que la migración de reversiones de cobro (2026_10_29_100001) suma a los perfiles existentes: Caja revierte y solicita
     * (DESACTIVAR sobre COBROS) y Gerencia aprueba o rechaza (REVERSIONES_COBRO). Lo mismo que la matriz. Los demás, nada.
     */
    public const PERMISOS_REVERSIONES_COBRO = [
        'COBROS' => [self::CAJA => ['DESACTIVAR']],
        'REVERSIONES_COBRO' => [self::GERENCIA => ['VER', 'EDITAR']],
    ];

    /**
     * Lo que la migración de convenios y visaciones (2026_10_30_100001) suma a los perfiles existentes. Lo mismo que la matriz para
     * esos tres módulos. Caja cobra la parte de la paciente sin módulo nuevo; Médico, Enfermería y los demás, nada.
     */
    public const PERMISOS_CONVENIOS = [
        'CONVENIOS' => [self::GERENCIA => self::TODO, self::FACTURACION => self::TODO],
        'COBERTURAS' => [self::GERENCIA => self::VER, self::RECEPCION => self::VER_CREAR_EDITAR, self::FACTURACION => self::VER_CREAR_EDITAR],
        'VISACIONES' => [self::GERENCIA => ['VER', 'EXPORTAR'], self::RECEPCION => self::VISACIONES_TODO, self::FACTURACION => self::VISACIONES_TODO],
    ];

    /**
     * Lo que la migración de liquidaciones (2026_10_31_100001) suma a los perfiles existentes. Lo mismo que la matriz para esos tres
     * módulos. Recepción, Caja, Médico, Enfermería, Compras y los demás, nada.
     */
    public const PERMISOS_LIQUIDACIONES = [
        'LIQUIDACIONES' => [self::GERENCIA => ['VER', 'DESACTIVAR', 'EXPORTAR'], self::FACTURACION => self::LIQUIDACIONES_TODO],
        'APROBACION_LIQUIDACIONES' => [self::GERENCIA => ['VER', 'EDITAR']],
        'PAGOS_ASEGURADORAS' => [self::GERENCIA => self::VER, self::FACTURACION => ['VER', 'CREAR', 'DESACTIVAR']],
    ];

    /**
     * Qué haría asegurar(), sin escribir nada: por código, 'existe' (con su perfil), 'adoptar' (el perfil
     * existente que se adopta) o 'crear'. Lo usa la simulación de solo lectura.
     *
     * @return array<string, array{accion: string, perfil: ?PerfilAcceso}>
     */
    public static function plan(): array
    {
        $plan = [];
        foreach (array_keys(self::NOMBRES) as $codigo) {
            $existente = PerfilAcceso::where('codigo', $codigo)->first();
            $adoptable = $existente ? null : self::adoptable($codigo);
            $plan[$codigo] = match (true) {
                $existente !== null => ['accion' => 'existe', 'perfil' => $existente],
                $adoptable !== null => ['accion' => 'adoptar', 'perfil' => $adoptable],
                default => ['accion' => 'crear', 'perfil' => null],
            };
        }

        return $plan;
    }

    /**
     * Crea los predefinidos que falten (con su matriz) o adopta el existente. Idempotente; nunca cambia los
     * permisos de un perfil que ya existe. Devuelve el plan que aplicó.
     */
    public static function asegurar(): array
    {
        $plan = self::plan();

        DB::transaction(function () use ($plan) {
            PerfilAcceso::asegurarAdministrador();

            foreach ($plan as $codigo => ['accion' => $accion, 'perfil' => $perfil]) {
                if ($codigo === self::ADMINISTRADOR) {
                    continue;
                }
                if ($accion === 'adoptar') {
                    // Se le pone el código (y el nombre visible, si está libre). Los permisos quedan como están,
                    // salvo los de COMPLETA_AL_ADOPTAR, que suman lo que les falte de la matriz.
                    $nombreLibre = ! PerfilAcceso::where('nombre', self::NOMBRES[$codigo])->whereKeyNot($perfil->id)->exists();
                    $perfil->update(['codigo' => $codigo, 'predefinido' => true, ...($nombreLibre ? ['nombre' => self::NOMBRES[$codigo]] : [])]);
                    if (in_array($codigo, self::COMPLETA_AL_ADOPTAR, true)) {
                        $perfil->permisos()->syncWithoutDetaching(self::permisoIds(self::MATRIZ[$codigo]));
                    }
                } elseif ($accion === 'crear') {
                    // Si otro perfil (con código) ya usa el nombre visible, se crea con "(predefinido)".
                    $nombre = PerfilAcceso::where('nombre', self::NOMBRES[$codigo])->exists() ? self::NOMBRES[$codigo].' (predefinido)' : self::NOMBRES[$codigo];
                    $nuevo = PerfilAcceso::create(['codigo' => $codigo, 'predefinido' => true, 'nombre' => $nombre, 'descripcion' => self::DESCRIPCIONES[$codigo]]);
                    $nuevo->permisos()->attach(self::permisoIds(self::MATRIZ[$codigo]));
                }
            }
        });

        return $plan;
    }

    /**
     * Para la migración (o el seeder) que introduce un módulo nuevo: le da a cada perfil predefinido
     * existente las acciones indicadas sobre ese módulo, como ['MEDICO' => ['VER'], 'GERENCIA' => ['VER']].
     * Solo suma (no quita nada) y se corre una vez, al crear el módulo. El Administrador lo recibe solo.
     */
    public static function sumarModulo(string $modulo, array $accionesPorPerfil): void
    {
        foreach ($accionesPorPerfil as $codigo => $acciones) {
            PerfilAcceso::where('codigo', $codigo)->first()?->permisos()->syncWithoutDetaching(self::permisoIds([$modulo => $acciones]));
        }
        PerfilAcceso::asegurarAdministrador();
    }

    /**
     * Diferencia de los permisos de un perfil contra la matriz de un código: 'sobran' (los tiene y la
     * matriz no) y 'faltan' (la matriz los tiene y el perfil no), como "MÓDULO: ACCIÓN".
     *
     * @return array{sobran: list<string>, faltan: list<string>}
     */
    public static function diferencia(PerfilAcceso $perfil, string $codigo): array
    {
        $tiene = $perfil->permisos()->with('moduloSistema')->get()
            ->map(fn (Permiso $permiso) => "{$permiso->moduloSistema->codigo}: {$permiso->accion}")->all();
        $matriz = collect(self::MATRIZ[$codigo] ?? [])->flatMap(fn (array $acciones, string $modulo) => array_map(fn ($accion) => "{$modulo}: {$accion}", $acciones))->all();

        return [
            'sobran' => collect($tiene)->diff($matriz)->sort()->values()->all(),
            'faltan' => collect($matriz)->diff($tiene)->sort()->values()->all(),
        ];
    }

    /** El perfil sin código que se adoptaría para ese código, o null. */
    private static function adoptable(string $codigo): ?PerfilAcceso
    {
        return PerfilAcceso::whereNull('codigo')
            ->whereIn('nombre', [self::NOMBRES[$codigo], ...(self::ADOPTA[$codigo] ?? [])])
            ->orderBy('id')->first();
    }

    /** Ids de permisos de [módulo => acciones], creando las filas que falten. Un módulo inexistente es un error. */
    private static function permisoIds(array $matriz): array
    {
        return collect($matriz)->flatMap(function (array $acciones, string $codigoModulo) {
            $modulo = ModuloSistema::where('codigo', $codigoModulo)->sole();

            return array_map(fn (string $accion) => Permiso::firstOrCreate(['modulo_sistema_id' => $modulo->id, 'accion' => $accion])->id, $acciones);
        })->all();
    }
}
