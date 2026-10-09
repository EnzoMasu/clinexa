<?php

use App\Support\Atencion\Autoguardado;
use App\Models\Consulta;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Persona;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Lo de la historia clínica que depende del motor: en PostgreSQL (la base real) la versión de la
 * consulta (updated_at) conserva los microsegundos, el unique de turno_id frena la segunda consulta
 * del turno, y el backfill corre. Lo demás está en tests/Feature/HistoriaClinica.
 */
beforeEach(fn () => hcEscenario());

afterEach(fn () => Carbon::setTestNow());

test('la versión de la consulta guarda los microsegundos, y la pestaña vieja se rechaza', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00.123456', 'UTC'));
    $consulta = hcConsulta();
    $vieja = $consulta->fresh()->version();
    expect($vieja)->toEndWith('.123456');

    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00.123999', 'UTC')); // mismo segundo
    hcActualizar($consulta, [...hcFilasGuardadas($consulta), 'motivo_consulta' => 'Pestaña 1.'], $vieja)->assertSessionHasNoErrors();
    hcActualizar($consulta, [...hcFilasGuardadas($consulta), 'motivo_consulta' => 'Pestaña 2.'], $vieja)
        ->assertSessionHas('error', Autoguardado::VERSION_VIEJA);

    expect($consulta->fresh()->motivo_consulta)->toBe('Pestaña 1.');
});

test('atender guarda la consulta y el turno ATENDIDO; la base no admite una segunda consulta del turno', function () {
    $turno = hcTurno();
    hcGuardarNueva([], $turno)->assertSessionHasNoErrors();

    expect($turno->fresh()->estado->codigo)->toBe('ATENDIDO');

    $error = null;
    try {
        DB::transaction(fn () => Consulta::create(['historia_clinica_id' => $this->historia->id, 'turno_id' => $turno->id, 'profesional_id' => $this->profesional->id, 'motivo_consulta' => 'x']));
    } catch (QueryException $e) {
        $error = $e->getCode();
    }
    expect($error)->toBe('23505');
});

test('el backfill corre en PostgreSQL', function () {
    $paciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000050']);
    $paciente->forceFill(['fecha_alta' => '2024-02-29'])->save();
    DB::table('historias_clinicas')->where('paciente_id', $paciente->id)->delete();

    (require database_path('migrations/2026_10_08_100003_crear_historias_de_pacientes_existentes.php'))->up();

    expect(HistoriaClinica::where('paciente_id', $paciente->id)->sole()->fecha_apertura->format('Y-m-d'))->toBe('2024-02-29');
});
