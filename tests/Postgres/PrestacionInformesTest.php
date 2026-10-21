<?php

use App\Models\Consulta;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Support\Prestaciones\GenerarPrestacion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de la prestación de cada informe de estudio en PostgreSQL (docs/tarifas-prestaciones.md): la base, única por
 * consulta (prestaciones_base_unica), y la de cada informe, única por informe (prestaciones_informe_unica), índices únicos
 * parciales. La migración, con prestaciones ya existentes. Lo demás está en tests/Feature/Economico. Datos inventados.
 */
beforeEach(function () {
    hcEscenario();
    Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->eco = Procedimiento::create(['codigo' => 'ECO-PRUEBA', 'nombre' => 'Ecografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES])->update(['fecha_hora' => '2026-10-01 00:00:00']);
});

afterEach(fn () => Carbon::setTestNow());

/** Consulta sin turno finalizada con un informe de ecografía: la base y la del informe. */
function pgPrestacionInformesConsulta(): Consulta
{
    hcGuardarNueva(['con_informes' => '1', 'informes' => [['id' => '', 'activo' => '1', 'procedimiento_id' => (string) test()->eco->id, 'texto' => 'Informe inventado.']]])
        ->assertSessionHasNoErrors();

    return Consulta::latest('id')->firstOrFail();
}

/** El código y la restricción con que la base rechaza la copia (en su propio savepoint). */
function pgPrestacionInformesRechazo(Prestacion $prestacion): array
{
    try {
        DB::transaction(fn () => $prestacion->replicate()->save());
    } catch (QueryException $e) {
        return [$e->getCode(), preg_match('/prestaciones_\w+_unica/', $e->getMessage(), $nombre) ? $nombre[0] : null];
    }

    return [null, null];
}

test('los índices únicos parciales existen y el único anterior por consulta ya no', function () {
    $indices = collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'prestaciones'"))->pluck('indexdef', 'indexname');

    expect(DB::getDriverName())->toBe('pgsql')
        ->and($indices['prestaciones_base_unica'])->toContain('UNIQUE')->toContain('(consulta_id)')->toContain('WHERE (informe_estudio_id IS NULL)')
        ->and($indices['prestaciones_informe_unica'])->toContain('UNIQUE')->toContain('(informe_estudio_id)')->toContain('WHERE (informe_estudio_id IS NOT NULL)')
        ->and($indices->has('prestaciones_consulta_id_unique'))->toBeFalse()
        ->and(collect(DB::select("select conname from pg_constraint where conrelid = 'prestaciones'::regclass and contype = 'u'"))->pluck('conname')->all())->toBe([]);
});

test('una consulta tiene su base y la de su informe; la base rechaza otra base y otra del mismo informe', function () {
    $consulta = pgPrestacionInformesConsulta();
    $prestaciones = Prestacion::where('consulta_id', $consulta->id)->orderBy('id')->get();
    expect($prestaciones)->toHaveCount(2)->and($prestaciones[0]->informe_estudio_id)->toBeNull()->and($prestaciones[1]->informe_estudio_id)->not->toBeNull();

    expect(pgPrestacionInformesRechazo($prestaciones[0]))->toBe(['23505', 'prestaciones_base_unica'])
        ->and(pgPrestacionInformesRechazo($prestaciones[1]))->toBe(['23505', 'prestaciones_informe_unica'])
        ->and(Prestacion::count())->toBe(2);
});

test('la migración con prestaciones ya existentes: las conserva y crea las restricciones; en simulación no cambia nada', function () {
    $migracion = require base_path('database/migrations/2026_10_27_100001_prestacion_de_informes_de_estudio.php');
    hcConsulta(); // una prestación base existente (sin informes)
    $migracion->down();
    $antes = DB::table('prestaciones')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all();
    expect($antes)->toHaveCount(1);

    DB::pretend(fn () => $migracion->up());
    expect(DB::getSchemaBuilder()->hasColumn('prestaciones', 'informe_estudio_id'))->toBeFalse();

    $migracion->up();
    expect(DB::table('prestaciones')->orderBy('id')->get()->map(fn ($fila) => collect((array) $fila)->except('informe_estudio_id')->all())->all())->toBe($antes)
        ->and(DB::table('prestaciones')->whereNotNull('informe_estudio_id')->count())->toBe(0)
        ->and(collect(DB::select("select indexname from pg_indexes where tablename = 'prestaciones'"))->pluck('indexname')->all())
        ->toContain('prestaciones_base_unica')->toContain('prestaciones_informe_unica')
        ->and(pgPrestacionInformesRechazo(Prestacion::sole()))->toBe(['23505', 'prestaciones_base_unica']);
});
