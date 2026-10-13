<?php

/*
 * Cupo entre pacientes (CupoEntrePacientesController): se mira con VER y se fija con CREAR o EDITAR sobre
 * DISPONIBILIDAD; solo para un profesional que atiende ese día, de hoy a 60 días, y nunca por debajo de lo ya
 * agendado. "Ahora": martes 06/10/2026 09:00 en Paraguay. Datos inventados.
 */

use App\Enums\AccionAuditoria;
use App\Http\Controllers\Admin\CupoEntrePacientesController;
use App\Models\CupoEntrePacientes;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    epEscenario();
    $this->actingAs($this->recepcion);
});

afterEach(fn () => Carbon::setTestNow());

function cupoFijar(int $cantidad, string $fecha = '06/10/2026', $profesional = null)
{
    return test()->put(route('admin.turnos.cupos.fijar', $profesional ?? test()->profesional), ['fecha' => $fecha, 'cantidad' => $cantidad, 'fila' => ($profesional ?? test()->profesional)->id]);
}

test('el panel muestra, para la fecha (hoy por defecto), los profesionales que atienden con cupo y usados', function () {
    epCupo(3);
    epTurno();
    epTurno(cambios: ['paciente_id' => $this->otraPaciente->id, 'estado_id' => Estado::idDe(Estado::CANCELADO)]);

    $this->get(route('admin.turnos.cupos'))->assertOk()
        ->assertSee('Benítez, Rosa')->assertDontSee('Insfrán, Laura') // el Dr. Insfrán no atiende los martes
        ->assertSeeInOrder(['Benítez, Rosa', '3', '1 de 3'])
        ->assertSee('name="cantidad"', false);

    // Otro día en que no atiende nadie.
    $this->get(route('admin.turnos.cupos', ['fecha' => '07/10/2026']))->assertOk()->assertSee('Ningún profesional atiende el 07/10/2026.');
});

test('fijar el cupo lo crea con CREAR auditado; subirlo lo edita con antes y después', function () {
    cupoFijar(2)->assertRedirect(route('admin.turnos.cupos', ['fecha' => '06/10/2026']))->assertSessionHasNoErrors();
    $cupo = CupoEntrePacientes::sole();
    expect($cupo->cantidad)->toBe(2)->and($cupo->creado_por)->toBe($this->recepcion->id);

    cupoFijar(5)->assertSessionHasNoErrors();
    expect($cupo->fresh()->cantidad)->toBe(5)->and(CupoEntrePacientes::count())->toBe(1);

    $eventos = LogAuditoria::where('tabla_afectada', 'cupos_entre_pacientes')->orderBy('id')->get();
    expect($eventos->pluck('accion')->map(fn ($accion) => $accion instanceof AccionAuditoria ? $accion->value : $accion)->all())->toBe(['CREAR', 'EDITAR'])
        ->and($eventos[1]->valor_anterior)->toMatchArray(['cantidad' => 2])
        ->and($eventos[1]->valor_nuevo)->toMatchArray(['cantidad' => 5]);
});

test('no se baja por debajo de los ya agendados no cancelados; a lo justo, sí', function () {
    epCupo(3);
    epTurno();
    epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);
    epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => 'Vera', 'nombres' => 'Ana'], 'FP-0000003')->id, 'estado_id' => Estado::idDe(Estado::CANCELADO)]);

    cupoFijar(1)->assertSessionHasErrors(['cantidad.'.$this->profesional->id => 'No se puede bajar el cupo a 1: ya hay 2 pacientes entre pacientes agendados ese día.']);
    expect(CupoEntrePacientes::sole()->cantidad)->toBe(3);

    cupoFijar(2)->assertSessionHasNoErrors();
    expect(CupoEntrePacientes::sole()->cantidad)->toBe(2);
});

test('el profesional debe atender ese día', function () {
    cupoFijar(2, profesional: $this->otroProfesional)
        ->assertSessionHasErrors(['cantidad.'.$this->otroProfesional->id => CupoEntrePacientesController::NO_ATIENDE]);

    // Disponibilidad inactiva: tampoco.
    $this->disponibilidad->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);
    cupoFijar(2)->assertSessionHasErrors(['cantidad.'.$this->profesional->id => CupoEntrePacientesController::NO_ATIENDE]);
    expect(CupoEntrePacientes::count())->toBe(0);
});

test('fecha de hoy a 60 días: ni pasada ni más lejos', function () {
    cupoFijar(2, '29/09/2026')->assertSessionHasErrors(['fecha' => 'No se fija el cupo de un día que ya pasó.']);
    cupoFijar(2, '08/12/2026')->assertSessionHasErrors(['fecha' => 'El cupo se fija hasta 60 días adelante.']); // martes, a 63 días
    cupoFijar(2, '01/12/2026')->assertSessionHasNoErrors(); // martes, a 56 días
    expect(CupoEntrePacientes::sole()->fecha->format('Y-m-d'))->toBe('2026-12-01');
});

test('cantidad de 0 a 30', function () {
    cupoFijar(31)->assertSessionHasErrors('cantidad');
    cupoFijar(-1)->assertSessionHasErrors('cantidad');
    cupoFijar(30)->assertSessionHasNoErrors();
    cupoFijar(0)->assertSessionHasNoErrors();
    expect(CupoEntrePacientes::sole()->cantidad)->toBe(0);
});

test('permisos: VER sobre DISPONIBILIDAD para mirar; CREAR o EDITAR para fijar', function () {
    $soloVer = User::factory()->conPermisos(['DISPONIBILIDAD' => ['VER']])->create();
    $this->actingAs($soloVer)->get(route('admin.turnos.cupos'))->assertOk()->assertDontSee('name="cantidad"', false);
    $this->actingAs($soloVer);
    cupoFijar(2)->assertForbidden();

    $this->actingAs(User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR']])->create())->get(route('admin.turnos.cupos'))->assertForbidden();

    $this->actingAs(User::factory()->conPermisos(['DISPONIBILIDAD' => ['EDITAR']])->create());
    cupoFijar(2)->assertSessionHasNoErrors();
    expect(CupoEntrePacientes::sole()->cantidad)->toBe(2);
});
