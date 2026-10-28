<?php

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\Moneda;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\ResponsablePago;
use App\Models\User;
use App\Support\Convenios\CalcularCobertura;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de las liquidaciones a aseguradoras en PostgreSQL (docs/liquidaciones-aseguradoras.md): una sola abierta por
 * aseguradora (liquidaciones_una_abierta) y una liquidación por prestación (lineas_liquidacion_una_por_prestacion), los dos índices
 * únicos parciales; los CHECK de la liquidación, sus líneas y sus pagos; y la migración con prestaciones ya existentes. Lo demás está en
 * tests/Feature/Economico. Datos inventados. Prefijo "pgLiquidaciones".
 */
beforeEach(function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $procedimiento = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, CalcularCobertura::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->medico, $procedimiento->id, null, 100000, Carbon::parse('2026-10-01'));
    $this->facturacion = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::FACTURACION)->sole()])->create();
    $this->gerente = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::GERENCIA)->sole()])->create();
    $this->aseguradora = pgLiquidacionesResponsable();
});

afterEach(fn () => Carbon::setTestNow());

function pgLiquidacionesResponsable(): ResponsablePago
{
    return ResponsablePago::create(['persona_id' => Persona::factory()->create()->id]);
}

/** El código y la restricción con que la base rechaza la sentencia (en su propio savepoint), o null si la acepta. */
function pgLiquidacionesRechazo(\Closure $sentencia): ?array
{
    try {
        DB::transaction($sentencia);
    } catch (QueryException $e) {
        $restricciones = ['liquidaciones_una_abierta', 'lineas_liquidacion_una_por_prestacion', 'liquidaciones_total_valido', 'liquidaciones_aprobacion_completa',
            'liquidaciones_anulacion_completa', 'liquidaciones_facturacion_valida', 'lineas_liquidacion_monto_positivo', 'lineas_liquidacion_liberacion_completa',
            'pagos_aseguradora_monto_positivo', 'pagos_aseguradora_anulacion_completa'];

        return [$e->getCode(), collect($restricciones)->first(fn (string $nombre) => str_contains($e->getMessage(), $nombre))];
    }

    return null;
}

function pgLiquidacionesLiquidacion(array $datos = []): int
{
    return DB::table('liquidaciones')->insertGetId([
        'responsable_pago_id' => test()->aseguradora->id, 'fecha_corte' => '2026-10-06', 'estado_id' => Estado::idDe(Estado::EN_PREPARACION), 'total' => 100000,
        'moneda_id' => Moneda::guarani()->id, 'armada_en' => now(), 'armada_por' => test()->facturacion->id, 'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]);
}

function pgLiquidacionesLinea(int $liquidacionId, int $prestacionId, array $datos = []): void
{
    DB::table('lineas_liquidacion')->insert([
        'liquidacion_id' => $liquidacionId, 'prestacion_id' => $prestacionId, 'paciente' => 'Paciente inventada', 'documento' => 'CI 9000001',
        'numero_asegurado' => 'AS-1', 'fecha_servicio' => '2026-10-06', 'procedimiento' => 'Consulta', 'numero_visacion' => 'V-1', 'monto' => 100000,
        'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]);
}

/** Lo que tiene una liquidación aprobada (para pasar de abierta a cerrada). */
function pgLiquidacionesAprobada(): array
{
    return ['estado_id' => Estado::idDe(Estado::APROBADO), 'enviada_en' => now(), 'enviada_por' => test()->facturacion->id, 'aprobada_en' => now(),
        'aprobada_por' => test()->gerente->id, 'facturacion' => 'PENDIENTE'];
}

test('corre de verdad contra PostgreSQL, con las restricciones y los índices creados', function () {
    $restricciones = fn (string $tabla) => collect(DB::select("select conname from pg_constraint where conrelid = '{$tabla}'::regclass and contype = 'c'"))
        ->pluck('conname')->sort()->values()->all();
    $indices = fn (string $tabla) => collect(DB::select('select indexname from pg_indexes where tablename = ?', [$tabla]))->pluck('indexname')->all();

    expect(DB::getDriverName())->toBe('pgsql')->and(DB::connection()->getDatabaseName())->toBe('clinexa_test')
        ->and($restricciones('liquidaciones'))->toBe(['liquidaciones_anulacion_completa', 'liquidaciones_aprobacion_completa', 'liquidaciones_devolucion_completa',
            'liquidaciones_envio_completo', 'liquidaciones_facturacion_valida', 'liquidaciones_total_valido'])
        ->and($restricciones('lineas_liquidacion'))->toBe(['lineas_liquidacion_liberacion_completa', 'lineas_liquidacion_monto_positivo'])
        ->and($restricciones('pagos_aseguradora'))->toBe(['pagos_aseguradora_anulacion_completa', 'pagos_aseguradora_monto_positivo'])
        ->and($indices('liquidaciones'))->toContain('liquidaciones_una_abierta')
        ->and($indices('lineas_liquidacion'))->toContain('lineas_liquidacion_una_por_prestacion');
});

test('liquidaciones_una_abierta: una sola en preparación o pendiente de aprobación por aseguradora; aprobadas y anuladas no cuentan', function () {
    $primera = pgLiquidacionesLiquidacion();

    expect(pgLiquidacionesRechazo(fn () => pgLiquidacionesLiquidacion()))->toBe(['23505', 'liquidaciones_una_abierta'])
        ->and(pgLiquidacionesRechazo(fn () => pgLiquidacionesLiquidacion(['estado_id' => Estado::idDe(Estado::PENDIENTE_APROBACION), 'enviada_en' => now(), 'enviada_por' => test()->facturacion->id])))
        ->toBe(['23505', 'liquidaciones_una_abierta'])
        // Otra aseguradora sí; una aprobada o una anulada de la misma, también.
        ->and(pgLiquidacionesRechazo(fn () => pgLiquidacionesLiquidacion(['responsable_pago_id' => pgLiquidacionesResponsable()->id])))->toBeNull()
        ->and(pgLiquidacionesRechazo(fn () => pgLiquidacionesLiquidacion(pgLiquidacionesAprobada())))->toBeNull()
        ->and(pgLiquidacionesRechazo(fn () => pgLiquidacionesLiquidacion(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_en' => now(), 'anulada_por' => test()->facturacion->id, 'motivo_anulacion' => 'Prueba'])))->toBeNull();

    // Aprobada la primera, se puede abrir otra.
    DB::table('liquidaciones')->where('id', $primera)->update(pgLiquidacionesAprobada());
    expect(pgLiquidacionesRechazo(fn () => pgLiquidacionesLiquidacion()))->toBeNull()
        ->and(DB::table('liquidaciones')->where('responsable_pago_id', test()->aseguradora->id)->count())->toBe(4);
});

test('lineas_liquidacion_una_por_prestacion: una prestación en una sola línea no liberada (quitada o anulada, queda libre)', function () {
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $primera = pgLiquidacionesLiquidacion(pgLiquidacionesAprobada());
    $segunda = pgLiquidacionesLiquidacion();
    pgLiquidacionesLinea($primera, $prestacion->id);

    expect(pgLiquidacionesRechazo(fn () => pgLiquidacionesLinea($segunda, $prestacion->id)))->toBe(['23505', 'lineas_liquidacion_una_por_prestacion'])
        ->and(pgLiquidacionesRechazo(fn () => pgLiquidacionesLinea($primera, $prestacion->id)))->toBe(['23505', 'lineas_liquidacion_una_por_prestacion']);

    // Liberada (quitada o por la anulación de su liquidación), la prestación entra en otra.
    DB::table('lineas_liquidacion')->where('liquidacion_id', $primera)->update(['liberacion' => 'ANULADA', 'liberada_en' => now(), 'liberada_por' => test()->gerente->id,
        'motivo_liberacion' => 'Liquidación anulada: prueba']);
    expect(pgLiquidacionesRechazo(fn () => pgLiquidacionesLinea($segunda, $prestacion->id)))->toBeNull()
        ->and(DB::table('lineas_liquidacion')->where('prestacion_id', $prestacion->id)->count())->toBe(2);
});

test('CHECK: total entero, aprobación por otro, anulación y liberación completas, montos positivos', function () {
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $id = pgLiquidacionesLiquidacion();

    expect(pgLiquidacionesRechazo(fn () => pgLiquidacionesLiquidacion(['responsable_pago_id' => pgLiquidacionesResponsable()->id, 'total' => -1])))->toBe(['23514', 'liquidaciones_total_valido'])
        ->and(pgLiquidacionesRechazo(fn () => pgLiquidacionesLiquidacion(['responsable_pago_id' => pgLiquidacionesResponsable()->id, 'total' => 100.5])))->toBe(['23514', 'liquidaciones_total_valido'])
        // Quien la armó no la aprueba.
        ->and(pgLiquidacionesRechazo(fn () => DB::table('liquidaciones')->where('id', $id)->update([...pgLiquidacionesAprobada(), 'aprobada_por' => test()->facturacion->id])))
        ->toBe(['23514', 'liquidaciones_aprobacion_completa'])
        ->and(pgLiquidacionesRechazo(fn () => DB::table('liquidaciones')->where('id', $id)->update(['facturacion' => 'PENDIENTE'])))->toBe(['23514', 'liquidaciones_facturacion_valida'])
        ->and(pgLiquidacionesRechazo(fn () => DB::table('liquidaciones')->where('id', $id)->update(['anulada_en' => now()])))->toBe(['23514', 'liquidaciones_anulacion_completa'])
        ->and(pgLiquidacionesRechazo(fn () => pgLiquidacionesLinea($id, $prestacion->id, ['monto' => 0])))->toBe(['23514', 'lineas_liquidacion_monto_positivo'])
        ->and(pgLiquidacionesRechazo(fn () => pgLiquidacionesLinea($id, $prestacion->id, ['liberacion' => 'OTRA', 'liberada_en' => now(), 'liberada_por' => test()->facturacion->id, 'motivo_liberacion' => 'X'])))
        ->toBe(['23514', 'lineas_liquidacion_liberacion_completa']);

    DB::table('liquidaciones')->where('id', $id)->update(pgLiquidacionesAprobada());
    $pago = fn (array $datos) => DB::table('pagos_aseguradora')->insert(['liquidacion_id' => $id, 'monto' => 50000, 'fecha' => '2026-10-06',
        'medio_pago_id' => MedioPago::where('codigo', 'TRANSFERENCIA')->value('id'), 'estado_id' => Estado::idDe(Estado::VIGENTE), 'usuario_id' => test()->facturacion->id,
        'created_at' => now(), 'updated_at' => now(), ...$datos]);
    expect(pgLiquidacionesRechazo(fn () => $pago(['monto' => 0])))->toBe(['23514', 'pagos_aseguradora_monto_positivo'])
        ->and(pgLiquidacionesRechazo(fn () => $pago(['anulado_en' => now()])))->toBe(['23514', 'pagos_aseguradora_anulacion_completa'])
        ->and(pgLiquidacionesRechazo(fn () => $pago([])))->toBeNull();
});

test('la migración, con prestaciones ya existentes: no las liquida ni las cambia', function () {
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $migracion = require base_path('database/migrations/2026_10_31_100001_create_liquidaciones_aseguradoras.php');
    $migracion->down();
    $antes = (array) DB::table('prestaciones')->where('id', $prestacion->id)->first();

    $migracion->up();

    expect((array) DB::table('prestaciones')->where('id', $prestacion->id)->first())->toBe($antes)
        ->and(DB::table('liquidaciones')->count())->toBe(0)->and(DB::table('lineas_liquidacion')->count())->toBe(0);
});
