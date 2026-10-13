<?php

/*
 * Urgencias de recepción (UrgenciaController, RegistrarUrgencia): un turno URGENCIA de hoy, PENDIENTE, presencial,
 * con llegada_en; una sola abierta por paciente, profesional y día; cancelación solo en PENDIENTE; nada clínico.
 * "Ahora": martes 06/10/2026 09:00 en Paraguay. Datos inventados.
 */

use App\Enums\TipoTurno;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\OrigenTurno;
use App\Models\Turno;
use App\Support\Turnos\RegistrarUrgencia;
use Illuminate\Support\Carbon;

beforeEach(function () {
    epEscenario();
    $this->actingAs($this->recepcion);
});

afterEach(fn () => Carbon::setTestNow());

function urgenciaRegistrar(array $cambios = [])
{
    return test()->post(route('admin.urgencias.store'), ['paciente_id' => test()->paciente->id, 'profesional_id' => test()->profesional->id, ...$cambios]);
}

test('se registra: URGENCIA de hoy, PENDIENTE, presencial, llegada ahora, sin horas; auditada "Urgencia registrada"', function () {
    urgenciaRegistrar()->assertRedirect(route('admin.urgencias.index'))
        ->assertSessionHas('status', 'Urgencia registrada: Duarte, Carmen, con Benítez, Rosa.');

    $turno = Turno::sole();
    expect($turno->tipo)->toBe(TipoTurno::URGENCIA)
        ->and($turno->fecha->format('Y-m-d'))->toBe('2026-10-06')
        ->and($turno->estado->codigo)->toBe(Estado::PENDIENTE)
        ->and($turno->origen_turno_id)->toBe($this->presencial->id)
        ->and($turno->llegada_en->equalTo(now()))->toBeTrue()
        ->and($turno->hora_inicio)->toBeNull()->and($turno->consultorio_id)->toBeNull();

    expect(LogAuditoria::where('tabla_afectada', 'turnos')->sole()->detalle)->toBe('Urgencia registrada');
});

test('cualquier profesional activo, aunque no atienda hoy; el formulario los ordena: primero los de hoy', function () {
    $this->get(route('admin.urgencias.create'))->assertOk()
        ->assertSeeInOrder(['Atienden hoy', 'Benítez, Rosa', 'Otros profesionales', 'Insfrán, Laura'])
        ->assertSee('Si el paciente no aparece, puede que todavía no esté registrado o que esté inactivo.')
        ->assertDontSee('<textarea', false);

    urgenciaRegistrar(['profesional_id' => $this->otroProfesional->id])->assertSessionHasNoErrors();
});

test('paciente inactivo o no registrado: no', function () {
    $this->paciente->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);
    urgenciaRegistrar()->assertSessionHasErrors(['paciente_id' => 'El paciente no está registrado o está inactivo.']);
    urgenciaRegistrar(['paciente_id' => 999999])->assertSessionHasErrors(['paciente_id' => 'El paciente no está registrado o está inactivo.']);
    urgenciaRegistrar(['paciente_id' => ''])->assertSessionHasErrors('paciente_id');
    expect(Turno::count())->toBe(0);
});

test('doble envío: una sola urgencia abierta por paciente, profesional y día', function () {
    urgenciaRegistrar()->assertSessionHasNoErrors();
    urgenciaRegistrar(['agendar_igual' => '1'])->assertSessionHasErrors(['paciente_id' => RegistrarUrgencia::YA_TIENE]);
    expect(Turno::count())->toBe(1);

    // Con otro profesional, sí (pide confirmar si ya tiene turno con ese profesional; con otro, no).
    urgenciaRegistrar(['profesional_id' => $this->otroProfesional->id])->assertSessionHasNoErrors();
    expect(Turno::count())->toBe(2);
});

test('el índice único de la base frena la segunda aunque se saltee la validación (concurrencia)', function () {
    epTurno(TipoTurno::URGENCIA);

    expect(fn () => epTurno(TipoTurno::URGENCIA))->toThrow(\Illuminate\Database\QueryException::class);
    // Cerrada (ATENDIDO, CANCELADO o AUSENTE) ya no cuenta como abierta.
    Turno::query()->update(['estado_id' => Estado::idDe(Estado::ATENDIDO)]);
    epTurno(TipoTurno::URGENCIA);
    expect(Turno::count())->toBe(2);
});

test('abierta incluye PENDIENTE, CONFIRMADO, SALTADO y EN_CONSULTA', function (string $estado) {
    epTurno(TipoTurno::URGENCIA, ['estado_id' => Estado::idDe($estado)]);
    urgenciaRegistrar(['agendar_igual' => '1'])->assertSessionHasErrors(['paciente_id' => RegistrarUrgencia::YA_TIENE]);
})->with(['PENDIENTE', 'CONFIRMADO', 'SALTADO', 'EN_CONSULTA']);

test('segundo turno del día con el profesional: pide confirmar', function () {
    hcTurno();
    urgenciaRegistrar()->assertSessionHasErrors(['agendar_igual' => 'La paciente ya tiene turno con Rosa Benítez hoy a las 08:00.']);
    urgenciaRegistrar(['agendar_igual' => '1'])->assertSessionHasNoErrors();
    expect(LogAuditoria::where('tabla_afectada', 'turnos')->latest('id')->first()->detalle)->toBe('Urgencia registrada. Segundo turno del día confirmado');
});

test('sin el origen PRESENCIAL (buscado por código), un error claro', function () {
    $this->presencial->update(['codigo' => 'OTRO']);
    OrigenTurno::create(['codigo' => 'X', 'nombre' => 'Presencial']); // mismo nombre, otro código: no sirve

    expect(fn () => RegistrarUrgencia::ejecutar($this->paciente->id, $this->profesional->id))
        ->toThrow(RuntimeException::class, 'Falta el origen de turno con código PRESENCIAL');
});

test('cancelar solo mientras está PENDIENTE, con EDITAR sobre TURNOS', function () {
    $turno = epTurno(TipoTurno::URGENCIA);

    $this->patch(route('admin.urgencias.cancelar', $turno))->assertRedirect(route('admin.urgencias.index'))->assertSessionHas('status', 'Urgencia cancelada.');
    expect($turno->fresh()->estado->codigo)->toBe(Estado::CANCELADO)
        ->and(LogAuditoria::where('tabla_afectada', 'turnos')->latest('id')->first()->detalle)->toBe('Urgencia cancelada');

    $confirmada = epTurno(TipoTurno::URGENCIA, ['estado_id' => Estado::idDe(Estado::CONFIRMADO)]);
    $this->patch(route('admin.urgencias.cancelar', $confirmada))->assertSessionHas('error', 'Solo se cancela una urgencia pendiente (esta está confirmado).');
    expect($confirmada->fresh()->estado->codigo)->toBe(Estado::CONFIRMADO);

    // Un turno que no es urgencia: 404.
    $this->patch(route('admin.urgencias.cancelar', hcTurno(['hora_inicio' => '11:00', 'hora_fin' => '11:30'])))->assertNotFound();

    // Sin EDITAR: 403.
    $this->actingAs(\App\Models\User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR']])->create());
    $this->patch(route('admin.urgencias.cancelar', epTurno(TipoTurno::URGENCIA, ['paciente_id' => $this->otraPaciente->id])))->assertForbidden();
});

test('el panel de recepción: paciente, profesional, hora de registro y estado; nada clínico ni urgencias del triaje', function () {
    urgenciaRegistrar()->assertSessionHasNoErrors();
    // Un turno normal que el triaje marcó urgente, con observación y signos: recepción no lo ve aquí.
    $normal = hcTurno(['paciente_id' => $this->otraPaciente->id]);
    $consulta = new Consulta(['historia_clinica_id' => $this->otraPaciente->historiaClinica->id, 'turno_id' => $normal->id, 'profesional_id' => $this->profesional->id]);
    $consulta->forceFill(['estado_id' => Estado::idDe(Estado::EN_PREPARACION), 'triaje_urgente' => true, 'triaje_observacion' => 'OBSERVACION-SECRETA dolor torácico'])->save();
    $urgencia = Turno::where('tipo', 'URGENCIA')->sole();
    $consultaUrgencia = new Consulta(['historia_clinica_id' => $this->historia->id, 'turno_id' => $urgencia->id, 'profesional_id' => $this->profesional->id]);
    $consultaUrgencia->forceFill(['estado_id' => Estado::idDe(Estado::EN_PREPARACION), 'triaje_urgente' => true, 'triaje_observacion' => 'OTRA-OBSERVACION-SECRETA'])->save();

    $this->get(route('admin.urgencias.index'))->assertOk()
        ->assertSeeInOrder(['09:00', 'Duarte, Carmen', 'Benítez, Rosa', 'PENDIENTE'])
        ->assertDontSee('Gómez, Lidia')
        ->assertDontSee('OBSERVACION-SECRETA')->assertDontSee('Signos alterados')->assertDontSee('URGENTE');

    // Tampoco en el listado de Turnos.
    $this->get(route('admin.turnos.index'))->assertOk()->assertDontSee('OBSERVACION-SECRETA')->assertDontSee('Signos alterados')->assertDontSee('>URGENTE<', false);
});

test('Turnos muestra los botones según permisos', function () {
    $this->get(route('admin.turnos.index'))->assertOk()->assertSee('Registrar urgencia')->assertSee('Urgencias de hoy')->assertSee('Cupo entre pacientes');

    $this->actingAs(\App\Models\User::factory()->conPermisos(['TURNOS' => ['VER']])->create());
    $this->get(route('admin.turnos.index'))->assertOk()->assertDontSee('Registrar urgencia')->assertSee('Urgencias de hoy')->assertDontSee('Cupo entre pacientes');
    $this->get(route('admin.urgencias.create'))->assertForbidden();
});
