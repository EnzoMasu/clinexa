<?php

/*
 * La migración de la facturación corre también contra un catálogo SIN sus datos: sin los módulos ni los permisos nuevos, sin módulos
 * en absoluto (instalación nueva) y en la simulación (`migrate --pretend`, que no devuelve filas). Es idempotente, da los permisos de
 * la matriz, carga los tres motivos de referencia y, con cobros, cajas y prestaciones ya existentes, no factura ni cambia nada: los
 * cobros anteriores quedan sin factura. Prefijo "migracionFacturacion".
 */

use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\User;
use App\Support\Cobros\Cobrar;
use App\Support\Facturacion\EmitirFactura;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Database\Seeders\ModuloSistemaSeeder;
use Database\Seeders\MotivosAnulacionFacturaSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MIGRACION_FACTURACION_MODULOS = ['FACTURAS', 'ANULACIONES_FACTURA', 'TIMBRADOS', 'DATOS_FISCALES', 'MOTIVOS_ANULACION'];

const MIGRACION_FACTURACION_TABLAS = ['clinicas', 'timbrados', 'facturas', 'detalles_factura', 'solicitudes_anulacion_factura'];

function migracionFacturacionArchivo(): object
{
    return require base_path('database/migrations/2026_11_02_100001_create_facturacion_individual.php');
}

function migracionFacturacionOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(function () {
    migracionFacturacionOlvidarIds();
    Carbon::setTestNow();
});

function migracionFacturacionAcciones(string $codigoPerfil, string $modulo): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, "{$modulo}:"))
        ->map(fn (string $clave) => substr($clave, strlen("{$modulo}:")))->sort()->values()->all();
}

/** Base anterior a esta fase: sin las tablas ni las columnas, sin los cinco módulos ni sus permisos, sin el estado nuevo. */
function migracionFacturacionSinNada(): void
{
    // SQLite rehace `sucursales` y `cobros` al quitarles la clave foránea: dentro de la transacción del test, las referencias se
    // verifican al final.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    migracionFacturacionArchivo()->down();
    foreach (ModuloSistema::whereIn('codigo', MIGRACION_FACTURACION_MODULOS)->get() as $modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
    DB::table('estados')->where('codigo', Estado::COBRADO)->delete();
    migracionFacturacionOlvidarIds();
}

test('en una base sin los módulos ni los permisos: arma el esquema vacío, crea el estado, los módulos, los motivos y da los permisos de la matriz', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionFacturacionSinNada();
    expect(Schema::hasTable('facturas'))->toBeFalse()->and(Schema::hasColumn('cobros', 'factura_id'))->toBeFalse()
        ->and(ModuloSistema::whereIn('codigo', MIGRACION_FACTURACION_MODULOS)->exists())->toBeFalse()
        ->and(Estado::where('codigo', Estado::COBRADO)->exists())->toBeFalse();

    migracionFacturacionArchivo()->up();
    migracionFacturacionOlvidarIds();

    foreach (MIGRACION_FACTURACION_TABLAS as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue()->and(DB::table($tabla)->count())->toBe(0, $tabla);
    }
    expect(Schema::hasColumns('cobros', ['factura_id']))->toBeTrue()->and(Schema::hasColumns('procedimientos', ['tasa_iva']))->toBeTrue()
        ->and(Schema::hasColumns('sucursales', ['clinica_id']))->toBeTrue()
        ->and(DB::table('motivos_anulacion_factura')->orderBy('id')->pluck('nombre')->all())
        ->toBe(['Error en los datos del cliente', 'Error en el procedimiento o el monto', 'Otro (detallar)'])
        ->and(DB::table('hitos_sistema')->where('codigo', EmitirFactura::HITO)->count())->toBe(1)
        ->and(Estado::where('codigo', Estado::COBRADO)->count())->toBe(1)
        ->and(ModuloSistema::where('codigo', 'FACTURAS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'COBRADO', 'EMITIDO'])
        ->and(ModuloSistema::where('codigo', 'ANULACIONES_FACTURA')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['APROBADO', 'PENDIENTE', 'RECHAZADO'])
        ->and(ModuloSistema::where('codigo', 'TIMBRADOS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['HISTORICO', 'VIGENTE']);

    expect(migracionFacturacionAcciones(PerfilesPredefinidos::CAJA, 'FACTURAS'))->toBe(['CREAR', 'DESACTIVAR', 'EXPORTAR', 'VER'])
        ->and(migracionFacturacionAcciones(PerfilesPredefinidos::GERENCIA, 'FACTURAS'))->toBe(['EXPORTAR', 'VER'])
        ->and(migracionFacturacionAcciones(PerfilesPredefinidos::GERENCIA, 'ANULACIONES_FACTURA'))->toBe(['EDITAR', 'VER'])
        ->and(migracionFacturacionAcciones(PerfilesPredefinidos::GERENCIA, 'TIMBRADOS'))->toBe(['CREAR', 'EDITAR', 'VER'])
        ->and(migracionFacturacionAcciones(PerfilesPredefinidos::GERENCIA, 'DATOS_FISCALES'))->toBe(['EDITAR', 'VER'])
        ->and(migracionFacturacionAcciones(PerfilesPredefinidos::FACTURACION, 'FACTURAS'))->toBe(['EXPORTAR', 'VER'])
        ->and(migracionFacturacionAcciones(PerfilesPredefinidos::FACTURACION, 'ANULACIONES_FACTURA'))->toBe([])
        ->and(migracionFacturacionAcciones(PerfilAcceso::ADMINISTRADOR, 'TIMBRADOS'))->not->toBe([]);
    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA,
        PerfilesPredefinidos::COMPRAS_TESORERIA, PerfilesPredefinidos::AUDITOR, PerfilesPredefinidos::SUPERVISION_MEDICA] as $perfil) {
        foreach (MIGRACION_FACTURACION_MODULOS as $modulo) {
            expect(migracionFacturacionAcciones($perfil, $modulo))->toBe([], "{$perfil} {$modulo}");
        }
    }
    // Lo que suma la migración es lo mismo que la matriz de los perfiles que se creen en adelante.
    foreach (PerfilesPredefinidos::PERMISOS_FACTURACION as $modulo => $porPerfil) {
        foreach ($porPerfil as $perfil => $acciones) {
            expect($acciones)->toBe(PerfilesPredefinidos::MATRIZ[$perfil][$modulo]);
        }
    }
});

test('en una instalación nueva (sin módulos ni estados) arma el esquema, carga los motivos y no toca el catálogo de módulos', function () {
    migracionFacturacionArchivo()->down();
    DB::table('estados')->where('codigo', Estado::COBRADO)->delete();
    expect(ModuloSistema::count())->toBe(0);

    migracionFacturacionArchivo()->up();

    expect(Schema::hasTable('facturas'))->toBeTrue()->and(ModuloSistema::count())->toBe(0)
        ->and(DB::table('motivos_anulacion_factura')->count())->toBe(3)
        ->and(Estado::where('codigo', Estado::COBRADO)->count())->toBe(1);
});

test('con el catálogo ya completo no duplica estados, módulos, permisos ni motivos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionFacturacionArchivo()->down();
    migracionFacturacionArchivo()->up();
    [$estados, $modulos, $permisos, $asignados] = [Estado::count(), ModuloSistema::count(), Permiso::count(), DB::table('perfil_permiso')->count()];

    migracionFacturacionArchivo()->down();
    migracionFacturacionArchivo()->up();
    (new MotivosAnulacionFacturaSeeder)->run();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count(), DB::table('perfil_permiso')->count()])->toBe([$estados, $modulos, $permisos, $asignados])
        ->and(DB::table('motivos_anulacion_factura')->count())->toBe(3);
});

test('en simulación (--pretend) no lee filas ni cambia nada', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionFacturacionSinNada();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    $sentencias = DB::pretend(fn () => migracionFacturacionArchivo()->up());

    expect($sentencias)->not->toBeEmpty()
        ->and(Schema::hasTable('facturas'))->toBeFalse()->and(Schema::hasColumn('cobros', 'factura_id'))->toBeFalse()
        ->and([Estado::count(), ModuloSistema::count(), Permiso::count()])->toBe([$estados, $modulos, $permisos])
        ->and(DB::table('hitos_sistema')->where('codigo', EmitirFactura::HITO)->exists())->toBeFalse();
});

test('con cobros, cajas y prestaciones ya existentes: no factura ni cambia nada; los cobros anteriores quedan sin factura', function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $consulta = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $usuario = fn (string $codigo) => User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
    [$gerente, $cajero] = [$usuario(PerfilesPredefinidos::GERENCIA), $usuario(PerfilesPredefinidos::CAJA)];
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])
        ->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($gerente, $consulta->id, null, 100000, Carbon::parse('2026-10-01'));
    $this->actingAs($this->medico);
    hcConsulta();
    hcConsulta();
    [$cobrada, $pendiente] = Prestacion::orderBy('id')->get()->all();

    migracionFacturacionSinNada();
    // Un cobro de antes de esta fase (sin la columna de la factura).
    $this->actingAs($cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
    Cobrar::ejecutar($cajero, $this->paciente->id, [$cobrada->id], [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => 100000, 'referencia' => null]]);
    expect(DB::table('cobros')->count())->toBe(1);
    $sinColumnasNuevas = fn (string $tabla, array $nuevas) => DB::table($tabla)->orderBy('id')->get()->map(fn ($fila) => collect((array) $fila)->except($nuevas)->all())->all();
    $foto = fn () => [
        $sinColumnasNuevas('prestaciones', []), $sinColumnasNuevas('cobros', ['factura_id']), $sinColumnasNuevas('cobro_prestaciones', []),
        $sinColumnasNuevas('lineas_cobro', []), $sinColumnasNuevas('movimientos_caja', []), $sinColumnasNuevas('cajas', []),
        $sinColumnasNuevas('procedimientos', ['tasa_iva']), $sinColumnasNuevas('sucursales', ['clinica_id']),
    ];
    $antes = $foto();

    Carbon::setTestNow(Carbon::parse('2026-10-06 13:00:00', 'UTC'));
    migracionFacturacionArchivo()->up();
    migracionFacturacionOlvidarIds();

    expect($foto())->toBe($antes)
        ->and(DB::table('facturas')->count())->toBe(0)->and(DB::table('detalles_factura')->count())->toBe(0)
        ->and(DB::table('cobros')->whereNotNull('factura_id')->count())->toBe(0)
        // Los procedimientos existentes quedan con IVA 10 %; las sucursales, sin clínica (se asignan al cargarla).
        ->and(DB::table('procedimientos')->pluck('tasa_iva')->unique()->all())->toBe([10])
        ->and(DB::table('sucursales')->whereNotNull('clinica_id')->count())->toBe(0)
        ->and($pendiente->fresh()->detalleFactura)->toBeNull();
});
