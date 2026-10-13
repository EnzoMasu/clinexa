<?php

/*
 * Alta "entre pacientes" (Nuevo turno, modalidad Entre pacientes): sin horario ni consultorio, dentro del cupo del
 * profesional ese día (AgendarEntrePacientes). No ocupa horarios libres ni dispara superposiciones; la
 * confirmación del segundo turno del día sí aplica. "Ahora": martes 06/10/2026 09:00. Datos inventados.
 */

use App\Enums\TipoTurno;
use App\Http\Controllers\Admin\TurnoController;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\Turno;
use App\Support\Agenda;
use App\Support\Turnos\AgendarEntrePacientes;
use Illuminate\Support\Carbon;

beforeEach(function () {
    epEscenario();
    $this->actingAs($this->recepcion);
});

afterEach(fn () => Carbon::setTestNow());

function agendarEntrePacientes(array $cambios = [])
{
    return test()->post(route('admin.turnos.store'), [
        'modalidad' => 'entre_pacientes', 'paciente_id' => test()->paciente->id, 'profesional_id' => test()->profesional->id,
        'fecha' => '06/10/2026', 'origen_turno_id' => test()->presencial->id, ...$cambios,
    ]);
}

test('agenda sin horas ni consultorio, PENDIENTE, con su origen; auditado con detalle "Entre pacientes"', function () {
    epCupo(2);

    agendarEntrePacientes()->assertRedirect(route('admin.turnos.index'))
        ->assertSessionHas('status', 'Turno entre pacientes dado para el 06/10/2026 (cupo: 1 de 2 usados).');

    $turno = Turno::sole();
    expect($turno->tipo)->toBe(TipoTurno::ENTRE_PACIENTES)
        ->and($turno->hora_inicio)->toBeNull()->and($turno->hora_fin)->toBeNull()->and($turno->consultorio_id)->toBeNull()
        ->and($turno->estado->codigo)->toBe(Estado::PENDIENTE)
        ->and($turno->origen_turno_id)->toBe($this->presencial->id);

    $evento = LogAuditoria::where('tabla_afectada', 'turnos')->sole();
    expect($evento->accion->value)->toBe('CREAR')->and($evento->detalle)->toBe('Entre pacientes');
});

test('sin cupo fijado (vale 0), o con cupo 0: no se agenda', function () {
    agendarEntrePacientes()->assertSessionHasErrors(['cupo' => AgendarEntrePacientes::SIN_CUPO]);
    epCupo(0);
    agendarEntrePacientes()->assertSessionHasErrors(['cupo' => AgendarEntrePacientes::SIN_CUPO]);
    expect(Turno::count())->toBe(0);
});

test('cupo lleno: no se agenda; un cancelado libera su lugar', function () {
    epCupo(1);
    agendarEntrePacientes()->assertSessionHasNoErrors();

    agendarEntrePacientes(['paciente_id' => $this->otraPaciente->id])->assertSessionHasErrors(['cupo' => AgendarEntrePacientes::CUPO_COMPLETO]);
    expect(Turno::count())->toBe(1);

    Turno::sole()->pasarA(Estado::CANCELADO);
    agendarEntrePacientes(['paciente_id' => $this->otraPaciente->id])->assertSessionHasNoErrors();
    expect(Turno::where('estado_id', '!=', Estado::idDe(Estado::CANCELADO))->count())->toBe(1);
});

test('dos envíos seguidos con cupo 1: el segundo ya ve el cupo usado (la concurrencia real, en tests/Postgres)', function () {
    epCupo(1);
    agendarEntrePacientes()->assertSessionHasNoErrors();
    agendarEntrePacientes(['agendar_igual' => '1'])->assertSessionHasErrors(['cupo' => AgendarEntrePacientes::CUPO_COMPLETO]);
    expect(Turno::count())->toBe(1);
});

test('segundo turno del día con el mismo profesional (de cualquier tipo): solo confirmando', function () {
    epCupo(3);
    hcTurno(); // NORMAL de las 08:00

    agendarEntrePacientes()->assertSessionHasErrors(['agendar_igual' => 'La paciente ya tiene turno con Rosa Benítez hoy a las 08:00.']);
    expect(Turno::count())->toBe(1);

    agendarEntrePacientes(['agendar_igual' => '1'])->assertSessionHasNoErrors();
    expect(LogAuditoria::where('tabla_afectada', 'turnos')->latest('id')->first()->detalle)->toBe('Entre pacientes. Segundo turno del día confirmado');

    // Y al revés: entre pacientes ya dado, otro entre pacientes también pide confirmar.
    agendarEntrePacientes()->assertSessionHasErrors(['agendar_igual' => 'La paciente ya tiene turno con Rosa Benítez hoy a las 08:00.']);
});

test('con un entre pacientes, un turno con horario de la misma paciente pide confirmar el segundo del día (no superposición)', function () {
    epCupo(1);
    agendarEntrePacientes()->assertSessionHasNoErrors();

    $this->post(route('admin.turnos.store'), [
        'paciente_id' => $this->paciente->id, 'profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'hora_inicio' => '10:00',
    ])->assertSessionHasErrors(['agendar_igual' => 'La paciente ya tiene turno con Rosa Benítez hoy (entre pacientes).'])
        ->assertSessionDoesntHaveErrors('hora_inicio');
});

test('no ocupa horarios libres ni dispara superposiciones', function () {
    epCupo(2);
    $antes = Agenda::horariosDisponibles($this->profesional, Carbon::parse('2026-10-06'))->pluck('hora_inicio')->all();
    agendarEntrePacientes()->assertSessionHasNoErrors();

    expect(Agenda::horariosDisponibles($this->profesional, Carbon::parse('2026-10-06'))->pluck('hora_inicio')->all())->toBe($antes)
        ->and($antes)->toContain('09:30')
        ->and(Turno::superpuestoDeLaPaciente($this->paciente->id, '2026-10-06', '00:00', '23:59'))->toBeNull();

    // Otra paciente toma el horario de las 09:30 con la misma profesional: sin choque.
    $this->post(route('admin.turnos.store'), [
        'paciente_id' => $this->otraPaciente->id, 'profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'hora_inicio' => '09:30',
    ])->assertSessionHasNoErrors();
});

test('no se agenda en una fecha pasada; sí hoy aunque ya pasaron los horarios', function () {
    epCupo(1, fecha: '2026-09-29');
    agendarEntrePacientes(['fecha' => '29/09/2026'])->assertSessionHasErrors(['fecha' => TurnoController::FECHA_PASADA]);

    Carbon::setTestNow(Carbon::parse('2026-10-07 01:00:00', 'UTC')); // 22:00 del martes en Paraguay
    epCupo(1);
    agendarEntrePacientes()->assertSessionHasNoErrors();
});

test('paciente inactivo o profesional inactivo: no', function () {
    epCupo(1);
    $this->paciente->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);
    agendarEntrePacientes()->assertSessionHasErrors('paciente_id');

    $this->paciente->update(['estado_id' => Estado::idDe(Estado::ACTIVO)]);
    $this->profesional->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);
    agendarEntrePacientes()->assertSessionHasErrors('profesional_id');
    expect(Turno::count())->toBe(0);
});

test('el listado muestra la pastilla en lugar de la hora, con urgencias primero, horario por hora y entre pacientes por carga', function () {
    epCupo(3);
    hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30', 'paciente_id' => $this->otraPaciente->id]);
    $primero = epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => 'Primera', 'nombres' => 'Ana'], 'FP-0000010')->id]);
    $segundo = epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => 'Segunda', 'nombres' => 'Bea'], 'FP-0000011')->id]);
    hcTurno(['hora_inicio' => '08:00', 'hora_fin' => '08:30', 'paciente_id' => epPaciente(['apellidos' => 'Temprana', 'nombres' => 'Cira'], 'FP-0000012')->id]);
    epTurno(TipoTurno::URGENCIA, ['paciente_id' => epPaciente(['apellidos' => 'Urgida', 'nombres' => 'Dora'], 'FP-0000013')->id]);

    $this->get(route('admin.turnos.index'))->assertOk()
        ->assertSeeInOrder(['Urgencia', 'Urgida, Dora', '08:00 – 08:30', 'Temprana, Cira', '10:00 – 10:30', 'Gómez, Lidia', 'Entre pacientes', 'Primera, Ana', 'Entre pacientes', 'Segunda, Bea'])
        ->assertSee('data-pastilla="tipo-entre-pacientes"', false)
        ->assertSee('data-pastilla="tipo-urgencia"', false);
});

test('el alta muestra la modalidad y el cupo del día responde solo conteos', function () {
    epCupo(4);
    epTurno();

    $this->get(route('admin.turnos.create'))->assertOk()->assertSee('Entre pacientes')->assertSee('name="modalidad"', false);
    $this->getJson(route('admin.turnos.cupo-del-dia', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026']))
        ->assertOk()->assertExactJson(['cupo' => 4, 'usados' => 1]);
    $this->getJson(route('admin.turnos.cupo-del-dia', ['profesional_id' => $this->profesional->id, 'fecha' => 'x']))->assertExactJson([]);
});
