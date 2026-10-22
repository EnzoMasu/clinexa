<?php

/*
 * La migración de reversiones de cobro corre también contra un catálogo SIN sus datos: sin el estado REVERTIDO, sin el módulo y sin
 * los permisos nuevos, sin módulos en absoluto (instalación nueva) y en la simulación (`migrate --pretend`, que no devuelve filas).
 * Es idempotente, da los permisos solo a Caja y Gerencia, y con cobros ya existentes no revierte ninguno.
 */

use App\Models\Cobro;
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
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function migracionReversionesArchivo(): object
{
    return require base_path('database/migrations/2026_10_29_100001_reversiones_de_cobro.php');
}

/** Olvida los ids de estados que Estado guarda en memoria (si no, devolvería ids de filas ya borradas). */
function migracionReversionesOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(function () {
    migracionReversionesOlvidarIds();
    Carbon::setTestNow();
});

function migracionReversionesAcciones(string $codigoPerfil, string $modulo): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, "{$modulo}:"))
        ->map(fn (string $clave) => substr($clave, strlen("{$modulo}:")))->sort()->values()->all();
}

/** Base anterior a esta fase: sin el esquema, sin el módulo, sin REVERTIDO y sin DESACTIVAR sobre COBROS en los perfiles. */
function migracionReversionesSinNada(): void
{
    // SQLite rehace `cobros` al quitarle columnas; con cobros que la referencian, las claves foráneas se verifican al final (la
    // transacción del test se deshace). En PostgreSQL lo mismo corre en tests/Postgres.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    migracionReversionesArchivo()->down();
    foreach (ModuloSistema::where('codigo', 'REVERSIONES_COBRO')->get() as $modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
    if ($cobros = ModuloSistema::where('codigo', 'COBROS')->first()) {
        $desactivar = Permiso::where('modulo_sistema_id', $cobros->id)->where('accion', 'DESACTIVAR')->pluck('id');
        DB::table('perfil_permiso')->whereIn('permiso_id', $desactivar)
            ->whereNotIn('perfil_acceso_id', PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->pluck('id'))->delete();
    }
    $revertido = Estado::where('codigo', Estado::REVERTIDO)->pluck('id');
    DB::table('estado_modulo')->whereIn('estado_id', $revertido)->delete();
    DB::table('estados')->whereIn('id', $revertido)->delete();
    migracionReversionesOlvidarIds();
}

test('en una base sin el módulo, el estado ni los permisos: los crea, arma el esquema vacío y da los permisos de la matriz', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    migracionReversionesSinNada();
    expect(Schema::hasTable('solicitudes_reversion_cobro'))->toBeFalse()->and(Schema::hasColumn('cobros', 'revertido_en'))->toBeFalse()
        ->and(Schema::hasColumn('medios_pago', 'es_efectivo'))->toBeFalse()
        ->and(ModuloSistema::where('codigo', 'REVERSIONES_COBRO')->exists())->toBeFalse()->and(Estado::where('codigo', Estado::REVERTIDO)->exists())->toBeFalse()
        ->and(migracionReversionesAcciones(PerfilesPredefinidos::CAJA, 'COBROS'))->toBe(['CREAR', 'VER']);

    migracionReversionesArchivo()->up();

    expect(Schema::hasTable('solicitudes_reversion_cobro'))->toBeTrue()->and(DB::table('solicitudes_reversion_cobro')->count())->toBe(0)
        ->and(Schema::hasColumn('cierre_caja_medios', 'es_efectivo'))->toBeTrue()
        ->and(MedioPago::where('es_efectivo', true)->pluck('codigo')->all())->toBe([MedioPago::EFECTIVO])
        ->and(Schema::hasColumns('cobros', ['revertido_en', 'revertido_por', 'motivo_reversion', 'caja_reversion_id']))->toBeTrue()
        ->and(Estado::where('codigo', Estado::REVERTIDO)->count())->toBe(1)
        ->and(ModuloSistema::where('codigo', 'COBROS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['REVERTIDO', 'VIGENTE'])
        ->and(ModuloSistema::where('codigo', 'REVERSIONES_COBRO')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['APROBADO', 'PENDIENTE', 'RECHAZADO']);

    expect(migracionReversionesAcciones(PerfilesPredefinidos::CAJA, 'COBROS'))->toBe(['CREAR', 'DESACTIVAR', 'VER'])
        ->and(migracionReversionesAcciones(PerfilesPredefinidos::GERENCIA, 'COBROS'))->toBe(['VER'])
        ->and(migracionReversionesAcciones(PerfilesPredefinidos::GERENCIA, 'REVERSIONES_COBRO'))->toBe(['EDITAR', 'VER'])
        ->and(migracionReversionesAcciones(PerfilAcceso::ADMINISTRADOR, 'REVERSIONES_COBRO'))->not->toBe([]);
    foreach (array_keys(PerfilesPredefinidos::NOMBRES) as $perfil) {
        if (in_array($perfil, [PerfilAcceso::ADMINISTRADOR, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::GERENCIA], true)) {
            continue;
        }
        expect(migracionReversionesAcciones($perfil, 'REVERSIONES_COBRO'))->toBe([], $perfil)
            ->and(migracionReversionesAcciones($perfil, 'COBROS'))->not->toContain('DESACTIVAR');
    }
    // Lo que suma la migración está en la matriz de los perfiles que se creen en adelante.
    foreach (PerfilesPredefinidos::PERMISOS_REVERSIONES_COBRO as $modulo => $porPerfil) {
        foreach ($porPerfil as $perfil => $acciones) {
            expect(array_diff($acciones, PerfilesPredefinidos::MATRIZ[$perfil][$modulo]))->toBe([]);
        }
    }
});

test('en una instalación nueva (sin módulos) arma el esquema y no toca el catálogo', function () {
    migracionReversionesArchivo()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionReversionesArchivo()->up();

    expect(Schema::hasTable('solicitudes_reversion_cobro'))->toBeTrue()->and(Schema::hasColumn('cobros', 'caja_reversion_id'))->toBeTrue()
        ->and(ModuloSistema::count())->toBe(0)->and(Estado::where('codigo', Estado::REVERTIDO)->exists())->toBeTrue();
});

test('con el catálogo ya completo no duplica estados, módulos ni permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionReversionesArchivo()->down();
    migracionReversionesArchivo()->up();
    [$estados, $modulos, $permisos, $asignados] = [Estado::count(), ModuloSistema::count(), Permiso::count(), DB::table('perfil_permiso')->count()];

    migracionReversionesArchivo()->down();
    migracionReversionesArchivo()->up();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count(), DB::table('perfil_permiso')->count()])->toBe([$estados, $modulos, $permisos, $asignados]);
});

test('en simulación (--pretend) no lee filas ni cambia nada', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionReversionesSinNada();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    $sentencias = DB::pretend(fn () => migracionReversionesArchivo()->up());

    expect($sentencias)->not->toBeEmpty()
        ->and(Schema::hasTable('solicitudes_reversion_cobro'))->toBeFalse()->and(Schema::hasColumn('cobros', 'revertido_en'))->toBeFalse()
        ->and(Schema::hasColumn('medios_pago', 'es_efectivo'))->toBeFalse()->and(Schema::hasColumn('cierre_caja_medios', 'es_efectivo'))->toBeFalse()
        ->and([Estado::count(), ModuloSistema::count(), Permiso::count()])->toBe([$estados, $modulos, $permisos]);
});

test('con cobros y cierres ya existentes: no revierte ninguno, quedan vigentes y los cierres solo reciben la marca del efectivo', function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $procedimiento = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    AltaTarifa::ejecutar($this->medico, $procedimiento->id, null, 100000, Carbon::parse('2026-10-01'));
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $cajero = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole()])->create();
    $this->actingAs($cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
    $this->post(route('admin.cobros.store'), ['paciente_id' => $this->paciente->id, 'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => '100000']]])->assertSessionHas('status');
    $this->post(route('admin.cajas.cerrar', \App\Models\Caja::sole()), ['contado' => MedioPago::pluck('id')->mapWithKeys(fn ($id) => [$id => $id === MedioPago::efectivo()->id ? '100000' : '0'])->all()])
        ->assertSessionHas('status');
    $efectivoId = MedioPago::efectivo()->id;
    migracionReversionesSinNada();
    $montosCierre = fn () => DB::table('cierre_caja_medios')->orderBy('id')->get(['id', 'medio_pago_id', 'esperado', 'contado', 'diferencia'])->map(fn ($fila) => (array) $fila)->all();
    $cierreAntes = $montosCierre();
    expect($cierreAntes)->toHaveCount(3);
    $foto = fn () => [
        DB::table('cobros')->orderBy('id')->get(['id', 'caja_id', 'total', 'estado_id'])->map(fn ($fila) => (array) $fila)->all(),
        DB::table('cobro_prestaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        DB::table('movimientos_caja')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
    ];
    $antes = $foto();
    expect($antes[0])->toHaveCount(1)->and($antes[1][0]['revertido_en'])->toBeNull();

    migracionReversionesArchivo()->up();
    migracionReversionesOlvidarIds();

    $cobro = Cobro::sole();
    expect($foto())->toBe($antes)
        ->and($cobro->revertido())->toBeFalse()->and($cobro->tieneEstado(Estado::VIGENTE))->toBeTrue()
        ->and($cobro->revertido_en)->toBeNull()->and($cobro->caja_reversion_id)->toBeNull()
        ->and(DB::table('solicitudes_reversion_cobro')->count())->toBe(0)
        ->and($prestacion->fresh()->estadoCobroTexto())->toBe(Prestacion::COBRADA)
        ->and(Cobrar::pendientes()->count())->toBe(0)
        ->and($montosCierre())->toBe($cierreAntes)
        ->and(DB::table('cierre_caja_medios')->where('es_efectivo', true)->pluck('medio_pago_id')->all())->toBe([$efectivoId])
        ->and(\App\Models\Caja::sole()->cerrada())->toBeTrue();
});
