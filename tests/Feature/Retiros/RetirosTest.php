<?php

/*
 * Retiros y rechazos (docs/retiros.md): las transiciones permitidas y prohibidas, el triaje terminado (solo retiro), el
 * cargo pendiente, la nota clínica una sola vez, el cierre de jornada (retirado / ausente) y que recepción no vea nada
 * clínico. "Ahora": martes 06/10/2026 09:00. Datos inventados. Funciones con el prefijo "rt".
 */

use App\Enums\AccionAuditoria;
use App\Enums\TipoTurno;
use App\Exceptions\AccionRechazada;
use App\Exceptions\RegistroInmutable;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\NotaSalida;
use App\Models\SalidaSinAtencion;
use App\Models\Turno;
use App\Models\User;
use App\Support\Atencion\GuardarNotaSalida;
use App\Support\Auditoria;
use Illuminate\Support\Carbon;

beforeEach(fn () => epEscenario());

afterEach(fn () => Carbon::setTestNow());

/** Recepción registra la salida del turno por el botón de Turnos. */
function rtRegistrar(Turno $turno, string $accion = 'retiro')
{
    return test()->actingAs(test()->recepcion)->from(route('admin.turnos.index'))->patch(route('admin.turnos.estado', $turno), ['accion' => $accion]);
}

function rtNota(Turno $turno, string $texto = 'Se retiró con dolor leve; se le explicó el riesgo.', ?User $usuario = null)
{
    return test()->actingAs($usuario ?? test()->medico)->post(route('admin.atencion.salidas.nota', $turno->fresh()->salida), ['texto' => $texto]);
}

test('transiciones permitidas: retiro y rechazo desde pendiente, confirmado y saltado', function () {
    foreach ([Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO] as $i => $estado) {
        foreach (['retiro' => SalidaSinAtencion::RETIRO, 'rechazo' => SalidaSinAtencion::RECHAZO] as $accion => $tipo) {
            $turno = epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => "P{$i}{$accion}", 'nombres' => 'Ana'], "FP-R{$i}{$accion}")->id, 'estado_id' => Estado::idDe($estado)]);

            rtRegistrar($turno, $accion)->assertSessionHas('status');

            $turno = $turno->fresh();
            expect($turno->estado->codigo)->toBe(Estado::RETIRADO)
                ->and($turno->salida->tipo)->toBe($tipo)->and($turno->salida->origen)->toBe(SalidaSinAtencion::ORIGEN_RECEPCION)
                ->and($turno->salida->usuario_id)->toBe($this->recepcion->id);
        }
    }
});

test('transiciones prohibidas: en consulta, atendido, cancelado, ausente, retirado y fecha futura', function () {
    foreach ([Estado::EN_CONSULTA, Estado::ATENDIDO, Estado::CANCELADO, Estado::AUSENTE, Estado::RETIRADO] as $i => $estado) {
        $turno = epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => "N{$i}", 'nombres' => 'Ana'], "FP-N{$i}")->id, 'estado_id' => Estado::idDe($estado)]);

        rtRegistrar($turno)->assertSessionHas('error');

        expect($turno->fresh()->estado->codigo)->toBe($estado)->and(SalidaSinAtencion::count())->toBe(0);
    }

    $futuro = epTurno(cambios: ['fecha' => '2026-10-13']);
    rtRegistrar($futuro)->assertSessionHas('error');
    expect($futuro->fresh()->estado->codigo)->toBe(Estado::PENDIENTE)->and(SalidaSinAtencion::count())->toBe(0);

    // Un retiro registrado no vuelve atrás ni se registra dos veces.
    $retirado = epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);
    rtRegistrar($retirado)->assertSessionHas('status');
    rtRegistrar($retirado, 'rechazo')->assertSessionHas('error');
    rtRegistrar($retirado, 'cancelar')->assertSessionHas('error');
    expect(SalidaSinAtencion::count())->toBe(1)->and($retirado->fresh()->estado->codigo)->toBe(Estado::RETIRADO);
});

test('con el triaje terminado no puede pasar a cancelado ni a ausente: solo se registra su retiro, con cargo pendiente', function () {
    $turno = epTurno(TipoTurno::ENTRE_PACIENTES);
    $consulta = epTriaje($turno, urgente: true, observacion: 'Dolor torácico.', terminado: true);

    foreach (['cancelar', 'ausente'] as $accion) {
        rtRegistrar($turno, $accion)->assertSessionHas('error', Turno::SOLO_RETIRO);
        expect($turno->fresh()->estado->codigo)->toBe(Estado::PENDIENTE)->and($turno->fresh()->puede($accion))->toBeFalse();
    }
    expect(fn () => $turno->fresh()->pasarA(Estado::CANCELADO))->toThrow(AccionRechazada::class, Turno::SOLO_RETIRO)
        ->and(fn () => $turno->fresh()->pasarA(Estado::AUSENTE))->toThrow(AccionRechazada::class, Turno::SOLO_RETIRO);

    rtRegistrar($turno)->assertSessionHas('status');

    $salida = $turno->fresh()->salida;
    expect($turno->fresh()->estado->codigo)->toBe(Estado::RETIRADO)
        ->and($salida->triaje_terminado)->toBeTrue()->and($salida->cargo_pendiente)->toBeTrue()
        ->and($salida->getAttributes())->not->toHaveKeys(['monto', 'tarifa', 'pagador_id', 'responsable_pago_id']);
    // Nada se borra: la consulta queda ANULADO con el triaje intacto.
    $consulta->refresh();
    expect($consulta->estado->codigo)->toBe(Estado::ANULADO)->and($consulta->triaje_observacion)->toBe('Dolor torácico.')->and($consulta->triaje_urgente)->toBeTrue();
});

test('con el triaje apenas iniciado todavía puede cancelarse; su retiro no genera cargo', function () {
    $iniciado = epTurno();
    epTriaje($iniciado, terminado: false);
    $otro = epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);
    epTriaje($otro, terminado: false);

    rtRegistrar($iniciado, 'cancelar')->assertSessionHas('status');
    expect($iniciado->fresh()->estado->codigo)->toBe(Estado::CANCELADO);

    rtRegistrar($otro, 'rechazo')->assertSessionHas('status');
    expect($otro->fresh()->salida->cargo_pendiente)->toBeFalse()->and($otro->fresh()->salida->triaje_terminado)->toBeFalse();
});

test('sin triaje, el retiro no genera cargo pendiente', function () {
    $turno = epTurno();
    rtRegistrar($turno);
    expect($turno->fresh()->salida->cargo_pendiente)->toBeFalse();
});

test('nota clínica: solo el profesional del turno, una sola vez, y no se modifica', function () {
    $turno = epTurno();
    rtRegistrar($turno);
    $salida = $turno->fresh()->salida;

    // Otro profesional y recepción: 403.
    $this->actingAs($this->otroMedico)->post(route('admin.atencion.salidas.nota', $salida), ['texto' => 'Nota ajena.'])->assertForbidden();
    $this->actingAs($this->recepcion)->post(route('admin.atencion.salidas.nota', $salida), ['texto' => 'Nota de recepción.'])->assertForbidden();
    expect(NotaSalida::count())->toBe(0);

    rtNota($turno)->assertRedirect(route('admin.atencion.salidas'))->assertSessionHas('status');
    $nota = NotaSalida::sole();
    expect($nota->texto)->toBe('Se retiró con dolor leve; se le explicó el riesgo.')->and($nota->profesional_id)->toBe($this->profesional->id);

    // Una segunda no se guarda (ni por la pantalla ni por el servicio) y la primera no cambia.
    rtNota($turno, 'Otro texto distinto.')->assertSessionHas('error', GuardarNotaSalida::YA_TIENE_NOTA);
    $this->actingAs($this->medico);
    expect(fn () => GuardarNotaSalida::ejecutar($this->medico, $salida, 'Otra vez.'))->toThrow(AccionRechazada::class, GuardarNotaSalida::YA_TIENE_NOTA)
        ->and(fn () => $nota->update(['texto' => 'Cambiada.']))->toThrow(RegistroInmutable::class)
        ->and(NotaSalida::sole()->texto)->toBe('Se retiró con dolor leve; se le explicó el riesgo.');

    // La nota vacía o enorme se rechaza.
    $otro = epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);
    rtRegistrar($otro);
    rtNota($otro, '')->assertSessionHasErrors('texto');
    rtNota($otro, str_repeat('a', NotaSalida::TEXTO_MAXIMO + 1))->assertSessionHasErrors('texto');
    expect(NotaSalida::count())->toBe(1);
});

test('cerrar jornada: triaje iniciado o terminado pasa a retirado; los demás, a ausente', function () {
    $terminado = epTurno();
    epTriaje($terminado, terminado: true);
    $iniciado = epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);
    epTriaje($iniciado, terminado: false);
    $sinTriaje = epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => 'Sin', 'nombres' => 'Triaje'], 'FP-S1')->id]);

    $this->actingAs($this->medico)->post(route('admin.atencion.cerrar-jornada.confirmar'))
        ->assertSessionHas('status', 'Jornada cerrada: 1 turno pasó a ausente y 2 turnos pasaron a retirado.');

    expect($terminado->fresh()->estado->codigo)->toBe(Estado::RETIRADO)
        ->and($terminado->fresh()->salida->only(['tipo', 'origen', 'triaje_terminado', 'cargo_pendiente']))
        ->toBe(['tipo' => 'RETIRO', 'origen' => SalidaSinAtencion::ORIGEN_CIERRE_JORNADA, 'triaje_terminado' => true, 'cargo_pendiente' => true])
        ->and($iniciado->fresh()->estado->codigo)->toBe(Estado::RETIRADO)
        ->and($iniciado->fresh()->salida->cargo_pendiente)->toBeFalse()
        ->and($sinTriaje->fresh()->estado->codigo)->toBe(Estado::AUSENTE)->and($sinTriaje->fresh()->salida)->toBeNull();
    // Lo cargado se conserva (la consulta queda ANULADO, no se borra).
    expect(Consulta::where('turno_id', $terminado->id)->sole()->estado->codigo)->toBe(Estado::ANULADO);

    // Un segundo cierre no encuentra nada.
    $this->post(route('admin.atencion.cerrar-jornada.confirmar'))->assertSessionHas('status', 'No había turnos para cerrar.');
    expect(SalidaSinAtencion::count())->toBe(2);
});

test('recepción no ve nada clínico: ni urgencia, ni observación, ni la nota, ni la pantalla del profesional', function () {
    $turno = epTurno(TipoTurno::URGENCIA);
    epTriaje($turno, urgente: true, observacion: 'Observación reservada del triaje.');
    rtRegistrar($turno);
    rtNota($turno, 'Nota clínica reservada.');

    $this->actingAs($this->recepcion);
    foreach ([route('admin.turnos.index'), route('admin.turnos.urgencias'), route('admin.agenda-general.index', ['fecha' => '06/10/2026'])] as $url) {
        $this->get($url)->assertOk()->assertDontSee('Observación reservada')->assertDontSee('Nota clínica reservada')->assertDontSee('URGENTE');
    }
    $this->get(route('admin.turnos.index'))->assertSee('RETIRADO');
    $this->get(route('admin.atencion.salidas'))->assertForbidden();
    $this->get(route('admin.atencion.index'))->assertForbidden();

    // El profesional del turno sí la ve.
    $this->actingAs($this->medico)->get(route('admin.atencion.salidas'))->assertOk()->assertSee('Nota clínica reservada')->assertDontSee('Guardar nota');
});

test('todo queda en auditoría y la nota clínica se oculta a quien no tiene VER sobre la historia clínica', function () {
    $turno = epTurno();
    rtRegistrar($turno);
    rtNota($turno, 'Nota clínica reservada.');

    expect(Auditoria::permisoDeContenido('notas_salida'))->toBe('HISTORIA_CLINICA')->and(Auditoria::permisoDeContenido('salidas_sin_atencion'))->toBeNull();
    $salida = LogAuditoria::where('tabla_afectada', 'salidas_sin_atencion')->where('accion', AccionAuditoria::CREAR->value)->sole();
    expect($salida->detalle)->toBe('Retiro registrado')->and($salida->usuario_id)->toBe($this->recepcion->id)
        ->and(LogAuditoria::where('tabla_afectada', 'turnos')->where('registro_afectado_id', $turno->id)->where('detalle', 'Retiro registrado')->exists())->toBeTrue();
    $evento = LogAuditoria::where('tabla_afectada', 'notas_salida')->where('accion', AccionAuditoria::CREAR->value)->sole();
    expect($evento->usuario_id)->toBe($this->medico->id);

    $auditor = User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'TURNOS' => ['VER']])->conPersona(['apellidos' => 'Auditor', 'nombres' => 'Pedro'])->create();
    $this->actingAs($auditor)->get(route('admin.auditoria.index'))->assertOk()->assertDontSee('Nota clínica reservada');
    $this->get(route('admin.auditoria.show', $salida))->assertOk()->assertSee('Retiro registrado');
    $this->get(route('admin.auditoria.show', $evento))->assertOk()->assertDontSee('Nota clínica reservada');
});

test('un turno retirado sigue ocupando su horario (solo CANCELADO lo libera)', function () {
    $turno = hcTurno(['hora_inicio' => '09:00', 'hora_fin' => '09:30']);
    rtRegistrar($turno);

    expect(Turno::ocupanHorario()->whereKey($turno->id)->exists())->toBeTrue();
});

test('carreras: un turno leído antes de que otra pantalla lo cambie no se pisa', function () {
    $viejo = epTurno(); // la pantalla de recepción lo leyó PENDIENTE
    rtRegistrar(Turno::findOrFail($viejo->id)); // otra pantalla registra el retiro

    expect(fn () => $viejo->pasarA(Estado::CANCELADO))->toThrow(AccionRechazada::class, Turno::CAMBIO_MIENTRAS_TANTO)
        ->and($viejo->fresh()->estado->codigo)->toBe(Estado::RETIRADO);
});

test('carreras: si el triaje se termina después de leer el turno, igual no se cancela ni pasa a ausente', function () {
    $turno = epTurno();
    $turno->setRelation('consulta', null); // lo que había al leerlo: sin triaje
    epTriaje($turno, terminado: true);     // mientras tanto, enfermería terminó el triaje

    expect(fn () => $turno->pasarA(Estado::CANCELADO))->toThrow(AccionRechazada::class, Turno::SOLO_RETIRO)
        ->and(fn () => $turno->pasarA(Estado::AUSENTE))->toThrow(AccionRechazada::class, Turno::SOLO_RETIRO)
        ->and($turno->fresh()->estado->codigo)->toBe(Estado::PENDIENTE);
});

test('carreras: cierre de jornada y retiro de recepción no se duplican, en ningún orden', function () {
    $antes = epTurno();
    epTriaje($antes, terminado: true);
    $despues = epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);
    epTriaje($despues, terminado: true);

    rtRegistrar($antes)->assertSessionHas('status'); // recepción primero
    $this->actingAs($this->medico)->post(route('admin.atencion.cerrar-jornada.confirmar'))
        ->assertSessionHas('status', 'Jornada cerrada: 1 turno pasó a retirado.');
    rtRegistrar($despues, 'rechazo')->assertSessionHas('error'); // el cierre primero

    expect(SalidaSinAtencion::count())->toBe(2)
        ->and(SalidaSinAtencion::where('cargo_pendiente', true)->count())->toBe(2)
        ->and($antes->fresh()->salida->origen)->toBe(SalidaSinAtencion::ORIGEN_RECEPCION)
        ->and($despues->fresh()->salida->origen)->toBe(SalidaSinAtencion::ORIGEN_CIERRE_JORNADA);
});

test('la base impide una segunda salida por turno (un solo cargo) y una segunda nota por salida', function () {
    $turno = epTurno();
    rtRegistrar($turno);
    rtNota($turno);
    $salida = $turno->fresh()->salida;

    expect(fn () => SalidaSinAtencion::create(['turno_id' => $turno->id, 'tipo' => 'RETIRO', 'origen' => 'RECEPCION', 'triaje_terminado' => true, 'cargo_pendiente' => true, 'usuario_id' => $this->recepcion->id]))
        ->toThrow(\Illuminate\Database\QueryException::class)
        ->and(fn () => NotaSalida::create(['salida_id' => $salida->id, 'texto' => 'Segunda.', 'profesional_id' => $this->profesional->id, 'usuario_id' => $this->medico->id]))
        ->toThrow(\Illuminate\Database\QueryException::class)
        ->and(SalidaSinAtencion::count())->toBe(1)->and(NotaSalida::count())->toBe(1);
});

test('recepción no ve los botones de cancelar ni ausente si el triaje está terminado, ni el de retiro en un turno futuro', function () {
    $terminado = epTurno();
    epTriaje($terminado, terminado: true);
    $futuro = epTurno(cambios: ['fecha' => '2026-10-13', 'paciente_id' => $this->otraPaciente->id]);

    expect($terminado->fresh()->load('consulta')->accionesPosibles())->toBe(['confirmar', 'retiro', 'rechazo'])
        ->and($futuro->fresh()->accionesPosibles())->toBe(['confirmar', 'ausente', 'cancelar']);
});
