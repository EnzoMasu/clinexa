<?php

/*
 * "No se presentó" y "Ausente" solo desde la hora del turno (hora de Paraguay, UTC−3; desde esa hora,
 * inclusive). La regla es una sola, Fecha::yaLlego, aplicada en Turno::pasarA: vale para el botón de
 * Consulta, el de Turnos y Cerrar jornada. Reloj simulado; datos inventados (escenario.php).
 */

use App\Models\Estado;
use App\Models\Turno;
use App\Support\Atencion\CerrarJornada;
use App\Support\Fecha;
use Illuminate\Support\Carbon;

beforeEach(function () {
    hcEscenario();
    $this->medico = hcDarPermisos($this->medico, ['TURNOS' => ['VER', 'EDITAR']]);
    $this->actingAs($this->medico);
});

afterEach(fn () => Carbon::setTestNow());

/** Pone el reloj en esa fecha y hora LOCALES (Paraguay). */
function horaLocal(string $fechaHora): void
{
    Carbon::setTestNow(Carbon::parse($fechaHora, config('app.zona_horaria_local'))->utc());
}

function noSePresento(Turno $turno)
{
    return test()->from(route('admin.atencion.index'))->post(route('admin.atencion.no-se-presento', $turno));
}

function ausenteManual(Turno $turno)
{
    return test()->from(route('admin.turnos.index'))->patch(route('admin.turnos.estado', $turno), ['accion' => 'ausente']);
}

const AVISO_19 = 'Todavía no llegó la hora del turno (19:00). Solo puede marcar la ausencia desde esa hora.';

describe('la regla (Fecha::yaLlego)', function () {
    test('un minuto antes no, en punto y después sí; un día anterior siempre; un día posterior nunca', function () {
        horaLocal('2026-10-06 18:59:59');
        expect(Fecha::yaLlego('2026-10-06', '19:00'))->toBeFalse()
            ->and(Fecha::yaLlego('2026-10-05', '23:59'))->toBeTrue()
            ->and(Fecha::yaLlego('2026-10-07', '00:00'))->toBeFalse();

        horaLocal('2026-10-06 19:00:00');
        expect(Fecha::yaLlego('2026-10-06', '19:00'))->toBeTrue()->and(Fecha::yaLlego('2026-10-06', '19:00:00'))->toBeTrue();

        horaLocal('2026-10-06 19:01:00');
        expect(Fecha::yaLlego('2026-10-06', '19:00'))->toBeTrue();
    });
});

describe('turno de hoy a las 19:00', function () {
    beforeEach(fn () => $this->turno = hcTurno(['hora_inicio' => '19:00', 'hora_fin' => '19:30']));

    test('a las 18:59: "No se presentó" y "Ausente" se rechazan con el aviso y nada cambia', function () {
        horaLocal('2026-10-06 18:59:00');

        noSePresento($this->turno)->assertRedirect(route('admin.atencion.index'))->assertSessionHas('error', AVISO_19);
        ausenteManual($this->turno)->assertRedirect(route('admin.turnos.index'))->assertSessionHas('error', AVISO_19);
        expect($this->turno->fresh()->estado->codigo)->toBe(Estado::CONFIRMADO);
    });

    test('a las 19:00 en punto: "No se presentó" pasa a SALTADO', function () {
        horaLocal('2026-10-06 19:00:00');
        noSePresento($this->turno)->assertSessionHas('status');
        expect($this->turno->fresh()->estado->codigo)->toBe(Estado::SALTADO);
    });

    test('a las 19:00 en punto: "Ausente" del listado pasa a AUSENTE', function () {
        horaLocal('2026-10-06 19:00:00');
        ausenteManual($this->turno)->assertSessionHas('status');
        expect($this->turno->fresh()->estado->codigo)->toBe(Estado::AUSENTE);
    });

    test('después (19:20): también', function () {
        horaLocal('2026-10-06 19:20:00');
        noSePresento($this->turno)->assertSessionHas('status');
        expect($this->turno->fresh()->estado->codigo)->toBe(Estado::SALTADO);
    });

    test('pantalla Consulta: deshabilitado con "Disponible desde 19:00"; la actualización automática lo habilita a las 19:00', function () {
        horaLocal('2026-10-06 18:59:00');
        $antes = $this->get(route('admin.atencion.index'))->assertOk()->getContent();
        expect($antes)->toContain('Disponible desde 19:00')
            ->toMatch('#<button type="button" disabled[^>]*>No se presentó</button>#')
            ->not->toContain(route('admin.atencion.no-se-presento', $this->turno));

        horaLocal('2026-10-06 19:00:00');
        $fragmento = $this->get(route('admin.atencion.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        expect($fragmento)->not->toContain('Disponible desde 19:00')
            ->toContain('action="'.route('admin.atencion.no-se-presento', $this->turno).'"');
    });

    test('listado de Turnos: "Ausente" deshabilitado con "Disponible desde 19:00" hasta esa hora', function () {
        horaLocal('2026-10-06 18:59:00');
        $html = $this->get(route('admin.turnos.index'))->assertOk()->getContent();
        expect($html)->toContain('Disponible desde 19:00')->not->toContain('name="accion" value="ausente"')
            ->toContain('name="accion" value="cancelar"'); // cancelar sigue disponible

        horaLocal('2026-10-06 19:00:00');
        expect($this->get(route('admin.turnos.index'))->getContent())->toContain('name="accion" value="ausente"')->not->toContain('Disponible desde 19:00');
    });

    test('Cerrar jornada a las 18:00: cierra los que ya llegaron a su hora; el de las 19:00 queda pendiente y se muestra aparte', function () {
        $temprano = hcTurno(['hora_inicio' => '08:00', 'hora_fin' => '08:30']);
        horaLocal('2026-10-06 18:00:00');

        $this->get(route('admin.atencion.cerrar-jornada'))->assertOk()
            ->assertSeeInOrder(['Este turno pasará a ausente', '06/10/2026 08:00', 'Todavía no llegaron a su hora', CerrarJornada::AUN_NO_ES_SU_HORA, '06/10/2026 19:00']);

        $this->post(route('admin.atencion.cerrar-jornada.confirmar'))
            ->assertSessionHas('status', 'Jornada cerrada: 1 turno pasó a ausente.')
            ->assertSessionHas('aviso', '1 turno todavía no llegó a su hora y quedó pendiente. '.CerrarJornada::AUN_NO_ES_SU_HORA);
        expect($temprano->fresh()->estado->codigo)->toBe(Estado::AUSENTE)
            ->and($this->turno->fresh()->estado->codigo)->toBe(Estado::CONFIRMADO);
    });

    test('Cerrar jornada solo con turnos que todavía no llegaron a su hora: no cierra nada y no ofrece confirmar', function () {
        horaLocal('2026-10-06 18:00:00');
        $this->get(route('admin.atencion.cerrar-jornada'))->assertOk()
            ->assertSee('No hay turnos para pasar a ausente.')->assertDontSee('>Cerrar jornada</button>', false);
        $this->post(route('admin.atencion.cerrar-jornada.confirmar'))->assertSessionHas('status', 'No había turnos para cerrar.');
        expect($this->turno->fresh()->estado->codigo)->toBe(Estado::CONFIRMADO);
    });
});

describe('bordes de medianoche (Paraguay = UTC−3)', function () {
    test('turno de las 22:30: a las 22:29 no (01:29 UTC del día siguiente); a las 22:30 sí', function () {
        $turno = hcTurno(['hora_inicio' => '22:30', 'hora_fin' => '23:00']);

        horaLocal('2026-10-06 22:29:00');
        expect(now()->format('Y-m-d H:i'))->toBe('2026-10-07 01:29'); // en UTC ya es miércoles
        noSePresento($turno)->assertSessionHas('error', 'Todavía no llegó la hora del turno (22:30). Solo puede marcar la ausencia desde esa hora.');

        horaLocal('2026-10-06 22:30:00');
        noSePresento($turno)->assertSessionHas('status');
        expect($turno->fresh()->estado->codigo)->toBe(Estado::SALTADO);
    });

    test('turno del miércoles a las 00:30: a las 00:29 no; a las 00:30 sí; y el del martes 23:00 ya pasó', function () {
        $miercoles = hcTurno(['fecha' => '2026-10-07', 'hora_inicio' => '00:30', 'hora_fin' => '01:00']);
        $martes = hcTurno(['hora_inicio' => '23:00', 'hora_fin' => '23:30']);

        horaLocal('2026-10-07 00:29:00');
        ausenteManual($miercoles)->assertSessionHas('error', 'Todavía no llegó la hora del turno (00:30). Solo puede marcar la ausencia desde esa hora.');
        ausenteManual($martes)->assertSessionHas('status'); // día anterior: su hora ya pasó

        horaLocal('2026-10-07 00:30:00');
        ausenteManual($miercoles)->assertSessionHas('status');
        expect($miercoles->fresh()->estado->codigo)->toBe(Estado::AUSENTE)
            ->and($martes->fresh()->estado->codigo)->toBe(Estado::AUSENTE);
    });
});

test('la regla está en un solo lugar: Turno::pasarA la aplica aunque se llame directo', function () {
    $turno = hcTurno(['hora_inicio' => '19:00', 'hora_fin' => '19:30']);
    horaLocal('2026-10-06 18:59:00');

    expect(fn () => $turno->pasarA(Estado::AUSENTE))->toThrow(\App\Exceptions\AccionRechazada::class, AVISO_19)
        ->and(fn () => $turno->fresh()->pasarA(Estado::SALTADO))->toThrow(\App\Exceptions\AccionRechazada::class, AVISO_19);
    // Cancelar no depende de la hora.
    $turno->fresh()->pasarA(Estado::CANCELADO);
    expect($turno->fresh()->estado->codigo)->toBe(Estado::CANCELADO);
});
