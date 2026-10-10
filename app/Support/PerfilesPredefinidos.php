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
        self::MEDICO => 'Consulta, historia clínica, recetas y preparación',
        self::ENFERMERIA => 'Preparación de la consulta (anamnesis y signos vitales)',
        self::RECEPCION => 'Agenda, turnos, disponibilidades y alta de personas y pacientes',
        self::CAJA => 'Lectura de pacientes, medios de pago y procedimientos',
        self::FACTURACION => 'Lectura de responsables de pago, procedimientos y medios de pago',
        self::COMPRAS_TESORERIA => 'Proveedores y sus categorías, categorías de gasto',
        self::SUPERVISION_MEDICA => 'Lectura de historias clínicas, recetas, pacientes, profesionales y auditoría',
        self::AUDITOR => 'Lectura de la auditoría',
    ];

    /**
     * Perfiles existentes, sin código, que se adoptan como el predefinido (en vez de crear otro), además del
     * que tenga su nombre visible. Se adopta tal cual: sus permisos no cambian (se muestra la diferencia).
     */
    public const ADOPTA = [
        self::RECEPCION => ['Recepcionista'],
    ];

    private const VER = ['VER'];

    private const VER_CREAR_EDITAR = ['VER', 'CREAR', 'EDITAR'];

    private const TODO = ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'];

    /**
     * La matriz: solo acciones que tienen efecto hoy (ninguna EXPORTAR: no hay exportación; TURNOS no tiene
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
        ],
        // TURNOS: VER y EDITAR (para "No se presentó" y cerrar la jornada). Los catálogos que usa la consulta
        // (CIE-10, tipos de bloque y de indicación) se cargan sin permiso de su módulo: no hacen falta.
        self::MEDICO => [
            'HISTORIA_CLINICA' => self::VER_CREAR_EDITAR, 'RECETAS' => self::VER_CREAR_EDITAR, 'PREPARACION' => self::VER_CREAR_EDITAR,
            'TURNOS' => ['VER', 'EDITAR'], 'DISPONIBILIDAD' => self::VER, 'CONSULTORIOS' => self::VER, 'PACIENTES' => self::VER,
        ],
        self::ENFERMERIA => [
            'PREPARACION' => self::VER_CREAR_EDITAR,
        ],
        // Agenda y alta de personas y pacientes. Sus formularios (tipo de documento, país, departamento,
        // ciudad, sucursal) no piden permiso de esos catálogos: no se los da.
        self::RECEPCION => [
            'TURNOS' => self::VER_CREAR_EDITAR, 'DISPONIBILIDAD' => self::TODO,
            'CONSULTORIOS' => self::VER, 'ORIGENES_TURNO' => self::VER, 'PROFESIONALES' => self::VER, 'ESPECIALIDADES' => self::VER,
            'PROCEDIMIENTOS' => self::VER,
            'PACIENTES' => self::VER_CREAR_EDITAR, 'PERSONAS' => self::VER_CREAR_EDITAR, 'RESPONSABLES_PAGO' => self::VER_CREAR_EDITAR,
        ],
        // Caja, Facturación y Compras: sus módulos todavía no existen; solo lo que sirve hoy. Nada clínico.
        self::CAJA => [
            'PACIENTES' => self::VER, 'MEDIOS_PAGO' => self::VER, 'PROCEDIMIENTOS' => self::VER,
        ],
        self::FACTURACION => [
            'RESPONSABLES_PAGO' => self::VER, 'PROCEDIMIENTOS' => self::VER, 'MEDIOS_PAGO' => self::VER,
        ],
        self::COMPRAS_TESORERIA => [
            'PROVEEDORES' => self::TODO, 'CATEGORIAS_PROVEEDOR' => self::TODO, 'CATEGORIAS_GASTO' => self::VER,
        ],
        // El permiso de desbloqueo de una consulta cerrada llega con la etapa de adenda.
        self::SUPERVISION_MEDICA => [
            'HISTORIA_CLINICA' => self::VER, 'RECETAS' => self::VER, 'PACIENTES' => self::VER, 'PROFESIONALES' => self::VER,
            'AUDITORIA' => self::VER,
        ],
        self::AUDITOR => [
            'AUDITORIA' => self::VER,
        ],
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
                    // Se le pone el código (y el nombre visible, si está libre); los permisos quedan como están.
                    $nombreLibre = ! PerfilAcceso::where('nombre', self::NOMBRES[$codigo])->whereKeyNot($perfil->id)->exists();
                    $perfil->update(['codigo' => $codigo, 'predefinido' => true, ...($nombreLibre ? ['nombre' => self::NOMBRES[$codigo]] : [])]);
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
