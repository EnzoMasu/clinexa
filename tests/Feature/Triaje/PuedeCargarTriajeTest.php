<?php

/*
 * Quién carga el triaje (ConsultaPolicy::cargarTriaje, el único lugar): usuario ACTIVO cuya persona es Profesional
 * activo o Personal de salud activo, además del permiso del módulo. Aplica a iniciar, al autoguardado del triaje
 * cuando no es el profesional de la consulta, y a terminar o reabrir. Datos inventados.
 */

use App\Models\CargoSalud;
use App\Models\Consulta;
use App\Models\Persona;
use App\Models\PersonalSalud;
use App\Models\Profesional;
use App\Models\User;
use App\Policies\ConsultaPolicy;

const PUEDE_CARGAR_TRIAJE_PERMISOS = ['PREPARACION' => ['VER', 'CREAR', 'EDITAR']];

beforeEach(function () {
    hcEscenario();
    $this->turno = hcTurno();
});

/** Intenta las cuatro acciones del triaje con ese usuario: [iniciar, autoguardar, terminar, reabrir] (códigos HTTP). */
function puedeCargarTriajeIntentar(User $usuario, Consulta $consulta): array
{
    test()->actingAs($usuario);

    return [
        test()->post(route('admin.preparacion.preparar', $consulta->turno))->status(),
        hcAutoguardar($consulta, ['examen' => ['frecuencia_cardiaca' => '80']])->status(),
        test()->post(route('admin.preparacion.lista', $consulta))->status(),
        test()->post(route('admin.preparacion.reabrir', $consulta))->status(),
    ];
}

/** Una consulta EN_PREPARACION de ese turno, iniciada por una enfermera con el rol. */
function puedeCargarTriajeConsulta(): Consulta
{
    $enfermera = User::factory()->conPermisos(PUEDE_CARGAR_TRIAJE_PERMISOS)->personalDeSalud()->create();
    test()->actingAs($enfermera)->post(route('admin.preparacion.preparar', test()->turno))->assertRedirect();

    return Consulta::sole();
}

test('Personal de salud activo con el permiso: inicia, autoguarda, termina y reabre', function () {
    $consulta = puedeCargarTriajeConsulta();
    $enfermera = User::factory()->conPermisos(PUEDE_CARGAR_TRIAJE_PERMISOS)->personalDeSalud('Licenciado/a en obstetricia', '4321')->create();

    expect(puedeCargarTriajeIntentar($enfermera, $consulta))->toBe([302, 200, 302, 302]);
});

test('Profesional activo (no el de la consulta) con el permiso del módulo también', function () {
    $consulta = puedeCargarTriajeConsulta();
    $usuario = User::factory()->conPermisos(PUEDE_CARGAR_TRIAJE_PERMISOS)->create();
    Profesional::create(['persona_id' => $usuario->persona_id, 'matricula' => 'MP-77']);

    expect(puedeCargarTriajeIntentar($usuario, $consulta))->toBe([302, 200, 302, 302]);
});

test('con el permiso pero sin rol de salud, administrativo, o personal de salud inactivo: 403 con el mensaje', function (Closure $usuario) {
    $consulta = puedeCargarTriajeConsulta();
    $quien = $usuario();

    expect(puedeCargarTriajeIntentar($quien, $consulta))->toBe([403, 403, 403, 403]);
    $this->actingAs($quien);
    hcAutoguardar($consulta, ['examen' => ['frecuencia_cardiaca' => '80']])->assertForbidden()->assertJsonPath('message', ConsultaPolicy::SOLO_PERSONAL_SALUD);
    $this->get(route('admin.preparacion.formulario', $consulta))->assertForbidden()->assertSee(ConsultaPolicy::SOLO_PERSONAL_SALUD);
    expect($consulta->fresh()->examenFisico)->toBeNull();
})->with([
    'sin rol' => [fn () => User::factory()->conPermisos(PUEDE_CARGAR_TRIAJE_PERMISOS)->create()],
    'administrativo (Recepción con el permiso por error)' => [fn () => User::factory()->conPermisos([...PUEDE_CARGAR_TRIAJE_PERMISOS, 'TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'PACIENTES' => ['VER']])->create()],
    'personal de salud inactivo' => [function () {
        $usuario = User::factory()->conPermisos(PUEDE_CARGAR_TRIAJE_PERMISOS)->personalDeSalud()->create();
        PersonalSalud::where('persona_id', $usuario->persona_id)->sole()->desactivar();

        return $usuario;
    }],
]);

test('Recepción (sin el permiso de triaje) recibe 403', function () {
    $consulta = puedeCargarTriajeConsulta();
    $recepcion = User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'PACIENTES' => ['VER', 'CREAR', 'EDITAR']])->create();

    expect(puedeCargarTriajeIntentar($recepcion, $consulta))->toBe([403, 403, 403, 403]);
});

test('la lista se ve con VER, pero a quien no es personal de salud se le avisa y no se le ofrecen los botones', function () {
    $this->actingAs(User::factory()->conPermisos(PUEDE_CARGAR_TRIAJE_PERMISOS)->create());

    $this->get(route('admin.preparacion.index'))->assertOk()->assertSee('Duarte, Carmen')
        ->assertSee(ConsultaPolicy::SOLO_PERSONAL_SALUD)->assertDontSee('Iniciar triaje')
        ->assertDontSee(route('admin.preparacion.preparar', $this->turno));

    $this->actingAs(User::factory()->conPermisos(PUEDE_CARGAR_TRIAJE_PERMISOS)->personalDeSalud()->create());
    $this->get(route('admin.preparacion.index'))->assertSee('Iniciar triaje')->assertDontSee(ConsultaPolicy::SOLO_PERSONAL_SALUD);
});

test('el profesional del turno inicia y carga el triaje de su paciente sin el permiso del módulo (como siempre)', function () {
    $this->actingAs($this->medico)->post(route('admin.preparacion.preparar', $this->turno))->assertRedirect();
    hcAutoguardar(Consulta::sole(), ['examen' => ['frecuencia_cardiaca' => '80']])->assertOk();
});

test('"Cargado por" muestra el cargo y el registro', function () {
    $enfermera = User::factory()->conPermisos(PUEDE_CARGAR_TRIAJE_PERMISOS)->personalDeSalud('Licenciado/a en enfermería', 'RE-55')
        ->conPersona(['apellidos' => 'Ortiz', 'nombres' => 'Nidia'])->create();
    $this->actingAs($enfermera)->post(route('admin.preparacion.preparar', $this->turno));
    hcAutoguardar(Consulta::sole(), ['examen' => ['frecuencia_cardiaca' => '80']])->assertOk();

    $this->get(route('admin.preparacion.formulario', Consulta::sole()))
        ->assertSee('Cargado por Nidia Ortiz (Licenciado/a en enfermería, registro RE-55)');
});
