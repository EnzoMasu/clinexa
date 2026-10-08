<?php

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\User;
use App\Policies\ConsultaPolicy;

beforeEach(function () {
    hcEscenario();
    $this->consulta = hcConsulta(); // de la Dra. Benítez
});

test('el profesional que atiende modifica su consulta', function () {
    $this->get(route('admin.consultas.edit', $this->consulta))->assertOk();
    hcActualizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'motivo_consulta' => 'Fiebre alta.'])
        ->assertRedirect(route('admin.consultas.show', $this->consulta));

    expect($this->consulta->fresh()->motivo_consulta)->toBe('Fiebre alta.');
});

test('otro profesional con EDITAR recibe 403 con el aviso, y no cambia nada', function () {
    $this->actingAs($this->otroMedico);

    $this->get(route('admin.consultas.edit', $this->consulta))->assertForbidden()->assertSee(ConsultaPolicy::SOLO_EL_QUE_ATIENDE);
    hcActualizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'motivo_consulta' => 'Otro motivo.'])
        ->assertForbidden()->assertSee(ConsultaPolicy::SOLO_EL_QUE_ATIENDE);

    expect($this->consulta->fresh()->motivo_consulta)->toBe('Dolor de garganta y fiebre desde ayer.');
    // En la vista de lectura no tiene el botón Editar.
    $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertDontSee(route('admin.consultas.edit', $this->consulta));
});

test('el Administrador que no es el profesional recibe 403', function () {
    $this->actingAs(User::factory()->administrador()->create());

    $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertDontSee(route('admin.consultas.edit', $this->consulta));
    $this->get(route('admin.consultas.edit', $this->consulta))->assertForbidden()->assertSee(ConsultaPolicy::SOLO_EL_QUE_ATIENDE);
    hcActualizar($this->consulta, hcFilasGuardadas($this->consulta))->assertForbidden();
});

test('un usuario sin rol de profesional no puede crear, aunque tenga CREAR (ni el Administrador)', function (string $quien) {
    $usuario = $quien === 'administrador' ? User::factory()->administrador()->create() : User::factory()->conPermisos(HC_PERMISOS_MEDICO)->create();
    $this->actingAs($usuario);
    $antes = Consulta::count();

    $this->get(route('admin.consultas.create', $this->historia))->assertForbidden()->assertSee('Solo un profesional activo puede atender consultas.');
    hcGuardarNueva()->assertForbidden();
    $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()->assertDontSee('Atender sin turno');

    // Tampoco atiende un turno CONFIRMADO de hoy: ni el enlace, ni la URL directa, ni el envío.
    $turno = hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30']);
    $atender = route('admin.consultas.create', [$this->historia, 'turno' => $turno->id]);
    if ($usuario->tienePermiso('TURNOS', 'VER')) {
        $this->get(route('admin.turnos.index'))->assertOk()->assertDontSee($atender, false)->assertDontSee('>Atender</a>', false);
    }
    $this->get($atender)->assertForbidden()->assertSee('Solo un profesional activo puede atender consultas.')->assertDontSee('name="motivo_consulta"', false);
    hcGuardarNueva([], $turno)->assertForbidden();

    expect(Consulta::count())->toBe($antes)
        ->and($turno->fresh()->estado->codigo)->toBe('CONFIRMADO');
})->with(['administrador', 'con CREAR sin ser profesional']);

test('con solo VER se lee la historia y la consulta, pero no se crea ni se edita', function () {
    [$lector] = hcMedico(['apellidos' => 'Ríos', 'nombres' => 'Ana'], 'MP-3', ['HISTORIA_CLINICA' => ['VER']]);
    $this->actingAs($lector);

    $this->get(route('admin.historias-clinicas.index'))->assertOk()->assertSee('Duarte, Carmen');
    $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()->assertDontSee('Atender sin turno');
    $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Dolor de garganta')->assertDontSee(route('admin.consultas.edit', $this->consulta));

    // El formulario no se muestra: ni el de alta ni el de edición (403, sin campos).
    $this->get(route('admin.consultas.create', $this->historia))->assertForbidden()->assertDontSee('name="motivo_consulta"', false);
    hcGuardarNueva()->assertForbidden();
    $this->get(route('admin.consultas.edit', $this->consulta))->assertForbidden()->assertDontSee('name="motivo_consulta"', false)->assertDontSee('name="version"', false);
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

test('el profesional dueño pero INACTIVO ya no modifica ni crea', function () {
    $this->profesional->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);
    $this->actingAs($this->medico->fresh()); // cada pedido real carga el usuario de nuevo

    $this->get(route('admin.consultas.edit', $this->consulta))->assertForbidden()->assertSee(ConsultaPolicy::SOLO_EL_QUE_ATIENDE);
    $this->get(route('admin.consultas.create', $this->historia))->assertForbidden();
});

test('paciente INACTIVO: no se crean consultas nuevas, pero el que atendió puede corregir la suya', function () {
    $this->paciente->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);

    $this->get(route('admin.consultas.create', $this->historia))->assertForbidden()->assertSee('El paciente está inactivo');
    $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()->assertDontSee('Atender sin turno');

    hcActualizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'motivo_consulta' => 'Corregido.'])->assertRedirect();
    expect($this->consulta->fresh()->motivo_consulta)->toBe('Corregido.');
});

test('el enlace "Historia clínica" del listado de Pacientes aparece con VER', function () {
    $this->actingAs(User::factory()->conPermisos(['PACIENTES' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());

    $this->get(route('admin.pacientes.index'))->assertOk()->assertSee(route('admin.historias-clinicas.show', $this->historia));
});
