<?php

/*
 * Las reglas de hora de los turnos sin horario (ENTRE_PACIENTES, URGENCIA): su "hora" llega al comienzo de su día en
 * Paraguay (Fecha::yaLlego con 00:00). "No se presentó" y "Pasar a ausente" valen ese mismo día; los de días
 * anteriores se cierran con Cerrar jornada. Reloj simulado en los bordes:
 * - martes 06/10 22:30 locales = miércoles 07/10 01:30 UTC: todavía es martes;
 * - miércoles 07/10 00:30 locales = 03:30 UTC: ya es miércoles.
 * Datos inventados.
 */

use App\Enums\TipoTurno;
use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use Illuminate\Support\Carbon;

beforeEach(function () {
    epEscenario();
    $this->medico = hcDarPermisos($this->medico, ['TURNOS' => ['VER', 'EDITAR']]);
    $this->actingAs($this->medico);
    $this->martes = epTurno();
    $this->urgenciaMartes = epTurno(TipoTurno::URGENCIA, ['paciente_id' => $this->otraPaciente->id]);
    $this->miercoles = epTurno(cambios: ['fecha' => '2026-10-07', 'paciente_id' => epPaciente(['apellidos' => 'Miercoles', 'nombres' => 'Ana'], 'FP-H01')->id]);
});

afterEach(fn () => Carbon::setTestNow());

function horasTiposA(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

test('00:30 del martes: el entre pacientes del martes ya llegó a su "hora"; el del miércoles no', function () {
    horasTiposA('2026-10-06 03:30:00');

    expect($this->martes->fresh()->llegoSuHora())->toBeTrue()
        ->and($this->miercoles->fresh()->llegoSuHora())->toBeFalse();

    $this->post(route('admin.atencion.no-se-presento', $this->martes))->assertSessionHasNoErrors();
    expect($this->martes->fresh()->estado->codigo)->toBe(Estado::SALTADO);
    $this->post(route('admin.atencion.pasar-ausente', $this->martes))->assertSessionHasNoErrors();
    expect($this->martes->fresh()->estado->codigo)->toBe(Estado::AUSENTE);
});

test('22:30 del martes: "No se presentó" de una urgencia del martes vale; la ausencia del miércoles todavía no', function () {
    horasTiposA('2026-10-07 01:30:00');

    $this->post(route('admin.atencion.no-se-presento', $this->urgenciaMartes))->assertSessionHasNoErrors();
    expect($this->urgenciaMartes->fresh()->estado->codigo)->toBe(Estado::SALTADO);

    expect(fn () => $this->miercoles->fresh()->pasarA(Estado::AUSENTE))
        ->toThrow(AccionRechazada::class, 'Todavía no llegó el día del turno (07/10/2026). Solo puede marcar la ausencia desde ese día.');
    // En Turnos, el botón Ausente del miércoles aparece deshabilitado con su día.
    $this->get(route('admin.turnos.index'))->assertOk()->assertSee('Disponible desde 07/10/2026');
});

test('00:30 del miércoles: los del martes ya son de un día anterior; no se marcan, se cierran con Cerrar jornada', function () {
    horasTiposA('2026-10-07 03:30:00');

    $this->post(route('admin.atencion.no-se-presento', $this->martes))->assertForbidden();
    expect($this->miercoles->fresh()->llegoSuHora())->toBeTrue();

    $this->post(route('admin.atencion.cerrar-jornada.confirmar'), ['confirmo_urgencias' => '1'])->assertSessionHasNoErrors();
    expect($this->martes->fresh()->estado->codigo)->toBe(Estado::AUSENTE)
        ->and($this->urgenciaMartes->fresh()->estado->codigo)->toBe(Estado::AUSENTE)
        ->and($this->miercoles->fresh()->estado->codigo)->toBe(Estado::AUSENTE); // ya es miércoles: llegó su día
});

test('los contadores son los del día de Paraguay', function () {
    horasTiposA('2026-10-07 01:30:00'); // 22:30 del martes
    expect($this->getJson(route('admin.atencion.contadores'), ['X-Requested-With' => 'XMLHttpRequest'])->json())
        ->toMatchArray(['entre_pacientes' => 1, 'urgencias' => 1]);

    horasTiposA('2026-10-07 03:30:00'); // 00:30 del miércoles
    expect($this->getJson(route('admin.atencion.contadores'), ['X-Requested-With' => 'XMLHttpRequest'])->json())
        ->toMatchArray(['entre_pacientes' => 1, 'urgencias' => 0, 'ultima' => null]);
});
