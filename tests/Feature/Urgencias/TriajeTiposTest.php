<?php

/*
 * Lista de Triaje con los tipos nuevos: incluye los ENTRE_PACIENTES y URGENCIA de hoy de todos los profesionales, con la
 * pastilla del tipo en lugar de la hora. Orden: urgencias, urgentes marcados por el triaje, el resto por hora y los entre
 * pacientes por orden de carga. El triaje de estos turnos funciona como el de cualquier otro. "Ahora": martes
 * 06/10/2026 09:00. Datos inventados.
 */

use App\Enums\TipoTurno;
use App\Models\Consulta;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    epEscenario();
    $this->enfermera = User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->personalDeSalud()
        ->conPersona(['apellidos' => 'Ortiz', 'nombres' => 'Nidia'])->create();
    $this->actingAs($this->enfermera);
});

afterEach(fn () => Carbon::setTestNow());

test('la lista y el orden con los tipos nuevos, de todos los profesionales', function () {
    $p = fn (string $apellido, string $ficha) => epPaciente(['apellidos' => $apellido, 'nombres' => 'Prueba'], $ficha)->id;
    epTurno(cambios: ['paciente_id' => $p('EntreUno', 'FP-T01')]);
    hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30', 'paciente_id' => $p('HoraDiez', 'FP-T02')]);
    epTriaje(hcTurno(['hora_inicio' => '11:00', 'hora_fin' => '11:30', 'paciente_id' => $p('MarcadaOnce', 'FP-T03')]), urgente: true);
    hcTurno(['hora_inicio' => '08:00', 'hora_fin' => '08:30', 'paciente_id' => $p('HoraOcho', 'FP-T04')]);
    epTurno(TipoTurno::URGENCIA, ['paciente_id' => $p('UrgenciaOtro', 'FP-T05'), 'profesional_id' => $this->otroProfesional->id]);
    epTurno(cambios: ['paciente_id' => $p('EntreDos', 'FP-T06'), 'profesional_id' => $this->otroProfesional->id]);
    epTurno(TipoTurno::URGENCIA, ['paciente_id' => $p('UrgenciaDos', 'FP-T07')]);

    $this->get(route('admin.preparacion.index'))->assertOk()
        ->assertSeeInOrder(['UrgenciaOtro', 'UrgenciaDos', 'MarcadaOnce', 'HoraOcho', 'HoraDiez', 'EntreUno', 'EntreDos'])
        ->assertSee('data-pastilla="tipo-urgencia"', false)
        ->assertSee('data-pastilla="tipo-entre-pacientes"', false)
        ->assertSee('7 turnos');
});

test('el triaje de una urgencia y de un entre pacientes funciona igual: iniciar, guardar y marcar urgente', function (TipoTurno $tipo) {
    $turno = epTurno($tipo);

    $this->post(route('admin.preparacion.preparar', $turno))->assertRedirect();
    $consulta = Consulta::where('turno_id', $turno->id)->sole();
    expect($consulta->enPreparacion())->toBeTrue();

    hcAutoguardar($consulta, ['con_triaje' => '1', 'triaje_urgente' => '1', 'triaje_observacion' => 'Dolor intenso.', 'examen' => ['temperatura' => '37']])->assertOk();
    expect($consulta->fresh())->triaje_urgente->toBeTrue()->triaje_observacion->toBe('Dolor intenso.');

    $this->get(route('admin.preparacion.formulario', $consulta))->assertOk()->assertSee('Dolor intenso.');
})->with([TipoTurno::URGENCIA, TipoTurno::ENTRE_PACIENTES]);
