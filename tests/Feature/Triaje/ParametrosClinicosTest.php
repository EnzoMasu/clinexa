<?php

/*
 * Pantalla de Parámetros clínicos: listado con las tres bandas, edición con validación del orden de las bandas,
 * Aprobar (EDITAR y Profesional activo) y re-aprobar, auditoría; y qué es contenido clínico en la Auditoría (lo
 * nuevo de consultas y examenes_fisicos sí; personal de salud, cargos y parámetros no). Valores inventados.
 */

use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\ParametroClinico;
use App\Models\Profesional;
use App\Models\User;
use App\Http\Controllers\Admin\ParametroClinicoController;

beforeEach(function () {
    $this->supervisor = User::factory()->conPermisos(['PARAMETROS_CLINICOS' => ['VER', 'EDITAR'], 'AUDITORIA' => ['VER']])
        ->conPersona(['apellidos' => 'Galeano', 'nombres' => 'Rubén'])->create();
    Profesional::create(['persona_id' => $this->supervisor->persona_id, 'matricula' => 'MP-90']);
    $this->temperatura = ParametroClinico::where('codigo', 'TEMPERATURA')->sole();
    $this->actingAs($this->supervisor);
});

function parametrosClinicosFormulario(array $cambios = []): array
{
    return ['unidad' => '°C', 'imposible_min' => '30', 'imposible_max' => '45', 'muy_alterado_min' => '35', 'muy_alterado_max' => '40',
        'normal_min' => '36', 'normal_max' => '37,5', 'notas' => '', ...$cambios];
}

test('el listado: las 8, con unidad, las tres bandas, el estado, la ayuda y el banner', function () {
    $this->get(route('admin.parametros-clinicos.index'))->assertOk()
        ->assertSee('Estos valores los define y aprueba un médico. Los rangos son para adultos.')
        ->assertSee('Los valores de referencia todavía no fueron aprobados por un médico: el sistema no marca alertas.')
        ->assertSeeInOrder(['Temperatura', '(°C)', '30 a 45 °C', 'sin definir', 'sin definir', 'PENDIENTE', 'Editar', 'Aprobar'])
        ->assertSee('Presión sistólica');
    expect(substr_count($this->get(route('admin.parametros-clinicos.index'))->getContent(), 'data-parametro='))->toBe(8);
});

test('editar con coma decimal guarda las bandas; editar uno aprobado lo devuelve a PENDIENTE', function () {
    $this->put(route('admin.parametros-clinicos.update', $this->temperatura), parametrosClinicosFormulario())->assertSessionHasNoErrors();
    expect($this->temperatura->fresh())->normal_max->toBe(37.5)->muy_alterado_min->toBe(35.0);

    $this->post(route('admin.parametros-clinicos.aprobar', $this->temperatura))->assertSessionHas('status', 'Parámetro Temperatura aprobado.');
    expect($this->temperatura->fresh())->aprobado()->toBeTrue()->aprobado_por->toBe($this->supervisor->id);
    $this->get(route('admin.parametros-clinicos.index'))->assertSee('Rubén Galeano, ');

    $this->put(route('admin.parametros-clinicos.update', $this->temperatura), parametrosClinicosFormulario(['normal_max' => '37,8']))
        ->assertSessionHas('status', 'Parámetro Temperatura actualizado. Queda PENDIENTE: un médico lo tiene que volver a aprobar.');
    expect($this->temperatura->fresh())->aprobado()->toBeFalse()->aprobado_por->toBeNull()->aprobado_en->toBeNull();

    // Re-aprobar.
    $this->post(route('admin.parametros-clinicos.aprobar', $this->temperatura));
    expect($this->temperatura->fresh()->aprobado())->toBeTrue();
});

test('validación del orden de las bandas', function (array $cambios, string $error) {
    $this->put(route('admin.parametros-clinicos.update', $this->temperatura), parametrosClinicosFormulario($cambios))->assertSessionHasErrors(['bandas' => $error]);
    expect($this->temperatura->fresh()->normal_min)->toBeNull();
})->with([
    'muy alterado fuera de imposible' => [['muy_alterado_max' => '46'], 'La banda muy alterado tiene que quedar dentro de la banda imposible.'],
    'normal fuera de muy alterado' => [['normal_min' => '34'], 'La banda normal tiene que quedar dentro de la banda muy alterado.'],
    'normal fuera de imposible (sin muy alterado)' => [['muy_alterado_min' => '', 'muy_alterado_max' => '', 'normal_max' => '50'], 'La banda normal tiene que quedar dentro de la banda imposible.'],
    'par incompleto' => [['normal_max' => ''], 'Complete el mínimo y el máximo de la banda normal, o deje los dos vacíos.'],
    'mínimo mayor que el máximo' => [['normal_min' => '37,6'], 'En la banda normal, el mínimo no puede ser mayor que el máximo.'],
]);

test('el límite imposible es obligatorio, salvo en la presión; y no supera lo que admite el examen', function () {
    $this->put(route('admin.parametros-clinicos.update', $this->temperatura), parametrosClinicosFormulario(['imposible_min' => '']))->assertSessionHasErrors('imposible_min');
    $this->put(route('admin.parametros-clinicos.update', $this->temperatura), parametrosClinicosFormulario(['imposible_max' => '1000']))->assertSessionHasErrors('imposible_max');

    $sistolica = ParametroClinico::where('codigo', 'PRESION_SISTOLICA')->sole();
    $this->put(route('admin.parametros-clinicos.update', $sistolica), ['unidad' => 'mmHg', 'normal_min' => '90', 'normal_max' => '130'])->assertSessionHasNoErrors();
    expect($sistolica->fresh())->imposible_min->toBeNull()->normal_max->toBe(130.0);
});

test('Aprobar exige EDITAR (403) y ser Profesional activo (403 con el mensaje)', function () {
    $this->actingAs(User::factory()->conPermisos(['PARAMETROS_CLINICOS' => ['VER']])->create());
    $this->get(route('admin.parametros-clinicos.index'))->assertOk()->assertDontSee('Editar')->assertDontSee('Aprobar');
    $this->post(route('admin.parametros-clinicos.aprobar', $this->temperatura))->assertForbidden();

    $this->actingAs(User::factory()->conPermisos(['PARAMETROS_CLINICOS' => ['VER', 'EDITAR']])->create());
    $this->get(route('admin.parametros-clinicos.index'))->assertSee('Editar')->assertDontSee('>Aprobar<', false);
    $this->post(route('admin.parametros-clinicos.aprobar', $this->temperatura))->assertForbidden()->assertSee(ParametroClinicoController::SOLO_PROFESIONAL);

    expect($this->temperatura->fresh()->aprobado())->toBeFalse();
});

test('auditoría: editar y aprobar quedan registrados y los ve quien ve la auditoría (no es contenido clínico)', function () {
    $this->put(route('admin.parametros-clinicos.update', $this->temperatura), parametrosClinicosFormulario());
    $this->post(route('admin.parametros-clinicos.aprobar', $this->temperatura));

    $eventos = LogAuditoria::where('tabla_afectada', 'parametros_clinicos')->where('registro_afectado_id', (string) $this->temperatura->id)->orderBy('id')->get();
    // Dos EDITAR: las bandas, y la aprobación (estado, quién y cuándo).
    expect($eventos)->toHaveCount(2)
        ->and($eventos[0]->valor_nuevo)->toMatchArray(['normal_min' => '36', 'normal_max' => '37.5'])
        ->and($eventos[1]->valor_nuevo)->toMatchArray(['estado_id' => Estado::idDe(Estado::APROBADO), 'aprobado_por' => $this->supervisor->id]);

    $auditor = User::factory()->conPermisos(['AUDITORIA' => ['VER']])->create();
    $this->actingAs($auditor)->get(route('admin.auditoria.show', $eventos->first()))->assertOk()->assertSee('37.5');
});

test('auditoría: lo nuevo del triaje es contenido clínico (oculto sin HISTORIA_CLINICA); el personal de salud no', function () {
    hcEscenario();
    $enfermera = User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->personalDeSalud()->create();
    $this->actingAs($enfermera)->post(route('admin.preparacion.preparar', hcTurno()));
    hcAutoguardar(\App\Models\Consulta::sole(), ['con_triaje' => '1', 'triaje_urgente' => '1', 'triaje_observacion' => 'OBSERVACION-RESERVADA'])->assertOk();
    $evento = LogAuditoria::where('tabla_afectada', 'consultas')->where('accion', 'EDITAR')->latest('id')->first();
    // El rol de la enfermera se creó con la fábrica (fuera de un pedido web): se edita desde la pantalla para auditarlo.
    $this->actingAs(User::factory()->administrador()->create());
    $registro = \App\Models\PersonalSalud::where('persona_id', $enfermera->persona_id)->sole();
    $this->put(route('admin.personal-salud.update', $registro), ['cargo_salud_id' => $registro->cargo_salud_id, 'registro_profesional' => 'RP-1', 'estado_id' => $registro->estado_id])
        ->assertSessionHasNoErrors();
    $alta = LogAuditoria::where('tabla_afectada', 'personal_salud')->sole();

    $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER']])->create());
    $this->get(route('admin.auditoria.show', $evento))->assertOk()->assertDontSee('OBSERVACION-RESERVADA');
    // El buscador no busca en el contenido clínico para quien no lo puede ver (el término solo queda en la caja de búsqueda).
    $this->get(route('admin.auditoria.index', ['q' => 'OBSERVACION-RESERVADA']))->assertOk()->assertDontSee(route('admin.auditoria.show', $evento));
    $this->get(route('admin.auditoria.show', $alta))->assertOk()->assertSee('RP-1');

    $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());
    $this->get(route('admin.auditoria.show', $evento))->assertSee('OBSERVACION-RESERVADA');
});
