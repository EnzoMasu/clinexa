<?php

/*
 * "Hoy" del flujo de atención es el día de Paraguay (UTC−3), no el de UTC:
 * - martes 06/10 a las 22:30 locales = miércoles 07/10 01:30 UTC: todavía es martes;
 * - miércoles 07/10 a las 00:30 locales = 03:30 UTC: ya es miércoles, y lo del martes es "de días anteriores".
 */

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    hcEscenario();
    $this->medico = hcDarPermisos($this->medico, ['TURNOS' => ['VER', 'EDITAR']]);
    $this->actingAs($this->medico);
    $this->martes = hcTurno(['hora_inicio' => '21:00', 'hora_fin' => '21:30']);
    $this->miercoles = hcTurno(['fecha' => '2026-10-07', 'hora_inicio' => '08:00', 'hora_fin' => '08:30']);
});

afterEach(fn () => Carbon::setTestNow());

function alas(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

describe('22:30 del martes (01:30 UTC del miércoles): sigue siendo martes', function () {
    beforeEach(fn () => alas('2026-10-07 01:30:00'));

    test('la agenda de Consulta y la lista de Preparación son las del martes', function () {
        $this->get(route('admin.atencion.index'))->assertOk()->assertSee('21:00')->assertDontSee('Tiene 1 turno de días anteriores');
        $html = $this->get(route('admin.atencion.index'))->getContent();
        expect($html)->toContain(route('admin.atencion.atender', $this->martes))->not->toContain(route('admin.atencion.atender', $this->miercoles));

        $enfermera = User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->create();
        $this->actingAs($enfermera)->get(route('admin.preparacion.index'))->assertSee('Hoy, 06/10/2026')->assertSee('21:00')->assertDontSee('08:00');
    });

    test('se atiende el turno del martes y no el del miércoles; lo finalizado queda en "Atendidos hoy"', function () {
        $this->post(route('admin.atencion.atender', $this->miercoles))->assertForbidden();
        $consulta = hcEnCurso($this->martes);
        expect($consulta->iniciada_en->format('Y-m-d H:i'))->toBe('2026-10-07 01:30');
        // Empezó hoy (martes, hora local): solo la hora, sin fecha.
        $this->get(route('admin.atencion.index'))->assertSee('En consulta desde 22:30')->assertDontSee('En consulta desde 07/10/2026');

        hcFinalizar($consulta, hcDatos())->assertSessionHasNoErrors();
        $this->get(route('admin.atencion.index'))->assertSee('Atendidos hoy (1)');
        $this->get(route('admin.consultas.show', $consulta))->assertSee('06/10/2026 22:30');
    });

    test('cerrar la jornada cierra el martes, no el miércoles', function () {
        $this->post(route('admin.atencion.cerrar-jornada.confirmar'))->assertSessionHas('status', 'Jornada cerrada: 1 turno pasó a ausente.');
        expect($this->martes->fresh()->estado->codigo)->toBe('AUSENTE')
            ->and($this->miercoles->fresh()->estado->codigo)->toBe('CONFIRMADO');
    });
});

describe('00:30 del miércoles (03:30 UTC): ya es miércoles', function () {
    beforeEach(fn () => alas('2026-10-07 03:30:00'));

    test('la agenda es la del miércoles y el turno del martes cuenta como de días anteriores sin cerrar', function () {
        $html = $this->get(route('admin.atencion.index'))->assertOk()->assertSee('Tiene 1 turno de días anteriores sin cerrar.')->getContent();
        expect($html)->toContain(route('admin.atencion.atender', $this->miercoles))->not->toContain(route('admin.atencion.atender', $this->martes));
    });

    test('el turno del martes ya no se atiende, ni se prepara, ni se marca "No se presentó"', function () {
        $this->post(route('admin.atencion.atender', $this->martes))->assertForbidden();
        $this->post(route('admin.preparacion.preparar', $this->martes))->assertForbidden();
        $this->post(route('admin.atencion.no-se-presento', $this->martes))->assertForbidden();
        expect(Consulta::count())->toBe(0);
    });

    test('lo atendido el martes a las 22:30 no aparece en "Atendidos hoy" del miércoles', function () {
        alas('2026-10-07 01:30:00');
        hcFinalizar(hcEnCurso($this->martes), hcDatos())->assertSessionHasNoErrors();

        alas('2026-10-07 03:30:00');
        $this->get(route('admin.atencion.index'))->assertSee('Atendidos hoy (0)');
    });

    test('una consulta empezada el martes a las 22:30 y todavía en curso aparece con su fecha', function () {
        alas('2026-10-07 01:30:00');
        hcEnCurso($this->martes);

        alas('2026-10-07 03:30:00');
        $this->get(route('admin.atencion.index'))->assertSee('En consulta desde 06/10/2026 22:30');
    });

    test('cerrar la jornada a las 00:30 cierra también el martes (día anterior) y el miércoles', function () {
        $this->get(route('admin.atencion.cerrar-jornada'))->assertSeeInOrder(['06/10/2026 21:00', '07/10/2026 08:00']);
        $this->post(route('admin.atencion.cerrar-jornada.confirmar'))->assertSessionHas('status', 'Jornada cerrada: 2 turnos pasaron a ausente.');
        expect($this->martes->fresh()->estado->codigo)->toBe(Estado::AUSENTE);
    });
});
