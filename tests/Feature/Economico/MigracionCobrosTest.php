<?php

/*
 * La migración de cobros y caja corre también contra un catálogo SIN sus datos: sin los estados nuevos, sin los módulos, sin
 * medios de pago, sin módulos en absoluto (instalación nueva) y en la simulación (`migrate --pretend`, que no devuelve filas). Es
 * idempotente, adopta los medios de pago existentes por el nombre, da los permisos solo a Caja, Gerencia y Facturación, y no
 * cobra las prestaciones que ya existían.
 */

use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Support\Cobros\Cobrar;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MIGRACION_COBROS_MODULOS = ['CAJAS', 'CIERRES_CAJA', 'COBROS'];

const MIGRACION_COBROS_TABLAS = ['cajas', 'cierres_caja', 'cierre_caja_medios', 'cobros', 'cobro_prestaciones', 'lineas_cobro', 'movimientos_caja'];

const MIGRACION_COBROS_ESTADOS_NUEVOS = [Estado::ABIERTO, Estado::CERRADO, Estado::DEVUELTO];

function migracionCobrosArchivo(): object
{
    return require base_path('database/migrations/2026_10_28_100001_create_cobros_y_caja.php');
}

/** Olvida los ids de estados que Estado guarda en memoria (si no, devolvería ids de filas ya borradas). */
function migracionCobrosOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(function () {
    migracionCobrosOlvidarIds();
    Carbon::setTestNow();
});

function migracionCobrosAcciones(string $codigoPerfil, string $modulo): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, "{$modulo}:"))
        ->map(fn (string $clave) => substr($clave, strlen("{$modulo}:")))->sort()->values()->all();
}

/** Base anterior a esta fase: sin las tablas, sin los módulos, sin los estados nuevos ni nada de lo que cuelga de ellos. */
function migracionCobrosSinNada(): void
{
    migracionCobrosArchivo()->down();
    foreach (ModuloSistema::whereIn('codigo', MIGRACION_COBROS_MODULOS)->get() as $modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
    $nuevos = Estado::whereIn('codigo', MIGRACION_COBROS_ESTADOS_NUEVOS)->pluck('id');
    DB::table('estado_modulo')->whereIn('estado_id', $nuevos)->delete();
    DB::table('estados')->whereIn('id', $nuevos)->delete();
    migracionCobrosOlvidarIds();
}

test('en una base sin los módulos, los estados ni los medios: los crea, arma las tablas vacías y da los permisos de la matriz', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionCobrosSinNada();
    expect(Schema::hasTable('cajas'))->toBeFalse()->and(ModuloSistema::whereIn('codigo', MIGRACION_COBROS_MODULOS)->exists())->toBeFalse()
        ->and(MedioPago::count())->toBe(0)->and(Estado::whereIn('codigo', MIGRACION_COBROS_ESTADOS_NUEVOS)->exists())->toBeFalse();

    migracionCobrosArchivo()->up();

    foreach (MIGRACION_COBROS_TABLAS as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue()->and(DB::table($tabla)->count())->toBe(0);
    }
    expect(Estado::whereIn('codigo', MIGRACION_COBROS_ESTADOS_NUEVOS)->count())->toBe(3)
        ->and(MedioPago::orderBy('id')->pluck('codigo', 'nombre')->all())->toBe(['Efectivo' => 'EFECTIVO', 'Transferencia' => 'TRANSFERENCIA', 'Tarjeta (POS)' => 'TARJETA'])
        ->and(MedioPago::efectivo()->estaActivo())->toBeTrue()
        ->and(ModuloSistema::where('codigo', 'CAJAS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ABIERTO', 'APROBADO', 'CERRADO', 'DEVUELTO'])
        ->and(ModuloSistema::where('codigo', 'CIERRES_CAJA')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['APROBADO', 'DEVUELTO', 'PENDIENTE'])
        ->and(ModuloSistema::where('codigo', 'COBROS')->sole()->estados()->pluck('codigo')->all())->toBe(['VIGENTE']);

    foreach (PerfilesPredefinidos::PERMISOS_COBROS_Y_CAJA as $modulo => $porPerfil) {
        foreach (array_keys(PerfilesPredefinidos::NOMBRES) as $perfil) {
            if ($perfil === PerfilAcceso::ADMINISTRADOR) {
                expect(migracionCobrosAcciones($perfil, $modulo))->not->toBe([]);

                continue;
            }
            $esperado = $porPerfil[$perfil] ?? [];
            sort($esperado);
            expect(migracionCobrosAcciones($perfil, $modulo))->toBe($esperado, "{$perfil} sobre {$modulo}");
        }
    }
    // Lo que suma la migración es lo mismo que la matriz de los perfiles que se creen en adelante.
    foreach (PerfilesPredefinidos::PERMISOS_COBROS_Y_CAJA as $modulo => $porPerfil) {
        foreach ($porPerfil as $perfil => $acciones) {
            expect(PerfilesPredefinidos::MATRIZ[$perfil][$modulo])->toBe($acciones);
        }
    }
    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA] as $perfil) {
        foreach (MIGRACION_COBROS_MODULOS as $modulo) {
            expect(PerfilesPredefinidos::MATRIZ[$perfil][$modulo] ?? null)->toBeNull();
        }
    }
});

test('adopta los medios de pago existentes por el nombre, sin duplicar ni cambiar su estado', function () {
    $this->seed(ModuloSistemaSeeder::class);
    migracionCobrosSinNada();
    $efectivo = MedioPago::create(['nombre' => 'EFECTIVO']);
    $efectivo->desactivar();
    $tarjeta = MedioPago::create(['nombre' => 'Tarjeta']);
    $cheque = MedioPago::create(['nombre' => 'Cheque']);

    migracionCobrosArchivo()->up();

    expect($efectivo->fresh()->codigo)->toBe(MedioPago::EFECTIVO)->and($efectivo->fresh()->nombre)->toBe('EFECTIVO')->and($efectivo->fresh()->estaActivo())->toBeFalse()
        ->and($tarjeta->fresh()->codigo)->toBe(MedioPago::TARJETA)
        ->and($cheque->fresh()->codigo)->toBeNull()
        ->and(MedioPago::count())->toBe(4)
        ->and(MedioPago::where('codigo', MedioPago::TRANSFERENCIA)->sole()->nombre)->toBe('Transferencia');
});

test('en una instalación nueva (sin módulos) crea las tablas y no toca el catálogo', function () {
    migracionCobrosArchivo()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionCobrosArchivo()->up();

    expect(Schema::hasTable('movimientos_caja'))->toBeTrue()->and(Schema::hasColumn('medios_pago', 'codigo'))->toBeTrue()
        ->and(ModuloSistema::count())->toBe(0)->and(MedioPago::count())->toBe(0);
});

test('con el catálogo ya completo no duplica estados, módulos, permisos ni medios', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionCobrosArchivo()->down();
    migracionCobrosArchivo()->up();
    migracionCobrosArchivo()->down();
    [$estados, $modulos, $permisos, $medios] = [Estado::count(), ModuloSistema::count(), Permiso::count(), MedioPago::count()];
    expect($medios)->toBe(3);

    migracionCobrosArchivo()->up();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count(), MedioPago::count()])->toBe([$estados, $modulos, $permisos, $medios]);
});

test('en simulación (--pretend) no lee filas ni cambia nada', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionCobrosSinNada();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    $sentencias = DB::pretend(fn () => migracionCobrosArchivo()->up());

    expect($sentencias)->not->toBeEmpty()
        ->and(Schema::hasTable('cajas'))->toBeFalse()->and(Schema::hasColumn('medios_pago', 'codigo'))->toBeFalse()
        ->and([Estado::count(), ModuloSistema::count(), Permiso::count(), MedioPago::count()])->toBe([$estados, $modulos, $permisos, 0]);
});

test('con prestaciones ya existentes: no las cobra y quedan pendientes de cobro', function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    $procedimiento = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    AltaTarifa::ejecutar($this->medico, $procedimiento->id, null, 100000, Carbon::parse('2026-10-01'));
    DB::table('hitos_sistema')->where('codigo', GenerarPrestacion::HITO)->update(['fecha_hora' => '2026-10-01 00:00:00']);
    hcConsulta();
    migracionCobrosSinNada();
    $antes = DB::table('prestaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all();
    expect($antes)->toHaveCount(1)->and($antes[0]['monto'])->not->toBeNull();

    migracionCobrosArchivo()->up();

    expect(DB::table('prestaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all())->toBe($antes)
        ->and(DB::table('cobros')->count())->toBe(0)->and(DB::table('cobro_prestaciones')->count())->toBe(0)
        ->and(Prestacion::sole()->estadoCobroTexto())->toBe(Prestacion::PENDIENTE_DE_COBRO)
        ->and(Cobrar::pendientes()->count())->toBe(1);
});
