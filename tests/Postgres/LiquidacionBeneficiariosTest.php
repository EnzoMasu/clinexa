<?php

use App\Models\AcuerdoReparto;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LineaReparto;
use App\Models\MedioPago;
use App\Models\Moneda;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\Cobrar;
use App\Support\LiquidacionesBeneficiarios\ArmarLiquidacionBeneficiario;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\AltaAcuerdo;
use App\Support\Repartos\Porcentaje;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de la liquidación a beneficiarios en PostgreSQL (docs/liquidacion-beneficiarios.md): una sola abierta por
 * beneficiario y los únicos parciales de las líneas (una ordinaria por vez, un ajuste por ordinaria, un saldo trasladado una vez); los
 * CHECK de la liquidación, sus líneas y sus pagos; y la migración con prestaciones, repartos y cobros ya cargados (no los toca y el
 * `down` se niega con liquidaciones cargadas). Lo demás está en tests/Feature/Economico. Datos inventados. Prefijo "pgLiqBenef".
 */
beforeEach(function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $procedimiento = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    $this->facturacion = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::FACTURACION)->sole()])->create();
    $this->gerente = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::GERENCIA)->sole()])->create();
    $this->cajero = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole()])->create();
    AltaTarifa::ejecutar($this->gerente, $procedimiento->id, null, 100000, Carbon::parse('2026-10-01'));
    AltaAcuerdo::ejecutar($this->gerente, AcuerdoReparto::PROFESIONAL, $this->profesional->id, null, null, Porcentaje::desdeTexto('40'), Carbon::parse('2026-10-01'));
});

afterEach(fn () => Carbon::setTestNow());

/** Una prestación cobrada de la Dra. Benítez (₲ 100.000; su línea, ₲ 40.000). */
function pgLiqBenefCobrada(): Prestacion
{
    test()->actingAs(test()->medico);
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    if (! DB::table('cajas')->where('usuario_id', test()->cajero->id)->exists()) {
        AbrirCaja::ejecutar(test()->cajero, 50000);
    }
    Cobrar::ejecutar(test()->cajero, test()->paciente->id, [$prestacion->id], [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => 100000, 'referencia' => null]]);

    return $prestacion;
}

function pgLiqBenefRechazo(Closure $sentencia): ?array
{
    try {
        DB::transaction($sentencia);
    } catch (QueryException $e) {
        $restricciones = ['liquidaciones_beneficiario_una_abierta', 'lineas_liq_beneficiario_una_ordinaria', 'lineas_liq_beneficiario_un_ajuste',
            'lineas_liq_beneficiario_un_saldo', 'liquidaciones_beneficiario_clave_valida', 'liquidaciones_beneficiario_total_entero',
            'liquidaciones_beneficiario_aprobacion_completa', 'liquidaciones_beneficiario_anulacion_completa', 'lineas_liq_beneficiario_tipo_valido',
            'lineas_liq_beneficiario_monto_entero', 'lineas_liq_beneficiario_liberacion_completa', 'pagos_beneficiario_monto_positivo',
            'pagos_beneficiario_caja_completa', 'pagos_beneficiario_anulacion_completa'];

        return [$e->getCode(), collect($restricciones)->first(fn (string $nombre) => str_contains($e->getMessage(), $nombre))];
    }

    return null;
}

function pgLiqBenefLiquidacion(array $datos = []): int
{
    return DB::table('liquidaciones_beneficiario')->insertGetId([
        'beneficiario' => 'PROFESIONAL', 'profesional_id' => test()->profesional->id, 'fecha_corte' => '2026-10-06',
        'estado_id' => Estado::idDe(Estado::EN_PREPARACION), 'total' => 40000, 'moneda_id' => Moneda::guarani()->id, 'armada_en' => now(),
        'armada_por' => test()->facturacion->id, 'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]);
}

function pgLiqBenefAprobada(): array
{
    return ['estado_id' => Estado::idDe(Estado::APROBADO), 'enviada_en' => now(), 'enviada_por' => test()->facturacion->id,
        'aprobada_en' => now(), 'aprobada_por' => test()->gerente->id];
}

function pgLiqBenefLinea(int $liquidacionId, array $datos = []): int
{
    $linea = LineaReparto::where('beneficiario', 'PROFESIONAL')->latest('id')->firstOrFail();

    return DB::table('lineas_liquidacion_beneficiario')->insertGetId([
        'liquidacion_beneficiario_id' => $liquidacionId, 'tipo' => 'ORDINARIA', 'linea_reparto_id' => $linea->id,
        'prestacion_id' => $linea->reparto->prestacion_id, 'vez' => 1, 'fecha_servicio' => '2026-10-06', 'procedimiento' => 'Consulta',
        'monto_base' => 100000, 'porcentaje' => 40, 'monto' => 40000, 'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]);
}

test('corre de verdad contra PostgreSQL, con las restricciones y los índices creados', function () {
    $restricciones = fn (string $tabla) => collect(DB::select("select conname from pg_constraint where conrelid = '{$tabla}'::regclass and contype = 'c'"))
        ->pluck('conname')->sort()->values()->all();
    $indices = fn (string $tabla) => collect(DB::select('select indexname from pg_indexes where tablename = ?', [$tabla]))->pluck('indexname')->all();

    expect(DB::connection()->getDatabaseName())->toBe('clinexa_test')
        ->and($restricciones('liquidaciones_beneficiario'))->toBe(['liquidaciones_beneficiario_anulacion_completa', 'liquidaciones_beneficiario_aprobacion_completa',
            'liquidaciones_beneficiario_clave_valida', 'liquidaciones_beneficiario_devolucion_completa', 'liquidaciones_beneficiario_envio_completo',
            'liquidaciones_beneficiario_total_entero'])
        ->and($restricciones('lineas_liquidacion_beneficiario'))->toBe(['lineas_liq_beneficiario_liberacion_completa', 'lineas_liq_beneficiario_monto_entero',
            'lineas_liq_beneficiario_tipo_valido'])
        ->and($restricciones('pagos_beneficiario'))->toBe(['pagos_beneficiario_anulacion_completa', 'pagos_beneficiario_caja_completa', 'pagos_beneficiario_monto_positivo'])
        ->and($indices('liquidaciones_beneficiario'))->toContain('liquidaciones_beneficiario_una_abierta')
        ->and($indices('lineas_liquidacion_beneficiario'))->toContain('lineas_liq_beneficiario_una_ordinaria')->toContain('lineas_liq_beneficiario_un_ajuste')
        ->toContain('lineas_liq_beneficiario_un_saldo');
});

test('una sola abierta por beneficiario (el mismo profesional como dueño de equipo es otro beneficiario); aprobadas y anuladas no cuentan', function () {
    $proveedor = DB::table('proveedores')->insertGetId(['persona_id' => test()->profesional->persona_id, 'created_at' => now(), 'updated_at' => now(),
        'estado_id' => Estado::idDe(Estado::ACTIVO)]);
    $primera = pgLiqBenefLiquidacion();

    expect(pgLiqBenefRechazo(fn () => pgLiqBenefLiquidacion()))->toBe(['23505', 'liquidaciones_beneficiario_una_abierta'])
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLiquidacion(['beneficiario' => 'EQUIPO', 'profesional_id' => null, 'proveedor_id' => $proveedor])))->toBeNull()
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLiquidacion(['profesional_id' => test()->otroProfesional->id])))->toBeNull()
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLiquidacion(pgLiqBenefAprobada())))->toBeNull();
    DB::table('liquidaciones_beneficiario')->where('id', $primera)->update(pgLiqBenefAprobada());
    expect(pgLiqBenefRechazo(fn () => pgLiqBenefLiquidacion()))->toBeNull();
});

test('las líneas: una ordinaria por vez, un ajuste por ordinaria y un saldo trasladado una vez (liberadas, quedan libres)', function () {
    pgLiqBenefCobrada();
    $primera = pgLiqBenefLiquidacion(pgLiqBenefAprobada());
    $segunda = pgLiqBenefLiquidacion();
    $ordinaria = pgLiqBenefLinea($primera);

    expect(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($segunda)))->toBe(['23505', 'lineas_liq_beneficiario_una_ordinaria'])
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($segunda, ['vez' => 2])))->toBeNull();

    $ajuste = ['tipo' => 'AJUSTE', 'vez' => null, 'ajusta_linea_id' => $ordinaria, 'monto' => -40000, 'motivo_ajuste' => 'Cobro revertido'];
    $tercera = pgLiqBenefLiquidacion(['profesional_id' => test()->otroProfesional->id]);
    expect(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($segunda, $ajuste)))->toBeNull()
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($tercera, $ajuste)))->toBe(['23505', 'lineas_liq_beneficiario_un_ajuste']);

    $saldo = ['tipo' => 'SALDO_ANTERIOR', 'linea_reparto_id' => null, 'prestacion_id' => null, 'vez' => null, 'saldo_de_liquidacion_id' => $primera, 'monto' => -1000];
    expect(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($segunda, $saldo)))->toBeNull()
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($tercera, $saldo)))->toBe(['23505', 'lineas_liq_beneficiario_un_saldo']);
});

test('CHECK: beneficiario válido, total entero, aprobación por otro, tipos de línea coherentes, pago con su caja completa', function () {
    pgLiqBenefCobrada();
    $id = pgLiqBenefLiquidacion();

    expect(pgLiqBenefRechazo(fn () => pgLiqBenefLiquidacion(['profesional_id' => test()->otroProfesional->id, 'beneficiario' => 'EQUIPO'])))->toBe(['23514', 'liquidaciones_beneficiario_clave_valida'])
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLiquidacion(['profesional_id' => test()->otroProfesional->id, 'total' => 10.5])))->toBe(['23514', 'liquidaciones_beneficiario_total_entero'])
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLiquidacion(['profesional_id' => test()->otroProfesional->id, 'total' => -40000])))->toBeNull()
        ->and(pgLiqBenefRechazo(fn () => DB::table('liquidaciones_beneficiario')->where('id', $id)->update([...pgLiqBenefAprobada(), 'aprobada_por' => test()->facturacion->id])))
        ->toBe(['23514', 'liquidaciones_beneficiario_aprobacion_completa'])
        ->and(pgLiqBenefRechazo(fn () => DB::table('liquidaciones_beneficiario')->where('id', $id)->update(['anulada_en' => now()])))->toBe(['23514', 'liquidaciones_beneficiario_anulacion_completa'])
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($id, ['monto' => -1])))->toBe(['23514', 'lineas_liq_beneficiario_tipo_valido'])
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($id, ['tipo' => 'AJUSTE', 'vez' => null, 'monto' => -40000])))->toBe(['23514', 'lineas_liq_beneficiario_tipo_valido'])
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($id, ['monto' => 100.5])))->toBe(['23514', 'lineas_liq_beneficiario_monto_entero'])
        ->and(pgLiqBenefRechazo(fn () => pgLiqBenefLinea($id, ['liberacion' => 'OTRA', 'liberada_en' => now(), 'liberada_por' => test()->facturacion->id, 'motivo_liberacion' => 'X'])))
        ->toBe(['23514', 'lineas_liq_beneficiario_liberacion_completa']);

    DB::table('liquidaciones_beneficiario')->where('id', $id)->update(pgLiqBenefAprobada());
    $pago = fn (array $datos) => DB::table('pagos_beneficiario')->insert(['liquidacion_beneficiario_id' => $id, 'monto' => 10000, 'fecha' => '2026-10-06',
        'medio_pago_id' => MedioPago::where('codigo', 'TRANSFERENCIA')->value('id'), 'estado_id' => Estado::idDe(Estado::VIGENTE), 'usuario_id' => test()->facturacion->id,
        'created_at' => now(), 'updated_at' => now(), ...$datos]);
    expect(pgLiqBenefRechazo(fn () => $pago(['monto' => 0])))->toBe(['23514', 'pagos_beneficiario_monto_positivo'])
        ->and(pgLiqBenefRechazo(fn () => $pago(['caja_id' => DB::table('cajas')->value('id')])))->toBe(['23514', 'pagos_beneficiario_caja_completa'])
        ->and(pgLiqBenefRechazo(fn () => $pago(['anulado_en' => now()])))->toBe(['23514', 'pagos_beneficiario_anulacion_completa'])
        ->and(pgLiqBenefRechazo(fn () => $pago([])))->toBeNull();
});

test('la migración, con prestaciones, repartos y cobros ya cargados: no los toca ni liquida nada; el down se niega con liquidaciones cargadas', function () {
    pgLiqBenefCobrada();
    $foto = fn () => collect(['prestaciones', 'repartos_prestacion', 'lineas_reparto', 'cobros', 'cobro_prestaciones', 'lineas_cobro', 'cajas', 'liquidaciones', 'facturas'])
        ->mapWithKeys(fn (string $tabla) => [$tabla => DB::table($tabla)->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all()])->all();
    $migracion = require base_path('database/migrations/2026_11_11_100001_create_liquidacion_beneficiarios.php');
    $migracion->down();
    $antes = $foto();

    $migracion->up();
    expect($foto())->toBe($antes)->and(DB::table('liquidaciones_beneficiario')->count())->toBe(0);

    ArmarLiquidacionBeneficiario::ejecutar($this->facturacion, 'PROFESIONAL', $this->profesional->id, Carbon::parse('2026-10-06'));
    expect(fn () => $migracion->down())->toThrow(RuntimeException::class, 'no se revierte')
        ->and(DB::table('liquidaciones_beneficiario')->count())->toBe(1);
});
