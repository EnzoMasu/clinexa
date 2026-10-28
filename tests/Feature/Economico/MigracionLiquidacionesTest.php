<?php

/*
 * La migración de las liquidaciones a aseguradoras corre también contra un catálogo SIN sus datos: sin los módulos ni los permisos
 * nuevos, sin módulos en absoluto (instalación nueva) y en la simulación (`migrate --pretend`, que no devuelve filas). Es idempotente,
 * da los permisos de la matriz y, con prestaciones de aseguradora, cobros y visaciones ya existentes, no liquida ni cambia nada: quedan
 * pendientes de liquidar, para cuando alguien arme la liquidación. Prefijo "migracionLiquidaciones".
 */

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LineaVisacion;
use App\Models\MedioPago;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\ResponsablePago;
use App\Models\User;
use App\Support\Convenios\CalcularCobertura;
use App\Support\Convenios\FirmaRecibida;
use App\Support\Convenios\GestionCoberturas;
use App\Support\Convenios\GestionConvenios;
use App\Support\Liquidaciones\ArmarLiquidacion;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MIGRACION_LIQUIDACIONES_MODULOS = ['LIQUIDACIONES', 'APROBACION_LIQUIDACIONES', 'PAGOS_ASEGURADORAS'];

const MIGRACION_LIQUIDACIONES_TABLAS = ['liquidaciones', 'lineas_liquidacion', 'pagos_aseguradora'];

function migracionLiquidacionesArchivo(): object
{
    return require base_path('database/migrations/2026_10_31_100001_create_liquidaciones_aseguradoras.php');
}

function migracionLiquidacionesOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(function () {
    migracionLiquidacionesOlvidarIds();
    Carbon::setTestNow();
});

function migracionLiquidacionesAcciones(string $codigoPerfil, string $modulo): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, "{$modulo}:"))
        ->map(fn (string $clave) => substr($clave, strlen("{$modulo}:")))->sort()->values()->all();
}

/** Base anterior a esta fase: sin las tablas, sin los tres módulos ni sus permisos, sin el estado nuevo. */
function migracionLiquidacionesSinNada(): void
{
    migracionLiquidacionesArchivo()->down();
    foreach (ModuloSistema::whereIn('codigo', MIGRACION_LIQUIDACIONES_MODULOS)->get() as $modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
    DB::table('estados')->where('codigo', Estado::PENDIENTE_APROBACION)->delete();
    migracionLiquidacionesOlvidarIds();
}

test('en una base sin los módulos ni los permisos: arma el esquema vacío, crea el estado y los módulos y da los permisos de la matriz', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionLiquidacionesSinNada();
    expect(Schema::hasTable('liquidaciones'))->toBeFalse()->and(ModuloSistema::whereIn('codigo', MIGRACION_LIQUIDACIONES_MODULOS)->exists())->toBeFalse()
        ->and(Estado::where('codigo', Estado::PENDIENTE_APROBACION)->exists())->toBeFalse();

    migracionLiquidacionesArchivo()->up();
    migracionLiquidacionesOlvidarIds();

    foreach (MIGRACION_LIQUIDACIONES_TABLAS as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue()->and(DB::table($tabla)->count())->toBe(0, $tabla);
    }
    expect(Estado::where('codigo', Estado::PENDIENTE_APROBACION)->count())->toBe(1)
        ->and(ModuloSistema::where('codigo', 'LIQUIDACIONES')->sole()->estados()->pluck('codigo')->sort()->values()->all())
        ->toBe(['ANULADO', 'APROBADO', 'EN_PREPARACION', 'PAGADO', 'PARCIAL', 'PENDIENTE_APROBACION'])
        ->and(ModuloSistema::where('codigo', 'PAGOS_ASEGURADORAS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'VIGENTE']);

    expect(migracionLiquidacionesAcciones(PerfilesPredefinidos::FACTURACION, 'LIQUIDACIONES'))->toBe(['CREAR', 'DESACTIVAR', 'EDITAR', 'EXPORTAR', 'VER'])
        ->and(migracionLiquidacionesAcciones(PerfilesPredefinidos::FACTURACION, 'PAGOS_ASEGURADORAS'))->toBe(['CREAR', 'DESACTIVAR', 'VER'])
        ->and(migracionLiquidacionesAcciones(PerfilesPredefinidos::FACTURACION, 'APROBACION_LIQUIDACIONES'))->toBe([])
        ->and(migracionLiquidacionesAcciones(PerfilesPredefinidos::GERENCIA, 'LIQUIDACIONES'))->toBe(['DESACTIVAR', 'EXPORTAR', 'VER'])
        ->and(migracionLiquidacionesAcciones(PerfilesPredefinidos::GERENCIA, 'APROBACION_LIQUIDACIONES'))->toBe(['EDITAR', 'VER'])
        ->and(migracionLiquidacionesAcciones(PerfilesPredefinidos::GERENCIA, 'PAGOS_ASEGURADORAS'))->toBe(['VER'])
        ->and(migracionLiquidacionesAcciones(PerfilAcceso::ADMINISTRADOR, 'APROBACION_LIQUIDACIONES'))->not->toBe([]);
    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA,
        PerfilesPredefinidos::COMPRAS_TESORERIA, PerfilesPredefinidos::AUDITOR, PerfilesPredefinidos::SUPERVISION_MEDICA] as $perfil) {
        foreach (MIGRACION_LIQUIDACIONES_MODULOS as $modulo) {
            expect(migracionLiquidacionesAcciones($perfil, $modulo))->toBe([], "{$perfil} {$modulo}");
        }
    }
    // Lo que suma la migración es lo mismo que la matriz de los perfiles que se creen en adelante.
    foreach (PerfilesPredefinidos::PERMISOS_LIQUIDACIONES as $modulo => $porPerfil) {
        foreach ($porPerfil as $perfil => $acciones) {
            expect($acciones)->toBe(PerfilesPredefinidos::MATRIZ[$perfil][$modulo]);
        }
    }
});

test('en una instalación nueva (sin módulos) arma el esquema y no toca el catálogo', function () {
    migracionLiquidacionesArchivo()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionLiquidacionesArchivo()->up();

    expect(Schema::hasTable('pagos_aseguradora'))->toBeTrue()->and(ModuloSistema::count())->toBe(0);
});

test('con el catálogo ya completo no duplica estados, módulos ni permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionLiquidacionesArchivo()->down();
    migracionLiquidacionesArchivo()->up();
    [$estados, $modulos, $permisos, $asignados] = [Estado::count(), ModuloSistema::count(), Permiso::count(), DB::table('perfil_permiso')->count()];

    migracionLiquidacionesArchivo()->down();
    migracionLiquidacionesArchivo()->up();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count(), DB::table('perfil_permiso')->count()])->toBe([$estados, $modulos, $permisos, $asignados]);
});

test('en simulación (--pretend) no lee filas ni cambia nada', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionLiquidacionesSinNada();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    $sentencias = DB::pretend(fn () => migracionLiquidacionesArchivo()->up());

    expect($sentencias)->not->toBeEmpty()
        ->and(Schema::hasTable('liquidaciones'))->toBeFalse()
        ->and([Estado::count(), ModuloSistema::count(), Permiso::count()])->toBe([$estados, $modulos, $permisos]);
});

test('con prestaciones de aseguradora, cobros y visaciones ya existentes: no liquida ni cambia nada', function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $consulta = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $usuario = fn (string $codigo) => User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
    [$gerente, $recepcion, $facturacion, $cajero] = [$usuario(PerfilesPredefinidos::GERENCIA), $usuario(PerfilesPredefinidos::RECEPCION),
        $usuario(PerfilesPredefinidos::FACTURACION), $usuario(PerfilesPredefinidos::CAJA)];
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO, CalcularCobertura::HITO])
        ->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($gerente, $consulta->id, null, 100000, Carbon::parse('2026-10-01'));
    $aseguradora = ResponsablePago::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Seguros de Prueba', 'nombres' => 'S.A.'])->id]);
    $convenio = GestionConvenios::alta($facturacion, $aseguradora->id, 'Convenio de prueba', Carbon::parse('2026-10-01'), null, ['Plan Oro']);
    GestionCoberturas::cargarCobertura($recepcion, $this->paciente, $convenio->planes()->sole()->id, 'AS-0001', 'TITULAR', Carbon::parse('2026-10-01'), null);
    $turno = hcTurno(['procedimiento_id' => $consulta->id]);
    GestionCoberturas::cargarVisacion($recepcion, $this->paciente, 'turno', $turno->id, 'V-1', Carbon::parse('2026-10-06'),
        [['procedimiento_id' => $consulta->id, 'resultado' => LineaVisacion::APROBADO, 'copago' => LineaVisacion::PORCENTAJE, 'valor' => '20']]);
    $this->actingAs($this->medico);
    hcGuardarNueva([], $turno)->assertSessionHasNoErrors();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    FirmaRecibida::marcar($recepcion, $prestacion);
    // Caja cobra la parte de la paciente (₲ 20.000).
    $this->actingAs($cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
    $this->post(route('admin.cobros.store'), ['paciente_id' => $this->paciente->id, 'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => '20000']]])->assertSessionHas('status');

    migracionLiquidacionesSinNada();
    $foto = fn () => [
        DB::table('prestaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        DB::table('cobros')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        DB::table('cobro_prestaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        DB::table('visaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        DB::table('lineas_visacion')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        DB::table('cajas')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
    ];
    $antes = $foto();
    expect($antes[0])->toHaveCount(1)->and($antes[0][0]['pagador'])->toBe(Prestacion::ASEGURADORA)->and($antes[1])->toHaveCount(1);

    Carbon::setTestNow(Carbon::parse('2026-10-06 13:00:00', 'UTC'));
    migracionLiquidacionesArchivo()->up();
    migracionLiquidacionesOlvidarIds();

    expect($foto())->toBe($antes)
        ->and(DB::table('liquidaciones')->count())->toBe(0)->and(DB::table('lineas_liquidacion')->count())->toBe(0)
        // Sigue pendiente de liquidar: entra cuando alguien arme la liquidación.
        ->and($prestacion->fresh()->liquidacion)->toBe(Prestacion::PENDIENTE_DE_LIQUIDAR)
        ->and(ArmarLiquidacion::candidatas($aseguradora->id, Carbon::parse('2026-10-06'))->pluck('prestaciones.id')->all())->toBe([$prestacion->id]);
});
