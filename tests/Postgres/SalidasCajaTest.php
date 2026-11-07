<?php

use App\Models\Caja;
use App\Models\CategoriaGasto;
use App\Models\Cobro;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\SalidaCaja;
use App\Models\SolicitudReversionCobro;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de las salidas de caja en PostgreSQL (docs/cobros-caja.md): el CHECK de movimientos_caja con SALIDA y
 * ANULACION_SALIDA, un movimiento de cada tipo por salida, la salida válida y en efectivo, la anulación completa, la solicitud de un
 * cobro o de una salida (nunca las dos) con una sola pendiente por salida, y la migración sobre cajas, cobros, cierres, movimientos y
 * solicitudes ya cargados (no los toca). Lo demás está en tests/Feature/Economico/SalidasCajaTest.php. Datos inventados.
 */
beforeEach(function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $procedimiento = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->medico, $procedimiento->id, null, 100000, Carbon::parse('2026-10-01'));
    $this->cajero = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole()])->create();
    $this->efectivo = MedioPago::efectivo();
    $this->categoria = CategoriaGasto::create(['nombre' => 'Varios (prueba)', 'estado_id' => Estado::idDe(Estado::ACTIVO)]);
});

afterEach(fn () => Carbon::setTestNow());

/** La caja abierta del cajero con ₲ 100.000 de inicial, un cobro en efectivo y una salida de ₲ 30.000. */
function pgSalidasCajaEscenario(): array
{
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    test()->actingAs(test()->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '100000'])->assertSessionHas('status');
    test()->post(route('admin.cobros.store'), ['paciente_id' => test()->paciente->id, 'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => test()->efectivo->id, 'monto' => '100000']]])->assertSessionHas('status');
    $caja = Caja::sole();
    test()->post(route('admin.cajas.salidas.store', $caja), ['categoria_gasto_id' => test()->categoria->id, 'beneficiario' => 'Proveedor Inventado',
        'concepto' => 'Pago menor de prueba.', 'monto' => '30000'])->assertSessionHas('status');

    return [$caja, Cobro::sole(), SalidaCaja::sole()];
}

function pgSalidasCajaRechazo(Closure $sentencia): array
{
    try {
        DB::transaction($sentencia);
    } catch (QueryException $e) {
        $restricciones = ['movimientos_caja_tipo_valido', 'movimientos_caja_salida_por_tipo', 'salidas_caja_valida', 'salidas_caja_anulacion_completa',
            'salidas_caja_en_efectivo', 'solicitudes_reversion_objeto', 'solicitudes_anulacion_salida_una_pendiente'];

        return [$e->getCode(), collect($restricciones)->first(fn (string $nombre) => str_contains($e->getMessage(), $nombre))];
    }

    return [null, null];
}

test('los movimientos de una salida: egreso negativo en efectivo, reingreso positivo, uno de cada tipo; los tipos viejos sin salida', function () {
    [$caja, $cobro, $salida] = pgSalidasCajaEscenario();
    $movimiento = fn (string $tipo, int $monto, array $extra = []) => fn () => DB::table('movimientos_caja')->insert([
        'caja_id' => $caja->id, 'tipo' => $tipo, 'medio_pago_id' => $this->efectivo->id, 'monto' => $monto, 'salida_caja_id' => $salida->id,
        'usuario_id' => $this->cajero->id, 'registrado_en' => now(), 'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);

    expect(DB::getDriverName())->toBe('pgsql')->and($caja->esperadoPorMedio())->toBe([$this->efectivo->id => 170000])
        ->and(pgSalidasCajaRechazo($movimiento(MovimientoCaja::SALIDA, -30000)))->toBe(['23505', 'movimientos_caja_salida_por_tipo'])
        ->and(pgSalidasCajaRechazo($movimiento(MovimientoCaja::SALIDA, 30000, ['salida_caja_id' => null])))->toBe(['23514', 'movimientos_caja_tipo_valido'])
        ->and(pgSalidasCajaRechazo($movimiento(MovimientoCaja::ANULACION_SALIDA, -30000)))->toBe(['23514', 'movimientos_caja_tipo_valido'])
        ->and(pgSalidasCajaRechazo($movimiento(MovimientoCaja::ANULACION_SALIDA, 30000, ['salida_caja_id' => null])))->toBe(['23514', 'movimientos_caja_tipo_valido'])
        ->and(pgSalidasCajaRechazo($movimiento(MovimientoCaja::APERTURA, 1000)))->toBe(['23514', 'movimientos_caja_tipo_valido'])
        ->and(pgSalidasCajaRechazo($movimiento('OTRO', -1000)))->toBe(['23514', 'movimientos_caja_tipo_valido']);

    $this->post(route('admin.salidas-caja.anular', $salida), ['motivo' => 'Error de carga (prueba).'])->assertSessionHas('status');
    expect($caja->esperadoPorMedio())->toBe([$this->efectivo->id => 200000])
        ->and(pgSalidasCajaRechazo($movimiento(MovimientoCaja::ANULACION_SALIDA, 30000)))->toBe(['23505', 'movimientos_caja_salida_por_tipo']);
});

test('la salida: monto mayor que cero, beneficiario y concepto, en efectivo y con la anulación completa', function () {
    [, , $salida] = pgSalidasCajaEscenario();
    $fila = fn () => DB::table('salidas_caja')->where('id', $salida->id);
    $transferencia = MedioPago::where('codigo', MedioPago::TRANSFERENCIA)->sole();

    expect(pgSalidasCajaRechazo(fn () => $fila()->update(['monto' => 0])))->toBe(['23514', 'salidas_caja_valida'])
        ->and(pgSalidasCajaRechazo(fn () => $fila()->update(['beneficiario' => '  '])))->toBe(['23514', 'salidas_caja_valida'])
        ->and(pgSalidasCajaRechazo(fn () => $fila()->update(['concepto' => 'abc'])))->toBe(['23514', 'salidas_caja_valida'])
        ->and(pgSalidasCajaRechazo(fn () => $fila()->update(['medio_pago_id' => $transferencia->id])))->toBe(['23503', 'salidas_caja_en_efectivo'])
        ->and(pgSalidasCajaRechazo(fn () => $fila()->update(['anulada_en' => now()])))->toBe(['23514', 'salidas_caja_anulacion_completa']);
});

test('la solicitud: de un cobro o de una salida, nunca las dos ni ninguna; una sola pendiente por salida', function () {
    [, $cobro, $salida] = pgSalidasCajaEscenario();
    $solicitud = fn (array $objeto) => fn () => DB::table('solicitudes_reversion_cobro')->insert([...$objeto, 'usuario_id' => $this->cajero->id,
        'solicitada_en' => now(), 'motivo' => 'Motivo inventado.', 'estado_id' => Estado::idDe(Estado::PENDIENTE), 'created_at' => now(), 'updated_at' => now()]);

    expect(pgSalidasCajaRechazo($solicitud(['cobro_id' => $cobro->id, 'salida_caja_id' => $salida->id])))->toBe(['23514', 'solicitudes_reversion_objeto'])
        ->and(pgSalidasCajaRechazo($solicitud(['cobro_id' => null, 'salida_caja_id' => null])))->toBe(['23514', 'solicitudes_reversion_objeto'])
        ->and(pgSalidasCajaRechazo($solicitud(['salida_caja_id' => $salida->id])))->toBe([null, null])
        ->and(pgSalidasCajaRechazo($solicitud(['salida_caja_id' => $salida->id])))->toBe(['23505', 'solicitudes_anulacion_salida_una_pendiente'])
        ->and(SolicitudReversionCobro::where('salida_caja_id', $salida->id)->count())->toBe(1);

    $indice = collect(DB::select("select indexdef from pg_indexes where indexname = 'solicitudes_anulacion_salida_una_pendiente'"))->sole();
    expect($indice->indexdef)->toContain('UNIQUE')->toContain('(salida_caja_id)')->toContain('WHERE (revisado_en IS NULL)');
});

test('la migración sobre datos existentes: cajas, cobros, cierres, movimientos y solicitudes quedan igual', function () {
    $migracion = require base_path('database/migrations/2026_11_09_100001_salidas_de_caja.php');
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $this->actingAs($this->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '50000'])->assertSessionHas('status');
    $this->post(route('admin.cobros.store'), ['paciente_id' => $this->paciente->id, 'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => $this->efectivo->id, 'monto' => '100000']]])->assertSessionHas('status');
    $caja = Caja::sole();
    $this->post(route('admin.cajas.cerrar', $caja), ['confirmar' => '1', 'contado' => MedioPago::pluck('id')->mapWithKeys(fn ($id) => [$id => $id === $this->efectivo->id ? '150000' : '0'])->all()])
        ->assertSessionHas('status');
    DB::table('solicitudes_reversion_cobro')->insert(['cobro_id' => Cobro::sole()->id, 'usuario_id' => $this->cajero->id, 'solicitada_en' => now(),
        'motivo' => 'Motivo inventado.', 'estado_id' => Estado::idDe(Estado::PENDIENTE), 'created_at' => now(), 'updated_at' => now()]);

    $foto = fn () => collect(['cajas', 'cobros', 'lineas_cobro', 'cierres_caja', 'cierre_caja_medios', 'movimientos_caja', 'solicitudes_reversion_cobro'])
        ->mapWithKeys(fn (string $tabla) => [$tabla => DB::table($tabla)->orderBy('id')->get()
            ->map(fn ($fila) => collect((array) $fila)->except('salida_caja_id')->all())->all()])->all();

    // La liquidación a beneficiarios (posterior) depende de cajas y salidas: se baja antes.
    (require base_path('database/migrations/2026_11_11_100001_create_liquidacion_beneficiarios.php'))->down();
    $migracion->down();
    $antes = $foto();
    expect(DB::getSchemaBuilder()->hasTable('salidas_caja'))->toBeFalse()->and($antes['movimientos_caja'])->toHaveCount(2);

    DB::pretend(fn () => $migracion->up());
    expect(DB::getSchemaBuilder()->hasTable('salidas_caja'))->toBeFalse();

    $migracion->up();
    expect($foto())->toBe($antes)
        ->and(DB::table('movimientos_caja')->whereNotNull('salida_caja_id')->count())->toBe(0)
        ->and(DB::table('solicitudes_reversion_cobro')->whereNotNull('salida_caja_id')->count())->toBe(0)
        ->and(collect(DB::select("select indexname from pg_indexes where tablename = 'solicitudes_reversion_cobro'"))->pluck('indexname')->all())
        ->toContain('solicitudes_reversion_una_pendiente')->toContain('solicitudes_anulacion_salida_una_pendiente');

    // Con salidas cargadas, no se deshace.
    expect(fn () => DB::transaction(function () use ($migracion) {
        DB::table('salidas_caja')->insert(['caja_id' => Caja::sole()->id, 'categoria_gasto_id' => $this->categoria->id, 'beneficiario' => 'X', 'concepto' => 'Concepto de prueba.',
            'monto' => 1000, 'medio_pago_id' => $this->efectivo->id, 'usuario_id' => $this->cajero->id, 'registrada_en' => now(),
            'estado_id' => Estado::idDe(Estado::VIGENTE), 'created_at' => now(), 'updated_at' => now()]);
        $migracion->down();
    }))->toThrow(RuntimeException::class);
});
