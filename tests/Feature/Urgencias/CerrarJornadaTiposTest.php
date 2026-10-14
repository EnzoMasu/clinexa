<?php

/*
 * Cerrar jornada con los tipos nuevos: incluye los ENTRE_PACIENTES y URGENCIA no atendidos (pasan a AUSENTE como los
 * demás), exige la casilla "Confirmo que revisé las urgencias" cuando hay urgencias (de recepción o del triaje) y un
 * triaje urgente que pasa a ausente conserva lo cargado (su consulta EN_PREPARACION pasa a ANULADO, sin borrar nada).
 * "Ahora": martes 06/10/2026 09:00. Datos inventados.
 */

use App\Enums\TipoTurno;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\ExamenFisico;
use App\Models\LogAuditoria;
use App\Support\Atencion\CerrarJornada;
use Illuminate\Support\Carbon;

beforeEach(function () {
    epEscenario();
    $this->actingAs($this->medico);
});

afterEach(fn () => Carbon::setTestNow());

test('incluye entre pacientes y urgencias no atendidos: pasan a AUSENTE como los demás', function () {
    $entre = epTurno();
    $saltado = epTurno(cambios: ['paciente_id' => $this->otraPaciente->id, 'estado_id' => Estado::idDe(Estado::SALTADO)]);
    $normal = hcTurno(['hora_inicio' => '08:00', 'hora_fin' => '08:30', 'paciente_id' => epPaciente(['apellidos' => 'Normal', 'nombres' => 'Ana'], 'FP-C01')->id]);
    $deAyer = epTurno(cambios: ['fecha' => '2026-10-05', 'paciente_id' => epPaciente(['apellidos' => 'Ayer', 'nombres' => 'Ana'], 'FP-C02')->id]);

    $this->get(route('admin.atencion.cerrar-jornada'))->assertOk()
        ->assertSeeInOrder(['05/10/2026 Entre pacientes', 'Ayer, Ana', '06/10/2026 08:00', '06/10/2026 Entre pacientes'])
        ->assertDontSee('Atención: urgencias sin atender');

    $this->post(route('admin.atencion.cerrar-jornada.confirmar'))->assertSessionHas('status', 'Jornada cerrada: 4 turnos pasaron a ausente.');
    foreach ([$entre, $saltado, $normal, $deAyer] as $turno) {
        expect($turno->fresh()->estado->codigo)->toBe(Estado::AUSENTE);
    }
});

test('con urgencias: sección roja y la casilla, que el servidor exige', function () {
    $urgencia = epTurno(TipoTurno::URGENCIA);
    $marcado = hcTurno(['hora_inicio' => '08:00', 'hora_fin' => '08:30', 'paciente_id' => $this->otraPaciente->id]);
    epTriaje($marcado, urgente: true, observacion: 'Dolor torácico.');

    $this->get(route('admin.atencion.cerrar-jornada'))->assertOk()
        ->assertSee('Atención: urgencias sin atender')
        ->assertSeeInOrder(['Duarte, Carmen (urgencia de recepción', 'Gómez, Lidia (triaje urgente'])
        ->assertSee('Confirmo que revisé las urgencias')
        ->assertDontSee('Dolor torácico.');

    $this->from(route('admin.atencion.cerrar-jornada'))->post(route('admin.atencion.cerrar-jornada.confirmar'))
        ->assertRedirect(route('admin.atencion.cerrar-jornada'))->assertSessionHas('error', CerrarJornada::FALTA_CONFIRMAR_URGENCIAS);
    expect($urgencia->fresh()->estado->codigo)->toBe(Estado::PENDIENTE)->and($marcado->fresh()->estado->codigo)->toBe(Estado::CONFIRMADO);

    $this->post(route('admin.atencion.cerrar-jornada.confirmar'), ['confirmo_urgencias' => '1'])->assertSessionHas('status', 'Jornada cerrada: 1 turno pasó a ausente y 1 turno pasó a retirado.');
    // El del triaje (terminado) pasa a retirado, no a ausente (docs/retiros.md).
    expect($marcado->fresh()->estado->codigo)->toBe(Estado::RETIRADO)
        ->and($urgencia->fresh()->estado->codigo)->toBe(Estado::AUSENTE)
        ->and(LogAuditoria::where('tabla_afectada', 'turnos')->where('registro_afectado_id', $urgencia->id)->latest('id')->first()->detalle)
        ->toBe('Cierre de jornada. Urgencias sin atender revisadas: 2');
});

test('un triaje urgente que todavía no llegó a su hora no se cierra ni pide la casilla', function () {
    $marcado = hcTurno(['hora_inicio' => '11:00', 'hora_fin' => '11:30']); // a las 11:00: aún no es su hora
    epTriaje($marcado, urgente: true);
    epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);

    $this->get(route('admin.atencion.cerrar-jornada'))->assertOk()->assertDontSee('Atención: urgencias sin atender');
    $this->post(route('admin.atencion.cerrar-jornada.confirmar'))->assertSessionHas('status', 'Jornada cerrada: 1 turno pasó a ausente.');
    expect($marcado->fresh()->estado->codigo)->toBe(Estado::CONFIRMADO);
});

test('un paciente con triaje urgente que pasa a retirado NO pierde lo cargado: la consulta queda ANULADO con todo', function () {
    $turno = epTurno(TipoTurno::URGENCIA);
    $consulta = epTriaje($turno, urgente: true, observacion: 'Sangrado abundante desde la mañana.', alterados: true);
    $consulta->forceFill(['motivo_consulta' => null])->save();
    $examen = ExamenFisico::where('consulta_id', $consulta->id)->sole()->getAttributes();

    $this->post(route('admin.atencion.cerrar-jornada.confirmar'), ['confirmo_urgencias' => '1'])->assertSessionHasNoErrors();

    $despues = Consulta::findOrFail($consulta->id);
    expect($turno->fresh()->estado->codigo)->toBe(Estado::RETIRADO)
        ->and($despues->estado->codigo)->toBe(Estado::ANULADO)
        ->and($despues->triaje_urgente)->toBeTrue()
        ->and($despues->triaje_observacion)->toBe('Sangrado abundante desde la mañana.')
        ->and($despues->triaje_urgente_en)->not->toBeNull()
        ->and(ExamenFisico::where('consulta_id', $consulta->id)->sole()->getAttributes())->toBe($examen);
});
