<?php

use App\Models\Consultorio;
use App\Models\Disponibilidad;
use App\Models\Estado;
use App\Models\OrigenTurno;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use App\Support\Agenda;
use Database\Seeders\DatosRealesClinicaSeeder;
use Illuminate\Support\Carbon;

/*
 * "Ahora" fijo: lunes 05/10/2026 09:00 en Paraguay (12:00 UTC). El martes siguiente es 06/10/2026.
 * Disponibilidad base: martes de 08:00 a 10:00, turnos de 30 minutos, vigente desde el 01/01/2026.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
    $this->actingAs(User::factory()->administrador()->create());

    $this->sucursal = Sucursal::create(['nombre' => 'Plenitud Mujer', 'direccion' => 'Iturbe', 'telefono' => '0975']);
    $this->consultorio = Consultorio::create(['sucursal_id' => $this->sucursal->id, 'nombre' => 'Consultorio 1']);
    $this->consultorio2 = Consultorio::create(['sucursal_id' => $this->sucursal->id, 'nombre' => 'Consultorio 2']);
    $this->profesional = Profesional::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Benítez', 'nombres' => 'Rosa'])->id, 'matricula' => 'MP-1']);
    $this->otroProfesional = Profesional::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Insfrán', 'nombres' => 'Laura'])->id, 'matricula' => 'MP-2']);
    $this->paciente = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Carmen'])->id, 'nro_ficha' => 'FP-0000001']);
    $this->disponibilidad = disponibilidad();
});

afterEach(fn () => Carbon::setTestNow());

function disponibilidad(array $cambios = []): Disponibilidad
{
    return Disponibilidad::create([
        'profesional_id' => test()->profesional->id, 'consultorio_id' => test()->consultorio->id, 'dia_semana' => 'MAR',
        'hora_desde' => '08:00', 'hora_hasta' => '10:00', 'duracion_turno_minutos' => 30, 'vigencia_desde' => '2026-01-01',
        ...$cambios,
    ]);
}

function turno(array $cambios = []): Turno
{
    return Turno::create([
        'paciente_id' => test()->paciente->id, 'profesional_id' => test()->profesional->id, 'consultorio_id' => test()->consultorio->id,
        'fecha' => '2026-10-06', 'hora_inicio' => '08:00', 'hora_fin' => '08:30', ...$cambios,
    ]);
}

function horarios(string $fecha = '2026-10-06', ?Profesional $profesional = null): array
{
    return Agenda::horariosDisponibles($profesional ?? test()->profesional, Carbon::parse($fecha))->pluck('hora_inicio')->all();
}

describe('cálculo de horarios disponibles', function () {
    test('divide la franja en turnos de la duración indicada', function () {
        expect(horarios())->toBe(['08:00', '08:30', '09:00', '09:30']);
    });

    test('el último bloque solo si entra completo', function () {
        $this->disponibilidad->update(['hora_hasta' => '09:45']);

        expect(horarios())->toBe(['08:00', '08:30', '09:00']);
    });

    test('descarta los que se pisan con un turno del profesional, salvo los cancelados', function () {
        turno(['hora_inicio' => '08:30', 'hora_fin' => '09:00']);
        turno(['hora_inicio' => '09:00', 'hora_fin' => '09:30', 'estado_id' => Estado::idDe(Estado::CANCELADO)]);

        expect(horarios())->toBe(['08:00', '09:00', '09:30']);
    });

    test('un turno que cruza dos bloques los descarta a los dos', function () {
        turno(['hora_inicio' => '08:15', 'hora_fin' => '08:45']);

        expect(horarios())->toBe(['09:00', '09:30']);
    });

    test('descarta los horarios en que el consultorio está ocupado por otro profesional', function () {
        turno(['profesional_id' => $this->otroProfesional->id, 'hora_inicio' => '09:00', 'hora_fin' => '09:30']);

        expect(horarios())->toBe(['08:00', '08:30', '09:30']);
    });

    test('sin disponibilidad para ese día de la semana no hay horarios', function () {
        expect(horarios('2026-10-07'))->toBe([]); // miércoles
    });

    test('fuera de la vigencia no hay horarios', function () {
        $this->disponibilidad->update(['vigencia_hasta' => '2026-09-30']);
        expect(horarios())->toBe([]);

        $this->disponibilidad->update(['vigencia_desde' => '2026-10-07', 'vigencia_hasta' => null]);
        expect(horarios())->toBe([]);

        // vigencia_hasta es inclusiva.
        $this->disponibilidad->update(['vigencia_desde' => '2026-01-01', 'vigencia_hasta' => '2026-10-06']);
        expect(horarios())->toBe(['08:00', '08:30', '09:00', '09:30']);
    });

    test('una disponibilidad o un consultorio inactivos no ofrecen horarios', function () {
        $this->disponibilidad->desactivar();
        expect(horarios())->toBe([]);

        $this->disponibilidad->update(['estado_id' => Estado::idDe(Estado::ACTIVO)]);
        $this->consultorio->desactivar();
        expect(horarios())->toBe([]);
    });

    test('no hay horarios en el pasado; hoy, solo los que todavía no empezaron', function () {
        expect(horarios('2026-09-29'))->toBe([]); // martes anterior

        Carbon::setTestNow(Carbon::parse('2026-10-06 11:40:00', 'UTC')); // martes 08:40 en Paraguay
        expect(horarios())->toBe(['09:00', '09:30']);
    });

    test('varias disponibilidades del mismo día se combinan, ordenadas', function () {
        disponibilidad(['consultorio_id' => $this->consultorio2->id, 'hora_desde' => '14:00', 'hora_hasta' => '15:00', 'duracion_turno_minutos' => 20]);

        expect(horarios())->toBe(['08:00', '08:30', '09:00', '09:30', '14:00', '14:20', '14:40']);
    });

    test('el endpoint devuelve los horarios con su consultorio, y exige permiso de crear turnos', function () {
        $this->getJson(route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026']))
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0', ['hora_inicio' => '08:00', 'hora_fin' => '08:30', 'consultorio' => 'Consultorio 1 — Plenitud Mujer']);

        // Sin datos válidos: lista vacía.
        $this->getJson(route('admin.turnos.horarios-disponibles'))->assertOk()->assertExactJson([]);
        $this->getJson(route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '2026-10-06']))
            ->assertOk()->assertExactJson([]);

        $this->actingAs(User::factory()->conPermisos(['TURNOS' => ['VER']])->create());
        $this->getJson(route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026']))
            ->assertForbidden();
    });
});

describe('alta de turno', function () {
    function datosTurno(array $cambios = []): array
    {
        return ['paciente_id' => test()->paciente->id, 'profesional_id' => test()->profesional->id, 'fecha' => '06/10/2026', 'hora_inicio' => '08:30', ...$cambios];
    }

    test('el formulario trae los buscadores, la fecha de hoy y la carga de horarios', function () {
        $html = $this->get(route('admin.turnos.create'))->assertOk()
            ->assertSee('name="paciente_id"', false)->assertSee('name="profesional_id"', false)
            ->assertSee('value="05/10/2026"', false)
            ->assertSee('Elija el profesional y la fecha para ver los horarios libres.')
            ->getContent();

        expect($html)->toContain('x-data="altaTurno(')->toContain('x-on:elegido="alElegir($event)"');
    });

    test('se da el turno: PENDIENTE, con el consultorio y la hora de fin del horario elegido', function () {
        $origen = OrigenTurno::firstOrCreate(['codigo' => 'TELEFONICO'], ['nombre' => 'Telefónico']);

        $this->post(route('admin.turnos.store'), datosTurno(['origen_turno_id' => $origen->id, 'observaciones' => 'Primera consulta']))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.turnos.index'))
            ->assertSessionHas('status', 'Turno dado para el 06/10/2026 a las 08:30 (Consultorio 1 — Plenitud Mujer).');

        expect(Turno::sole())
            ->estado->codigo->toBe('PENDIENTE')
            ->consultorio_id->toBe($this->consultorio->id)
            ->origen_turno_id->toBe($origen->id)
            ->observaciones->toBe('Primera consulta')
            ->and(substr(Turno::sole()->hora_fin, 0, 5))->toBe('09:00')
            ->and(horarios())->toBe(['08:00', '09:00', '09:30']);
    });

    test('rechaza un horario que no está libre o que no sale de la disponibilidad', function (string $hora) {
        turno(['hora_inicio' => '08:30', 'hora_fin' => '09:00']);

        $this->post(route('admin.turnos.store'), datosTurno(['hora_inicio' => $hora]))->assertSessionHasErrors('hora_inicio');
        expect(Turno::count())->toBe(1);
    })->with(['ocupado' => '08:30', 'fuera de la franja' => '11:00', 'no alineado' => '08:10']);

    test('no se dan turnos en fechas pasadas', function () {
        $this->post(route('admin.turnos.store'), datosTurno(['fecha' => '29/09/2026']))
            ->assertSessionHasErrors(['fecha' => 'No se pueden dar turnos en fechas pasadas.']);
    });

    test('paciente y profesional tienen que estar activos', function () {
        $this->paciente->desactivar();
        $this->post(route('admin.turnos.store'), datosTurno())->assertSessionHasErrors('paciente_id');

        $this->paciente->update(['estado_id' => Estado::idDe(Estado::ACTIVO)]);
        $this->profesional->desactivar();
        $this->post(route('admin.turnos.store'), datosTurno())->assertSessionHasErrors('profesional_id');
        expect(Turno::count())->toBe(0);
    });

    test('los buscadores devuelven solo pacientes y profesionales activos (por nombre, ficha o matrícula)', function () {
        $inactivo = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Inactiva'])->id, 'nro_ficha' => 'FP-0000002']);
        $inactivo->desactivar();

        $this->getJson(route('admin.turnos.pacientes', ['q' => 'Duarte']))->assertOk()
            ->assertExactJson([['id' => $this->paciente->id, 'texto' => "Duarte, Carmen — CI {$this->paciente->persona->nro_documento} · Ficha FP-0000001"]]);
        expect($this->getJson(route('admin.turnos.pacientes', ['q' => 'FP-0000001']))->json('*.id'))->toBe([$this->paciente->id])
            ->and($this->getJson(route('admin.turnos.profesionales', ['q' => 'MP-2']))->json('*.id'))->toBe([$this->otroProfesional->id]);

        // Una persona sin el rol no aparece.
        Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'SinRol']);
        expect($this->getJson(route('admin.turnos.pacientes', ['q' => 'Duarte']))->json())->toHaveCount(1);
    });
});

describe('cambios de estado', function () {
    function accion(Turno $turno, string $accion)
    {
        return test()->from(route('admin.turnos.index'))->patch(route('admin.turnos.estado', $turno), ['accion' => $accion]);
    }

    test('transiciones válidas', function (string $desde, string $accion, string $hasta) {
        $turno = turno(['estado_id' => Estado::idDe($desde)]);

        accion($turno, $accion)->assertRedirect(route('admin.turnos.index'))->assertSessionHas('status');
        expect($turno->fresh()->estado->codigo)->toBe($hasta);
    })->with([
        ['PENDIENTE', 'confirmar', 'CONFIRMADO'],
        ['PENDIENTE', 'cancelar', 'CANCELADO'],
        ['CONFIRMADO', 'ausente', 'AUSENTE'],
        ['CONFIRMADO', 'cancelar', 'CANCELADO'],
    ]);

    test('transiciones inválidas: no cambia nada y avisa', function (string $desde, string $accion) {
        $turno = turno(['estado_id' => Estado::idDe($desde)]);

        accion($turno, $accion)->assertSessionHas('error');
        expect($turno->fresh()->estado->codigo)->toBe($desde);
    })->with([
        ['PENDIENTE', 'ausente'],
        ['CONFIRMADO', 'confirmar'],
        ['ATENDIDO', 'cancelar'],
        ['CANCELADO', 'confirmar'],
    ]);

    test('"atender" ya no es un cambio de estado: ATENDIDO solo se alcanza guardando la consulta', function (string $desde) {
        $turno = turno(['estado_id' => Estado::idDe($desde)]);

        accion($turno, 'atender')->assertSessionHasErrors('accion');
        expect($turno->fresh()->estado->codigo)->toBe($desde);
    })->with(['PENDIENTE', 'CONFIRMADO', 'AUSENTE']);

    test('una acción desconocida se rechaza', function () {
        accion(turno(), 'borrar')->assertSessionHasErrors('accion');
    });

    test('cambiar el estado exige permiso EDITAR sobre Turnos', function () {
        $turno = turno();
        $this->actingAs(User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR']])->create());

        accion($turno, 'confirmar')->assertForbidden();
        $this->get(route('admin.turnos.index'))->assertOk()->assertDontSee('name="accion"', false);
        expect($turno->fresh()->estado->codigo)->toBe('PENDIENTE');
    });

    test('el listado ofrece solo las transiciones del estado actual y busca en vivo', function () {
        turno(); // PENDIENTE
        turno(['hora_inicio' => '09:00', 'hora_fin' => '09:30', 'estado_id' => Estado::idDe(Estado::ATENDIDO)]);

        $html = $this->get(route('admin.turnos.index'))->assertOk()
            ->assertSeeInOrder(['06/10/2026', '08:00 – 08:30', 'Duarte, Carmen', 'Benítez, Rosa', 'Consultorio 1 — Plenitud Mujer'])
            ->getContent();
        expect(substr_count($html, 'name="accion" value="confirmar"'))->toBe(1)
            ->and(substr_count($html, 'name="accion" value="cancelar"'))->toBe(1)
            ->and($html)->not->toContain('value="atender"');

        $this->get(route('admin.turnos.index', ['q' => 'Duarte']))->assertSee('Duarte, Carmen');
        $this->get(route('admin.turnos.index', ['q' => 'Insfrán']))->assertDontSee('Duarte, Carmen');
        $this->get(route('admin.turnos.index', ['q' => '06/10/2026']))->assertSee('Duarte, Carmen');
        $this->get(route('admin.turnos.index', ['q' => '07/10/2026']))->assertDontSee('Duarte, Carmen');
    });
});

describe('CRUD de disponibilidades', function () {
    function datosDisponibilidad(array $cambios = []): array
    {
        return [
            'profesional_id' => test()->otroProfesional->id, 'consultorio_id' => test()->consultorio2->id, 'dia_semana' => 'JUE',
            'hora_desde' => '14:00', 'hora_hasta' => '18:00', 'duracion_turno_minutos' => 20, 'vigencia_desde' => '01/10/2026', ...$cambios,
        ];
    }

    test('se crea con fechas dd/mm/aaaa y entra ACTIVA', function () {
        $this->post(route('admin.disponibilidades.store'), datosDisponibilidad(['vigencia_hasta' => '31/12/2026']))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.disponibilidades.index'));

        expect(Disponibilidad::where('profesional_id', $this->otroProfesional->id)->sole())
            ->estado->codigo->toBe('ACTIVO')
            ->vigencia_desde->format('Y-m-d')->toBe('2026-10-01')
            ->vigencia_hasta->format('Y-m-d')->toBe('2026-12-31');
    });

    test('valida horario, duración y vigencia', function (array $cambios, string $campo) {
        $this->post(route('admin.disponibilidades.store'), datosDisponibilidad($cambios))->assertSessionHasErrors($campo);
    })->with([
        'hasta igual a desde' => [['hora_hasta' => '14:00'], 'hora_hasta'],
        'hasta antes que desde' => [['hora_hasta' => '13:00'], 'hora_hasta'],
        'hora mal escrita' => [['hora_desde' => '2pm'], 'hora_desde'],
        'la franja no alcanza' => [['hora_hasta' => '14:15'], 'duracion_turno_minutos'],
        'vigencia hasta anterior' => [['vigencia_hasta' => '01/09/2026'], 'vigencia_hasta'],
        'vigencia hasta igual' => [['vigencia_hasta' => '01/10/2026'], 'vigencia_hasta'],
        'día inválido' => [['dia_semana' => 'XYZ'], 'dia_semana'],
    ]);

    test('no se superpone con otra activa del mismo profesional ni del mismo consultorio', function () {
        // Mismo profesional (la base: martes 08-10), otro consultorio.
        $this->post(route('admin.disponibilidades.store'), datosDisponibilidad([
            'profesional_id' => $this->profesional->id, 'dia_semana' => 'MAR', 'hora_desde' => '09:00', 'hora_hasta' => '11:00',
        ]))->assertSessionHasErrors(['hora_desde' => 'Se superpone con otra disponibilidad activa de el mismo profesional: Martes de 08:00 a 10:00, vigente desde el 01/01/2026.']);

        // Mismo consultorio, otro profesional.
        $this->post(route('admin.disponibilidades.store'), datosDisponibilidad([
            'consultorio_id' => $this->consultorio->id, 'dia_semana' => 'MAR', 'hora_desde' => '07:00', 'hora_hasta' => '08:30',
        ]))->assertSessionHasErrors('hora_desde');

        // Mismo día y horario pero con vigencias que no se cruzan: se permite.
        $this->disponibilidad->update(['vigencia_hasta' => '2026-09-30']);
        $this->post(route('admin.disponibilidades.store'), datosDisponibilidad([
            'profesional_id' => $this->profesional->id, 'dia_semana' => 'MAR', 'hora_desde' => '08:00', 'hora_hasta' => '10:00',
        ]))->assertSessionHasNoErrors();
    });

    test('al editar no choca consigo misma; desactivar la saca de la agenda', function () {
        $this->put(route('admin.disponibilidades.update', $this->disponibilidad), datosDisponibilidad([
            'profesional_id' => $this->profesional->id, 'consultorio_id' => $this->consultorio->id, 'dia_semana' => 'MAR',
            'hora_desde' => '08:00', 'hora_hasta' => '11:00', 'duracion_turno_minutos' => 30, 'estado_id' => Estado::idDe(Estado::ACTIVO),
        ]))->assertSessionHasNoErrors();
        expect(horarios())->toHaveCount(6);

        $this->patch(route('admin.disponibilidades.desactivar', $this->disponibilidad))->assertRedirect();
        expect($this->disponibilidad->fresh()->estado->codigo)->toBe('INACTIVO')->and(horarios())->toBe([]);
    });

    test('el listado muestra día, horario y vigencia en dd/mm/aaaa', function () {
        $this->get(route('admin.disponibilidades.index'))->assertOk()
            ->assertSeeInOrder(['Benítez, Rosa', 'Consultorio 1 — Plenitud Mujer', 'Martes', '08:00 – 10:00', '30 min', '01/01/2026 – sin fin']);
        $this->get(route('admin.disponibilidades.create'))->assertOk()->assertSee('value="05/10/2026"', false);
    });

    test('el buscador de profesionales solo devuelve activos', function () {
        $this->otroProfesional->desactivar();

        expect($this->getJson(route('admin.disponibilidades.profesionales', ['q' => 'MP']))->json('*.id'))->toBe([$this->profesional->id]);
    });
});

describe('catálogos de la agenda', function () {
    test('orígenes de turno: CRUD con código único', function () {
        $this->post(route('admin.origenes-turno.store'), ['codigo' => 'WHATSAPP', 'nombre' => 'WhatsApp'])->assertSessionHasNoErrors();
        $origen = OrigenTurno::where('codigo', 'WHATSAPP')->sole();
        expect($origen->estado->codigo)->toBe('ACTIVO');

        $this->post(route('admin.origenes-turno.store'), ['codigo' => 'WHATSAPP', 'nombre' => 'Otro'])->assertSessionHasErrors('codigo');
        $this->put(route('admin.origenes-turno.update', $origen), ['codigo' => 'WHATSAPP', 'nombre' => 'Mensaje de WhatsApp', 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHasNoErrors();
        $this->patch(route('admin.origenes-turno.desactivar', $origen));

        expect($origen->fresh())->nombre->toBe('Mensaje de WhatsApp')->estado->codigo->toBe('INACTIVO');
        $this->get(route('admin.origenes-turno.index', ['q' => 'whats']))->assertOk()->assertSee('Mensaje de WhatsApp');
    });

    test('el seeder de datos reales carga los 4 orígenes, activos', function () {
        $this->seed(DatosRealesClinicaSeeder::class);

        expect(OrigenTurno::orderBy('codigo')->pluck('codigo')->all())->toBe(['APP', 'PRESENCIAL', 'TELEFONICO', 'WEB'])
            ->and(OrigenTurno::all()->every->estaActivo())->toBeTrue();
    });

    test('consultorios: el nombre es único dentro de la sucursal, no entre sucursales', function () {
        $otra = Sucursal::create(['nombre' => 'Anexo', 'direccion' => 'X', 'telefono' => '1']);

        $this->post(route('admin.consultorios.store'), ['sucursal_id' => $this->sucursal->id, 'nombre' => 'Consultorio 1'])
            ->assertSessionHasErrors(['nombre' => 'Esa sucursal ya tiene un consultorio con ese nombre.']);
        $this->post(route('admin.consultorios.store'), ['sucursal_id' => $otra->id, 'nombre' => 'Consultorio 1'])->assertSessionHasNoErrors();

        $this->get(route('admin.consultorios.index', ['q' => 'anexo']))->assertOk()->assertSee('Anexo')->assertDontSee('Consultorio 2'); // el de Plenitud Mujer no aparece
        $this->getJson(route('verificar-unico', ['campo' => 'consultorio.nombre', 'valor' => 'Consultorio 2', 'sucursal_id' => $this->sucursal->id]))
            ->assertJson(['disponible' => false]);
    });

    test('los 4 módulos nuevos aparecen en el menú solo con permiso', function () {
        $this->get('/dashboard')->assertSee('Turnos')->assertSee('Disponibilidades')->assertSee('Consultorios')->assertSee('Orígenes de turno');

        $this->actingAs(User::factory()->conPermisos(['TURNOS' => ['VER']])->create());
        $this->get('/dashboard')->assertSee(route('admin.turnos.index'))->assertDontSee(route('admin.disponibilidades.index'))
            ->assertDontSee(route('admin.consultorios.index'));
    });
});
