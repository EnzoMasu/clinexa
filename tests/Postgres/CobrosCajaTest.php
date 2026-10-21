<?php

use App\Models\Caja;
use App\Models\Cobro;
use App\Models\CobroPrestacion;
use App\Models\Consulta;
use App\Models\MedioPago;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de cobros y caja en PostgreSQL (docs/cobros-caja.md): una caja abierta por cajero
 * (cajas_una_abierta_por_cajero) y a lo sumo un cobro vigente por prestación (cobro_prestaciones_un_cobro_vigente), índices únicos
 * parciales, y los CHECK de montos. La migración, con prestaciones ya existentes. Lo demás está en tests/Feature/Economico. Datos
 * inventados.
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
});

afterEach(fn () => Carbon::setTestNow());

/** Una prestación tarifada de una consulta finalizada, cobrada en efectivo por la caja abierta del cajero. */
function pgCobrosCajaCobrada(): Prestacion
{
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    test()->actingAs(test()->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
    test()->post(route('admin.cobros.store'), ['paciente_id' => test()->paciente->id, 'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => test()->efectivo->id, 'monto' => '100000']]])->assertSessionHas('status');

    return $prestacion;
}

/** El código y la restricción con que la base rechaza la sentencia (en su propio savepoint). */
function pgCobrosCajaRechazo(\Closure $sentencia): array
{
    try {
        DB::transaction($sentencia);
    } catch (QueryException $e) {
        $restricciones = ['cajas_una_abierta_por_cajero', 'cobro_prestaciones_un_cobro_vigente', 'cobros_total_positivo', 'lineas_cobro_monto_positivo',
            'cajas_monto_inicial_valido', 'movimientos_caja_tipo_valido'];

        return [$e->getCode(), collect($restricciones)->first(fn (string $nombre) => str_contains($e->getMessage(), $nombre))];
    }

    return [null, null];
}

test('los dos índices únicos parciales existen con su condición', function () {
    $indices = collect(DB::select("select tablename, indexname, indexdef from pg_indexes where indexname in ('cajas_una_abierta_por_cajero', 'cobro_prestaciones_un_cobro_vigente')"))
        ->keyBy('indexname');

    expect(DB::getDriverName())->toBe('pgsql')
        ->and($indices['cajas_una_abierta_por_cajero']->indexdef)->toContain('UNIQUE')->toContain('(usuario_id)')->toContain('WHERE (cerrada_en IS NULL)')
        ->and($indices['cobro_prestaciones_un_cobro_vigente']->indexdef)->toContain('UNIQUE')->toContain('(prestacion_id)')->toContain('WHERE (revertido_en IS NULL)');
});

test('una caja abierta por cajero: la base rechaza la segunda abierta; cerrada, se abre otra', function () {
    $this->actingAs($this->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
    $caja = Caja::sole();

    expect(pgCobrosCajaRechazo(fn () => $caja->replicate()->save()))->toBe(['23505', 'cajas_una_abierta_por_cajero'])
        ->and(Caja::count())->toBe(1);

    $this->post(route('admin.cajas.cerrar', $caja), ['contado' => MedioPago::pluck('id')->mapWithKeys(fn ($id) => [$id => '0'])->all()])->assertSessionHas('status');
    $this->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
    expect(Caja::count())->toBe(2)->and(Caja::whereNull('cerrada_en')->count())->toBe(1);
});

test('un cobro vigente por prestación: la base rechaza el segundo; uno revertido no cuenta', function () {
    $prestacion = pgCobrosCajaCobrada();
    $cobro = Cobro::sole();
    $otro = $cobro->replicate();
    $otro->save();

    expect(pgCobrosCajaRechazo(fn () => DB::table('cobro_prestaciones')->insert([
        'cobro_id' => $otro->id, 'prestacion_id' => $prestacion->id, 'monto' => 100000, 'created_at' => now(), 'updated_at' => now(),
    ])))->toBe(['23505', 'cobro_prestaciones_un_cobro_vigente'])
        ->and(CobroPrestacion::where('prestacion_id', $prestacion->id)->count())->toBe(1);

    // Revertido (lo que hará la fase 3b), ya no es el vigente: la base admite otro.
    DB::table('cobro_prestaciones')->where('prestacion_id', $prestacion->id)->update(['revertido_en' => now()]);
    DB::table('cobro_prestaciones')->insert(['cobro_id' => $otro->id, 'prestacion_id' => $prestacion->id, 'monto' => 100000, 'created_at' => now(), 'updated_at' => now()]);
    expect(CobroPrestacion::where('prestacion_id', $prestacion->id)->count())->toBe(2)
        ->and(CobroPrestacion::where('prestacion_id', $prestacion->id)->whereNull('revertido_en')->count())->toBe(1);
});

test('los CHECK de montos', function () {
    $prestacion = pgCobrosCajaCobrada();
    $cobro = Cobro::sole();
    $caja = Caja::sole();

    expect(pgCobrosCajaRechazo(fn () => DB::table('cobros')->where('id', $cobro->id)->update(['total' => 0])))->toBe(['23514', 'cobros_total_positivo'])
        ->and(pgCobrosCajaRechazo(fn () => DB::table('lineas_cobro')->update(['monto' => -1])))->toBe(['23514', 'lineas_cobro_monto_positivo'])
        ->and(pgCobrosCajaRechazo(fn () => DB::table('cajas')->update(['monto_inicial' => -1])))->toBe(['23514', 'cajas_monto_inicial_valido'])
        ->and(pgCobrosCajaRechazo(fn () => DB::table('movimientos_caja')->insert([
            'caja_id' => $caja->id, 'tipo' => 'COBRO', 'medio_pago_id' => $this->efectivo->id, 'monto' => 10, 'usuario_id' => $this->cajero->id,
            'registrado_en' => now(), 'created_at' => now(), 'updated_at' => now(),
        ])))->toBe(['23514', 'movimientos_caja_tipo_valido'])
        ->and((int) $cobro->fresh()->total)->toBe(100000)->and($prestacion->fresh()->estadoCobroTexto())->toBe(Prestacion::COBRADA);
});

test('la migración con prestaciones ya existentes: no las cobra; en simulación no cambia nada', function () {
    $migracion = require base_path('database/migrations/2026_10_28_100001_create_cobros_y_caja.php');
    hcConsulta(); // una prestación tarifada existente
    $migracion->down();
    $antes = DB::table('prestaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all();
    expect($antes)->toHaveCount(1);

    DB::pretend(fn () => $migracion->up());
    expect(DB::getSchemaBuilder()->hasTable('cajas'))->toBeFalse()->and(DB::getSchemaBuilder()->hasColumn('medios_pago', 'codigo'))->toBeFalse();

    $migracion->up();
    expect(DB::table('prestaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all())->toBe($antes)
        ->and(DB::table('cobros')->count())->toBe(0)
        ->and(Prestacion::sole()->estadoCobroTexto())->toBe(Prestacion::PENDIENTE_DE_COBRO)
        ->and(collect(DB::select("select indexname from pg_indexes where tablename in ('cajas', 'cobro_prestaciones')"))->pluck('indexname')->all())
        ->toContain('cajas_una_abierta_por_cajero')->toContain('cobro_prestaciones_un_cobro_vigente');
});
