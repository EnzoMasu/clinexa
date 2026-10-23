<?php

/*
 * La migración de convenios y visaciones corre también contra un catálogo SIN sus datos: sin los módulos ni los permisos nuevos, sin
 * módulos en absoluto (instalación nueva) y en la simulación (`migrate --pretend`, que no devuelve filas). Es idempotente, da los
 * permisos de la matriz y, con prestaciones y cobros ya existentes, no recalcula ni cambia nada: quedan particulares (sin snapshot).
 * Prefijo "migracionConvenios".
 */

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\User;
use App\Support\Cobros\Cobrar;
use App\Support\Convenios\AplicarVisacion;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MIGRACION_CONVENIOS_MODULOS = ['CONVENIOS', 'COBERTURAS', 'VISACIONES'];

function migracionConveniosArchivo(): object
{
    return require base_path('database/migrations/2026_10_30_100001_create_convenios_y_visaciones.php');
}

function migracionConveniosOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(function () {
    migracionConveniosOlvidarIds();
    Carbon::setTestNow();
});

function migracionConveniosAcciones(string $codigoPerfil, string $modulo): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, "{$modulo}:"))
        ->map(fn (string $clave) => substr($clave, strlen("{$modulo}:")))->sort()->values()->all();
}

/** Base anterior a esta fase: sin el esquema, sin los tres módulos ni sus permisos. */
function migracionConveniosSinNada(): void
{
    // SQLite rehace `prestaciones` al quitarle columnas; las claves foráneas se verifican al final (la transacción del test se deshace).
    DB::statement('PRAGMA defer_foreign_keys = ON');
    migracionConveniosArchivo()->down();
    foreach (ModuloSistema::whereIn('codigo', MIGRACION_CONVENIOS_MODULOS)->get() as $modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
    migracionConveniosOlvidarIds();
}

test('en una base sin los módulos ni los permisos: arma el esquema vacío, crea los módulos y da los permisos de la matriz', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionConveniosSinNada();
    expect(Schema::hasTable('convenios'))->toBeFalse()->and(Schema::hasTable('visaciones'))->toBeFalse()
        ->and(Schema::hasColumn('prestaciones', 'pagador'))->toBeFalse()
        ->and(ModuloSistema::whereIn('codigo', MIGRACION_CONVENIOS_MODULOS)->exists())->toBeFalse();

    migracionConveniosArchivo()->up();

    foreach (['convenios', 'planes_convenio', 'tarifas_convenio', 'coberturas_paciente', 'visaciones', 'lineas_visacion'] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue()->and(DB::table($tabla)->count())->toBe(0, $tabla);
    }
    expect(Schema::hasColumns('prestaciones', ['pagador', 'monto_paciente', 'monto_aseguradora', 'liquidacion', 'firma_recibida_en', 'visacion_aplicada_en']))->toBeTrue()
        ->and(DB::table('hitos_sistema')->where('codigo', 'CONVENIOS')->count())->toBe(1)
        ->and(ModuloSistema::where('codigo', 'VISACIONES')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'VIGENTE'])
        ->and(ModuloSistema::where('codigo', 'CONVENIOS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['HISTORICO', 'VIGENTE']);

    expect(migracionConveniosAcciones(PerfilesPredefinidos::FACTURACION, 'CONVENIOS'))->toBe(['CREAR', 'DESACTIVAR', 'EDITAR', 'VER'])
        ->and(migracionConveniosAcciones(PerfilesPredefinidos::GERENCIA, 'CONVENIOS'))->toBe(['CREAR', 'DESACTIVAR', 'EDITAR', 'VER'])
        ->and(migracionConveniosAcciones(PerfilesPredefinidos::RECEPCION, 'COBERTURAS'))->toBe(['CREAR', 'EDITAR', 'VER'])
        ->and(migracionConveniosAcciones(PerfilesPredefinidos::RECEPCION, 'VISACIONES'))->toBe(['CREAR', 'DESACTIVAR', 'EDITAR', 'EXPORTAR', 'VER'])
        ->and(migracionConveniosAcciones(PerfilesPredefinidos::RECEPCION, 'CONVENIOS'))->toBe([])
        ->and(migracionConveniosAcciones(PerfilesPredefinidos::GERENCIA, 'VISACIONES'))->toBe(['EXPORTAR', 'VER'])
        ->and(migracionConveniosAcciones(PerfilAcceso::ADMINISTRADOR, 'VISACIONES'))->not->toBe([]);
    foreach ([PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::AUDITOR, PerfilesPredefinidos::SUPERVISION_MEDICA] as $perfil) {
        foreach (MIGRACION_CONVENIOS_MODULOS as $modulo) {
            expect(migracionConveniosAcciones($perfil, $modulo))->toBe([], "{$perfil} {$modulo}");
        }
    }
    // Lo que suma la migración es lo mismo que la matriz de los perfiles que se creen en adelante.
    foreach (PerfilesPredefinidos::PERMISOS_CONVENIOS as $modulo => $porPerfil) {
        foreach ($porPerfil as $perfil => $acciones) {
            expect($acciones)->toBe(PerfilesPredefinidos::MATRIZ[$perfil][$modulo]);
        }
    }
});

test('en una instalación nueva (sin módulos) arma el esquema y no toca el catálogo', function () {
    migracionConveniosArchivo()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionConveniosArchivo()->up();

    expect(Schema::hasTable('visaciones'))->toBeTrue()->and(Schema::hasColumn('prestaciones', 'monto_paciente'))->toBeTrue()->and(ModuloSistema::count())->toBe(0);
});

test('con el catálogo ya completo no duplica estados, módulos ni permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionConveniosArchivo()->down();
    migracionConveniosArchivo()->up();
    [$estados, $modulos, $permisos, $asignados] = [Estado::count(), ModuloSistema::count(), Permiso::count(), DB::table('perfil_permiso')->count()];

    migracionConveniosArchivo()->down();
    migracionConveniosArchivo()->up();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count(), DB::table('perfil_permiso')->count()])->toBe([$estados, $modulos, $permisos, $asignados]);
});

test('en simulación (--pretend) no lee filas ni cambia nada', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionConveniosSinNada();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    $sentencias = DB::pretend(fn () => migracionConveniosArchivo()->up());

    expect($sentencias)->not->toBeEmpty()
        ->and(Schema::hasTable('convenios'))->toBeFalse()->and(Schema::hasColumn('prestaciones', 'pagador'))->toBeFalse()
        ->and(DB::table('hitos_sistema')->where('codigo', 'CONVENIOS')->exists())->toBeFalse()
        ->and([Estado::count(), ModuloSistema::count(), Permiso::count()])->toBe([$estados, $modulos, $permisos]);
});

test('con prestaciones, repartos y cobros ya existentes: no recalcula nada, quedan particulares y se cobran igual', function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $procedimiento = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    AltaTarifa::ejecutar($this->medico, $procedimiento->id, null, 100000, Carbon::parse('2026-10-01'));
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    hcConsulta();
    $cobrada = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $this->actingAs($this->medico);
    hcConsulta();
    $sinCobrar = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $cajero = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole()])->create();
    $this->actingAs($cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
    $this->post(route('admin.cobros.store'), ['paciente_id' => $this->paciente->id, 'prestaciones' => [$cobrada->id],
        'lineas' => [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => '100000']]])->assertSessionHas('status');

    migracionConveniosSinNada();
    $columnas = ['id', 'consulta_id', 'paciente_id', 'procedimiento_id', 'fecha_servicio', 'monto', 'moneda_id', 'tarifa_id', 'estado_id', 'tarifada_en'];
    $foto = fn () => [
        DB::table('prestaciones')->orderBy('id')->get($columnas)->map(fn ($fila) => (array) $fila)->all(),
        DB::table('repartos_prestacion')->orderBy('id')->get(['id', 'prestacion_id', 'estado_id', 'monto_base'])->map(fn ($fila) => (array) $fila)->all(),
        DB::table('cobros')->orderBy('id')->get(['id', 'total', 'estado_id'])->map(fn ($fila) => (array) $fila)->all(),
        DB::table('cobro_prestaciones')->orderBy('id')->get(['id', 'prestacion_id', 'monto', 'revertido_en'])->map(fn ($fila) => (array) $fila)->all(),
    ];
    $antes = $foto();
    expect($antes[0])->toHaveCount(2)->and($antes[2])->toHaveCount(1);

    Carbon::setTestNow(Carbon::parse('2026-10-06 13:00:00', 'UTC'));
    migracionConveniosArchivo()->up();
    migracionConveniosOlvidarIds();

    expect($foto())->toBe($antes)
        ->and(DB::table('prestaciones')->whereNotNull('pagador')->orWhereNotNull('monto_paciente')->orWhereNotNull('monto_aseguradora')->count())->toBe(0)
        ->and($cobrada->fresh()->estadoCobroTexto())->toBe(Prestacion::COBRADA)
        // La no cobrada sigue en la caja por su monto (es particular).
        ->and(Cobrar::pendientes()->pluck('prestaciones.id')->all())->toBe([$sinCobrar->id])
        ->and($sinCobrar->fresh()->montoPaciente())->toBe(100000)->and($sinCobrar->fresh()->pagadorTexto())->toBe('Particular (paciente)')
        // Anteriores al hito: no se les aplica una visación.
        ->and(AplicarVisacion::impedimento($sinCobrar->fresh()))->toBe('Esta prestación es anterior a los convenios: no se recalcula.');
});
