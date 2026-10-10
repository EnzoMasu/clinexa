<?php

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\User;
use App\Policies\ConsultaPolicy;

beforeEach(function () {
    hcEscenario();
    $this->consulta = hcConsulta(); // de la Dra. Benítez
});

test('el profesional que atiende escribe su consulta EN CURSO; la FINALIZADA queda cerrada también para él (409)', function () {
    $enCurso = hcConsultaEnCurso();
    hcActualizar($enCurso, ['motivo_consulta' => 'Fiebre alta.'])->assertOk();
    expect($enCurso->fresh()->motivo_consulta)->toBe('Fiebre alta.');

    hcActualizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'motivo_consulta' => 'Otro.'])
        ->assertStatus(409)->assertJson(['message' => \App\Exceptions\ConsultaCerrada::MENSAJE]);
    expect($this->consulta->fresh()->motivo_consulta)->toBe('Dolor de garganta y fiebre desde ayer.');
    $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Consulta cerrada: no puede modificarse.')->assertDontSee('>Editar<', false);
});

test('otro profesional (con EDITAR) recibe 403 con el aviso en una consulta en curso ajena, y no cambia nada', function () {
    $enCurso = hcConsultaEnCurso();
    $this->actingAs($this->otroMedico);

    hcActualizar($enCurso, ['motivo_consulta' => 'Otro motivo.'])->assertForbidden();
    $this->get(route('admin.consultas.atencion', $enCurso))->assertForbidden()->assertSee(ConsultaPolicy::SOLO_EL_QUE_ATIENDE);
    expect($enCurso->fresh()->motivo_consulta)->toBe('Dolor de garganta y fiebre desde ayer.');
});

test('el Administrador que no es el profesional: 403 en una consulta en curso; la lectura, sin edición', function () {
    $enCurso = hcConsultaEnCurso();
    $this->actingAs(User::factory()->administrador()->create());

    $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Consulta cerrada: no puede modificarse.');
    hcActualizar($enCurso, ['motivo_consulta' => 'x'])->assertForbidden();
    expect($enCurso->fresh()->motivo_consulta)->toBe('Dolor de garganta y fiebre desde ayer.');
});
test('un usuario sin rol de profesional no puede crear, aunque tenga CREAR (ni el Administrador)', function (string $quien) {
    $usuario = $quien === 'administrador' ? User::factory()->administrador()->create() : User::factory()->conPermisos(HC_PERMISOS_MEDICO)->create();
    $this->actingAs($usuario);
    $antes = Consulta::count();

    $this->post(route('admin.atencion.atender-sin-turno', $this->historia))->assertForbidden()->assertSee('Solo un profesional activo puede atender consultas.');
    $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()->assertDontSee('Atender sin turno');

    // Tampoco atiende un turno CONFIRMADO de hoy: ni el botón, ni el envío directo.
    $turno = hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30']);
    if ($usuario->tienePermiso('TURNOS', 'VER')) {
        $this->get(route('admin.turnos.index'))->assertOk()->assertDontSee(route('admin.atencion.atender', $turno), false);
    }
    $this->post(route('admin.atencion.atender', $turno))->assertForbidden()->assertSee('Solo un profesional activo puede atender consultas.');

    expect(Consulta::count())->toBe($antes)
        ->and($turno->fresh()->estado->codigo)->toBe('CONFIRMADO');
})->with(['administrador', 'con CREAR sin ser profesional']);

test('con solo VER se lee la historia y la consulta, pero no se crea ni se edita', function () {
    [$lector] = hcMedico(['apellidos' => 'Ríos', 'nombres' => 'Ana'], 'MP-3', ['HISTORIA_CLINICA' => ['VER']]);
    $this->actingAs($lector);

    $this->get(route('admin.historias-clinicas.index'))->assertOk()->assertSee('Duarte, Carmen');
    $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()->assertDontSee('Atender sin turno');
    $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Dolor de garganta')->assertDontSee('name="motivo_consulta"', false);

    // No inicia atenciones ni escribe (sin CREAR ni EDITAR: 403 antes de mirar la consulta).
    $this->post(route('admin.atencion.atender-sin-turno', $this->historia))->assertForbidden();
    hcActualizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'motivo_consulta' => 'Intento con solo VER.'])->assertForbidden();
    expect($this->consulta->fresh()->motivo_consulta)->toBe('Dolor de garganta y fiebre desde ayer.');
    $this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'J06']))->assertForbidden();
});

test('sin VER no se entra a nada de la historia clínica', function () {
    $this->actingAs(User::factory()->conPermisos(['PACIENTES' => ['VER']])->create());

    foreach ([route('admin.historias-clinicas.index'), route('admin.historias-clinicas.show', $this->historia), route('admin.consultas.show', $this->consulta)] as $url) {
        $this->get($url)->assertForbidden();
    }
    // El listado de Pacientes no ofrece el enlace a la historia.
    $this->get(route('admin.pacientes.index'))->assertOk()->assertDontSee('Historia clínica');
});

test('el profesional dueño pero INACTIVO ya no escribe su consulta en curso ni crea', function () {
    $enCurso = hcConsultaEnCurso();
    $this->profesional->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);
    $this->actingAs($this->medico->fresh()); // cada pedido real carga el usuario de nuevo

    hcActualizar($enCurso, ['motivo_consulta' => 'x'])->assertForbidden();
    $this->get(route('admin.consultas.atencion', $enCurso))->assertForbidden()->assertSee(ConsultaPolicy::SOLO_EL_QUE_ATIENDE);
    $this->post(route('admin.atencion.atender-sin-turno', $this->historia))->assertForbidden();
    expect(Consulta::count())->toBe(2);
});

test('paciente INACTIVO (sin consultas cerradas): no se le crean consultas nuevas', function () {
    // Una paciente con consultas cerradas no se puede desactivar: esta no tiene ninguna.
    $otra = \App\Models\Paciente::create(['persona_id' => \App\Models\Persona::factory()->create(['apellidos' => 'Gómez', 'nombres' => 'Ana'])->id, 'nro_ficha' => 'FP-0000002']);
    $otra->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);

    $this->post(route('admin.atencion.atender-sin-turno', $otra->historiaClinica))->assertForbidden()->assertSee('El paciente está inactivo');
    expect(Consulta::count())->toBe(1);
    $this->get(route('admin.historias-clinicas.show', $otra->historiaClinica))->assertOk()->assertDontSee('Atender sin turno');
});

test('el enlace "Historia clínica" del listado de Pacientes aparece con VER', function () {
    $this->actingAs(User::factory()->conPermisos(['PACIENTES' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());

    $this->get(route('admin.pacientes.index'))->assertOk()->assertSee(route('admin.historias-clinicas.show', $this->historia));
});
