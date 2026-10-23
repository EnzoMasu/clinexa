<?php

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LineaVisacion;
use App\Models\Moneda;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\ResponsablePago;
use App\Models\User;
use App\Support\Convenios\CalcularCobertura;
use App\Support\Convenios\GestionConvenios;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de convenios y visaciones en PostgreSQL (docs/convenios-seguros.md): una sola cobertura por paciente cada día
 * (coberturas_paciente_sin_solapamiento, EXCLUDE) y una abierta (coberturas_una_vigente); la tarifa del convenio sin solapamiento
 * (tarifas_convenio_sin_solapamiento); el número de visación único por responsable de pago entre las no anuladas
 * (visaciones_numero_unico, índice único parcial); los CHECK de la visación y de las partes de la prestación; y la migración con
 * prestaciones ya existentes. Lo demás está en tests/Feature/Economico. Datos inventados. Prefijo "pgConvenios".
 */
beforeEach(function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    $this->consultaProc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, CalcularCobertura::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->medico, $this->consultaProc->id, null, 100000, Carbon::parse('2026-10-01'));
    $this->usuario = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::FACTURACION)->sole()])->create();
    $this->aseguradora = pgConveniosResponsable();
    $this->convenio = GestionConvenios::alta($this->usuario, $this->aseguradora->id, 'Convenio de prueba', Carbon::parse('2026-10-01'), null, ['Plan Oro']);
    $this->plan = $this->convenio->planes()->sole();
});

afterEach(fn () => Carbon::setTestNow());

function pgConveniosResponsable(): ResponsablePago
{
    return ResponsablePago::create(['persona_id' => Persona::factory()->create()->id]);
}

/** El código y la restricción con que la base rechaza la sentencia (en su propio savepoint), o null si la acepta. */
function pgConveniosRechazo(\Closure $sentencia): ?array
{
    try {
        DB::transaction($sentencia);
    } catch (QueryException $e) {
        $restricciones = ['coberturas_paciente_sin_solapamiento', 'coberturas_una_vigente', 'tarifas_convenio_sin_solapamiento', 'tarifas_convenio_una_vigente',
            'visaciones_numero_unico', 'visaciones_turno_o_consulta', 'lineas_visacion_valores_validos', 'prestaciones_partes_validas', 'prestaciones_pagador_valido',
            'prestaciones_liquidacion_valida'];

        return [$e->getCode(), collect($restricciones)->first(fn (string $nombre) => str_contains($e->getMessage(), $nombre))];
    }

    return null;
}

function pgConveniosCobertura(array $datos): void
{
    DB::table('coberturas_paciente')->insert([
        'paciente_id' => test()->paciente->id, 'plan_convenio_id' => test()->plan->id, 'numero_asegurado' => 'AS-1', 'tipo_afiliado' => 'TITULAR',
        'vigente_desde' => '2026-10-01', 'vigente_hasta' => null, 'estado_id' => Estado::idDe(Estado::HISTORICO), 'cerrada_en' => now(), 'cerrada_por' => test()->usuario->id,
        'usuario_id' => test()->usuario->id, 'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]);
}

function pgConveniosTarifa(array $datos): void
{
    DB::table('tarifas_convenio')->insert([
        'convenio_id' => test()->convenio->id, 'procedimiento_id' => test()->consultaProc->id, 'monto' => 90000, 'moneda_id' => Moneda::guarani()->id,
        'vigente_desde' => '2026-10-01', 'vigente_hasta' => null, 'estado_id' => Estado::idDe(Estado::VIGENTE), 'usuario_id' => test()->usuario->id,
        'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]);
}

test('corre de verdad contra PostgreSQL, con las restricciones creadas', function () {
    $restricciones = fn (string $tabla) => collect(DB::select("select conname from pg_constraint where conrelid = '{$tabla}'::regclass and contype in ('x', 'c')"))
        ->pluck('conname')->sort()->values()->all();

    expect(DB::getDriverName())->toBe('pgsql')->and(DB::connection()->getDatabaseName())->toBe('clinexa_test')
        ->and($restricciones('coberturas_paciente'))->toBe(['coberturas_paciente_cierre_completo', 'coberturas_paciente_rango_valido', 'coberturas_paciente_sin_solapamiento', 'coberturas_paciente_tipo_valido'])
        ->and($restricciones('tarifas_convenio'))->toBe(['tarifas_convenio_monto_positivo', 'tarifas_convenio_rango_valido', 'tarifas_convenio_sin_solapamiento'])
        ->and($restricciones('visaciones'))->toBe(['visaciones_anulacion_completa', 'visaciones_turno_o_consulta'])
        ->and(collect(DB::select("select indexname from pg_indexes where tablename = 'visaciones'"))->pluck('indexname')->all())->toContain('visaciones_numero_unico');
});

test('cobertura: los rangos de una misma paciente no se solapan y hay una sola abierta', function () {
    pgConveniosCobertura(['vigente_desde' => '2026-10-01', 'vigente_hasta' => '2026-10-10']);

    expect(pgConveniosRechazo(fn () => pgConveniosCobertura(['vigente_desde' => '2026-10-10', 'vigente_hasta' => '2026-10-20'])))
        ->toBe(['23P01', 'coberturas_paciente_sin_solapamiento'])
        // Sin vencimiento, desde dentro del rango cerrado: también se solapa.
        ->and(pgConveniosRechazo(fn () => pgConveniosCobertura(['vigente_desde' => '2026-10-05', 'cerrada_en' => null, 'cerrada_por' => null, 'estado_id' => Estado::idDe(Estado::VIGENTE)])))
        ->toBe(['23P01', 'coberturas_paciente_sin_solapamiento']);

    // Consecutiva, abierta: entra. Una segunda abierta (aunque no se solape), no.
    expect(pgConveniosRechazo(fn () => pgConveniosCobertura(['vigente_desde' => '2026-10-11', 'vigente_hasta' => '2026-10-31', 'cerrada_en' => null, 'cerrada_por' => null, 'estado_id' => Estado::idDe(Estado::VIGENTE)])))->toBeNull()
        ->and(pgConveniosRechazo(fn () => pgConveniosCobertura(['vigente_desde' => '2026-11-01', 'cerrada_en' => null, 'cerrada_por' => null, 'estado_id' => Estado::idDe(Estado::VIGENTE)])))
        ->toBe(['23505', 'coberturas_una_vigente'])
        // Otra paciente, en las mismas fechas, no choca.
        ->and(pgConveniosRechazo(fn () => pgConveniosCobertura(['paciente_id' => \App\Models\Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000099'])->id])))->toBeNull()
        ->and(DB::table('coberturas_paciente')->count())->toBe(3);
});

test('tarifa del convenio: sin solapamiento por convenio y procedimiento, una sola abierta, monto positivo', function () {
    $otro = GestionConvenios::alta(test()->usuario, pgConveniosResponsable()->id, 'Otro', Carbon::parse('2026-10-01'), null, []);
    pgConveniosTarifa(['vigente_desde' => '2026-10-01', 'vigente_hasta' => '2026-10-10', 'estado_id' => Estado::idDe(Estado::HISTORICO)]);

    expect(pgConveniosRechazo(fn () => pgConveniosTarifa(['vigente_desde' => '2026-10-10'])))->toBe(['23P01', 'tarifas_convenio_sin_solapamiento'])
        ->and(pgConveniosRechazo(fn () => pgConveniosTarifa(['vigente_desde' => '2026-10-11'])))->toBeNull()
        // Una segunda abierta: la frenan el único parcial y el solape.
        ->and(pgConveniosRechazo(fn () => pgConveniosTarifa(['vigente_desde' => '2027-01-01'])))->not->toBeNull()
        ->and(pgConveniosRechazo(fn () => pgConveniosTarifa(['convenio_id' => $otro->id, 'monto' => 0])))->toBe(['23514', null])
        // Otro convenio, mismo procedimiento y fechas: convive.
        ->and(pgConveniosRechazo(fn () => pgConveniosTarifa(['convenio_id' => $otro->id])))->toBeNull()
        ->and(DB::table('tarifas_convenio')->count())->toBe(3);
});

test('número de visación: único por responsable de pago entre las no anuladas; turno o consulta, y líneas válidas', function () {
    pgConveniosCobertura(['cerrada_en' => null, 'cerrada_por' => null, 'estado_id' => Estado::idDe(Estado::VIGENTE)]);
    $coberturaId = DB::table('coberturas_paciente')->value('id');
    $turno = hcTurno();
    $visacion = fn (array $datos = []) => DB::table('visaciones')->insertGetId([
        'cobertura_paciente_id' => $coberturaId, 'responsable_pago_id' => test()->aseguradora->id, 'turno_id' => $turno->id, 'consulta_id' => null,
        'numero' => 'V-1', 'fecha' => '2026-10-06', 'estado_id' => Estado::idDe(Estado::VIGENTE), 'usuario_id' => test()->usuario->id,
        'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]);
    $primera = $visacion();

    expect(pgConveniosRechazo(fn () => $visacion()))->toBe(['23505', 'visaciones_numero_unico'])
        // Otra aseguradora puede usar el mismo número; una anulada no cuenta.
        ->and(pgConveniosRechazo(fn () => $visacion(['responsable_pago_id' => pgConveniosResponsable()->id])))->toBeNull()
        ->and(pgConveniosRechazo(fn () => $visacion(['anulada_en' => now(), 'motivo_anulacion' => 'Prueba', 'anulada_por' => test()->usuario->id, 'estado_id' => Estado::idDe(Estado::ANULADO)])))->toBeNull()
        ->and(pgConveniosRechazo(fn () => $visacion(['numero' => 'V-2', 'consulta_id' => null, 'turno_id' => null])))->toBe(['23514', 'visaciones_turno_o_consulta']);

    $linea = fn (array $datos) => DB::table('lineas_visacion')->insert(['visacion_id' => $primera, 'procedimiento_id' => test()->consultaProc->id,
        'created_at' => now(), 'updated_at' => now(), ...$datos]);
    expect(pgConveniosRechazo(fn () => $linea(['resultado' => LineaVisacion::APROBADO, 'copago' => LineaVisacion::PORCENTAJE, 'porcentaje' => 120])))
        ->toBe(['23514', 'lineas_visacion_valores_validos'])
        ->and(pgConveniosRechazo(fn () => $linea(['resultado' => LineaVisacion::NO_CUBIERTO, 'copago' => LineaVisacion::NINGUNO])))->toBe(['23514', 'lineas_visacion_valores_validos'])
        ->and(pgConveniosRechazo(fn () => $linea(['resultado' => LineaVisacion::APROBADO, 'copago' => LineaVisacion::MONTO_FIJO, 'monto' => 50000])))->toBeNull();
});

test('prestación: las partes suman el total y lo de la aseguradora exige su responsable y visación', function () {
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    expect($prestacion->pagador)->toBe(Prestacion::PACIENTE)->and((int) $prestacion->monto_paciente)->toBe(100000);
    $cambio = fn (array $datos) => DB::table('prestaciones')->where('id', $prestacion->id)->update($datos);

    expect(pgConveniosRechazo(fn () => $cambio(['monto_paciente' => 90000])))->toBe(['23514', 'prestaciones_partes_validas'])
        ->and(pgConveniosRechazo(fn () => $cambio(['pagador' => Prestacion::ASEGURADORA])))->toBe(['23514', 'prestaciones_pagador_valido'])
        ->and(pgConveniosRechazo(fn () => $cambio(['liquidacion' => Prestacion::PENDIENTE_DE_LIQUIDAR])))->toBe(['23514', 'prestaciones_liquidacion_valida'])
        ->and(pgConveniosRechazo(fn () => $cambio(['monto_paciente' => 0, 'monto_aseguradora' => 100000])))->toBe(['23514', 'prestaciones_pagador_valido']);
});

test('la migración, con prestaciones ya existentes: no las recalcula (quedan sin snapshot) y respeta las restricciones', function () {
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $migracion = require base_path('database/migrations/2026_10_30_100001_create_convenios_y_visaciones.php');
    $migracion->down();
    $antes = (array) DB::table('prestaciones')->where('id', $prestacion->id)->first(['id', 'monto', 'tarifa_id', 'estado_id', 'moneda_id']);

    $migracion->up();

    expect((array) DB::table('prestaciones')->where('id', $prestacion->id)->first(['id', 'monto', 'tarifa_id', 'estado_id', 'moneda_id']))->toBe($antes)
        ->and(DB::table('prestaciones')->where('id', $prestacion->id)->value('pagador'))->toBeNull()
        ->and(DB::table('prestaciones')->where('id', $prestacion->id)->value('monto_paciente'))->toBeNull();
});
