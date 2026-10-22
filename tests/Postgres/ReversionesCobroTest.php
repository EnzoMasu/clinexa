<?php

use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
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
 * Las restricciones de la reversión de cobros en PostgreSQL (docs/cobros-caja.md): una sola solicitud pendiente por cobro
 * (solicitudes_reversion_una_pendiente, índice único parcial), una devolución por línea (movimientos_caja_linea_por_tipo), los CHECK
 * del egreso, de la reversión completa y del cierre (negativos solo fuera del efectivo), y la migración con cobros ya existentes. Lo demás está en tests/Feature/Economico. Datos
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
    $this->otroCajero = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole()])->create();
    $this->efectivo = MedioPago::efectivo();
});

afterEach(fn () => Carbon::setTestNow());

/** Un cobro en efectivo de una prestación de una consulta finalizada, en la caja abierta del cajero. */
function pgReversionesCobroCobro(): Cobro
{
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    test()->actingAs(test()->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
    test()->post(route('admin.cobros.store'), ['paciente_id' => test()->paciente->id, 'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => test()->efectivo->id, 'monto' => '100000']]])->assertSessionHas('status');

    return Cobro::latest('id')->firstOrFail();
}

/** El código y la restricción con que la base rechaza la sentencia (en su propio savepoint). */
function pgReversionesCobroRechazo(\Closure $sentencia): array
{
    try {
        DB::transaction($sentencia);
    } catch (QueryException $e) {
        $restricciones = ['solicitudes_reversion_una_pendiente', 'movimientos_caja_linea_por_tipo', 'movimientos_caja_tipo_valido',
            'cobros_reversion_completa', 'solicitudes_reversion_revision_completa', 'cierre_caja_medios_valores_validos',
            'cierre_caja_medios_es_efectivo_del_medio', 'medios_pago_un_efectivo'];

        return [$e->getCode(), collect($restricciones)->first(fn (string $nombre) => str_contains($e->getMessage(), $nombre))];
    }

    return [null, null];
}

function pgReversionesCobroSolicitud(Cobro $cobro, User $usuario): array
{
    return ['cobro_id' => $cobro->id, 'usuario_id' => $usuario->id, 'solicitada_en' => now(), 'motivo' => 'Motivo inventado.',
        'estado_id' => Estado::idDe(Estado::PENDIENTE), 'created_at' => now(), 'updated_at' => now()];
}

test('el índice único parcial de solicitudes existe con su condición', function () {
    $indice = collect(DB::select("select indexdef from pg_indexes where indexname = 'solicitudes_reversion_una_pendiente'"))->sole();

    expect(DB::getDriverName())->toBe('pgsql')
        ->and($indice->indexdef)->toContain('UNIQUE')->toContain('(cobro_id)')->toContain('WHERE (revisado_en IS NULL)');
});

test('una sola solicitud pendiente por cobro: la base rechaza la segunda; revisada, admite otra', function () {
    $cobro = pgReversionesCobroCobro();
    DB::table('solicitudes_reversion_cobro')->insert(pgReversionesCobroSolicitud($cobro, $this->otroCajero));

    expect(pgReversionesCobroRechazo(fn () => DB::table('solicitudes_reversion_cobro')->insert(pgReversionesCobroSolicitud($cobro, $this->cajero))))
        ->toBe(['23505', 'solicitudes_reversion_una_pendiente'])
        ->and(SolicitudReversionCobro::where('cobro_id', $cobro->id)->count())->toBe(1);

    // Rechazada (revisada), ya no es la pendiente: la base admite otra.
    DB::table('solicitudes_reversion_cobro')->update(['estado_id' => Estado::idDe(Estado::RECHAZADO), 'revisado_en' => now(), 'revisado_por' => $this->cajero->id]);
    DB::table('solicitudes_reversion_cobro')->insert(pgReversionesCobroSolicitud($cobro, $this->otroCajero));
    expect(SolicitudReversionCobro::where('cobro_id', $cobro->id)->count())->toBe(2)
        ->and(SolicitudReversionCobro::where('cobro_id', $cobro->id)->whereNull('revisado_en')->count())->toBe(1)
        ->and(pgReversionesCobroRechazo(fn () => DB::table('solicitudes_reversion_cobro')->where('revisado_en', null)->update(['revisado_en' => now()])))
        ->toBe(['23514', 'solicitudes_reversion_revision_completa']);
});

test('la reversión por la pantalla y los CHECK: egreso negativo, una devolución por línea y la reversión completa', function () {
    $cobro = pgReversionesCobroCobro();
    $this->post(route('admin.cobros.revertir', $cobro), ['motivo' => 'Motivo inventado de prueba.'])->assertSessionHas('status');
    $cobro->refresh();
    $linea = $cobro->lineas()->sole();
    $caja = Caja::sole();
    $egreso = fn (int $monto) => fn () => DB::table('movimientos_caja')->insert([
        'caja_id' => $caja->id, 'tipo' => MovimientoCaja::REVERSION, 'medio_pago_id' => $this->efectivo->id, 'monto' => $monto,
        'cobro_id' => $cobro->id, 'linea_cobro_id' => $linea->id, 'usuario_id' => $this->cajero->id,
        'registrado_en' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($cobro->revertido())->toBeTrue()->and($caja->esperadoPorMedio())->toBe([$this->efectivo->id => 0])
        ->and(pgReversionesCobroRechazo($egreso(-100000)))->toBe(['23505', 'movimientos_caja_linea_por_tipo'])
        ->and(pgReversionesCobroRechazo($egreso(100000)))->toBe(['23514', 'movimientos_caja_tipo_valido'])
        ->and(pgReversionesCobroRechazo(fn () => DB::table('cobros')->where('id', $cobro->id)->update(['caja_reversion_id' => null])))->toBe(['23514', 'cobros_reversion_completa'])
        ->and(MovimientoCaja::where('tipo', MovimientoCaja::REVERSION)->count())->toBe(1);
});

test('el cierre: lo esperado y lo contado negativos solo fuera del efectivo; la marca del efectivo es la de su medio', function () {
    pgReversionesCobroCobro();
    $caja = Caja::sole();
    $this->post(route('admin.cajas.cerrar', $caja), ['contado' => MedioPago::pluck('id')->mapWithKeys(fn ($id) => [$id => $id === $this->efectivo->id ? '100000' : '0'])->all()])
        ->assertSessionHas('status');
    $transferencia = MedioPago::where('codigo', MedioPago::TRANSFERENCIA)->sole();
    $fila = fn (MedioPago $medio) => DB::table('cierre_caja_medios')->where('medio_pago_id', $medio->id);

    $fila($transferencia)->update(['esperado' => -150000, 'contado' => -150000, 'diferencia' => 0]);
    expect((int) $fila($transferencia)->value('esperado'))->toBe(-150000)
        ->and((bool) $fila($this->efectivo)->value('es_efectivo'))->toBeTrue()
        ->and(pgReversionesCobroRechazo(fn () => $fila($this->efectivo)->update(['esperado' => -1, 'contado' => -1, 'diferencia' => 0])))->toBe(['23514', 'cierre_caja_medios_valores_validos'])
        ->and(pgReversionesCobroRechazo(fn () => $fila($transferencia)->update(['diferencia' => 5])))->toBe(['23514', 'cierre_caja_medios_valores_validos'])
        // La marca no se separa de la del medio de pago, y hay a lo sumo un medio efectivo.
        ->and(pgReversionesCobroRechazo(fn () => $fila($this->efectivo)->update(['es_efectivo' => false])))->toBe(['23503', 'cierre_caja_medios_es_efectivo_del_medio'])
        ->and(pgReversionesCobroRechazo(fn () => DB::table('medios_pago')->where('id', $transferencia->id)->update(['es_efectivo' => true])))->toBe(['23505', 'medios_pago_un_efectivo'])
        ->and(pgReversionesCobroRechazo(fn () => DB::table('medios_pago')->where('id', $this->efectivo->id)->update(['es_efectivo' => false])))->toBe(['23503', 'cierre_caja_medios_es_efectivo_del_medio']);
});

test('la migración con cobros ya existentes: no los revierte; en simulación no cambia nada', function () {
    $migracion = require base_path('database/migrations/2026_10_29_100001_reversiones_de_cobro.php');
    $cobro = pgReversionesCobroCobro();
    $migracion->down();
    $antes = DB::table('cobros')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all();
    expect($antes)->toHaveCount(1);

    DB::pretend(fn () => $migracion->up());
    expect(DB::getSchemaBuilder()->hasTable('solicitudes_reversion_cobro'))->toBeFalse()->and(DB::getSchemaBuilder()->hasColumn('cobros', 'revertido_en'))->toBeFalse();

    $migracion->up();
    $despues = DB::table('cobros')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all();
    expect(array_map(fn (array $fila) => array_intersect_key($fila, $antes[0]), $despues))->toBe($antes)
        ->and($despues[0]['revertido_en'])->toBeNull()->and($despues[0]['caja_reversion_id'])->toBeNull()
        ->and($cobro->fresh()->tieneEstado(Estado::VIGENTE))->toBeTrue()
        ->and(DB::table('cobro_prestaciones')->whereNotNull('revertido_en')->count())->toBe(0)
        ->and(DB::table('medios_pago')->where('es_efectivo', true)->pluck('codigo')->all())->toBe([MedioPago::EFECTIVO])
        ->and(collect(DB::select("select indexname from pg_indexes where tablename in ('solicitudes_reversion_cobro', 'movimientos_caja')"))->pluck('indexname')->all())
        ->toContain('solicitudes_reversion_una_pendiente')->toContain('movimientos_caja_linea_por_tipo');
});
