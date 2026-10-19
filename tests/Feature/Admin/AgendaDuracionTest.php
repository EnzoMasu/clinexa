<?php

use App\Models\Consultorio;
use App\Models\Disponibilidad;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use App\Support\Agenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Js;

/*
 * Turnos con horario que duran lo de su procedimiento (docs/agenda-urgencias.md, "Duración por procedimiento").
 * "Ahora" fijo: lunes 05/10/2026 09:00 en Paraguay. Disponibilidad: martes 06/10/2026 de 08:00 a 12:00, grilla de 30 min.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
    $this->actingAs(User::factory()->administrador()->create());

    $sucursal = Sucursal::create(['nombre' => 'Sede Inventada', 'direccion' => 'Calle 1', 'telefono' => '0900']);
    $this->consultorio = Consultorio::create(['sucursal_id' => $sucursal->id, 'nombre' => 'Consultorio 1']);
    $this->profesional = Profesional::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Prueba', 'nombres' => 'Ana'])->id, 'matricula' => 'MP-9']);
    $this->otroProfesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-8']);
    $this->paciente = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Ficticia', 'nombres' => 'Eva'])->id, 'nro_ficha' => 'FP-0000901']);
    $this->disponibilidad = Disponibilidad::create([
        'profesional_id' => $this->profesional->id, 'consultorio_id' => $this->consultorio->id, 'dia_semana' => 'MAR',
        'hora_desde' => '08:00', 'hora_hasta' => '12:00', 'duracion_turno_minutos' => 30, 'vigencia_desde' => '2026-01-01',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function agendaDuracionProcedimiento(int $minutos): Procedimiento
{
    return Procedimiento::create(['codigo' => "EST-{$minutos}", 'nombre' => "Estudio de {$minutos} min", 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => $minutos]);
}

function agendaDuracionHorarios(?int $duracion = null, ?Profesional $profesional = null): array
{
    return Agenda::horariosDisponibles($profesional ?? test()->profesional, Carbon::parse('2026-10-06'), $duracion)
        ->map(fn (array $bloque) => "{$bloque['hora_inicio']}-{$bloque['hora_fin']}")->all();
}

function agendaDuracionTurno(array $cambios = []): Turno
{
    return Turno::create([
        'paciente_id' => test()->paciente->id, 'profesional_id' => test()->profesional->id, 'consultorio_id' => test()->consultorio->id,
        'fecha' => '2026-10-06', 'hora_inicio' => '08:00', 'hora_fin' => '10:00', ...$cambios,
    ]);
}

function agendaDuracionAlta(array $cambios = []): array
{
    return ['paciente_id' => test()->paciente->id, 'profesional_id' => test()->profesional->id, 'fecha' => '06/10/2026', 'hora_inicio' => '08:00', ...$cambios];
}

describe('horarios libres para una duración', function () {
    test('los inicios siguen la grilla y cada horario dura lo pedido, entrando completo en la franja', function (int $minutos, array $esperados) {
        expect(agendaDuracionHorarios($minutos))->toBe($esperados);
    })->with([
        '30 min' => [30, ['08:00-08:30', '08:30-09:00', '09:00-09:30', '09:30-10:00', '10:00-10:30', '10:30-11:00', '11:00-11:30', '11:30-12:00']],
        '90 min' => [90, ['08:00-09:30', '08:30-10:00', '09:00-10:30', '09:30-11:00', '10:00-11:30', '10:30-12:00']],
        '120 min' => [120, ['08:00-10:00', '08:30-10:30', '09:00-11:00', '09:30-11:30', '10:00-12:00']],
    ]);

    test('un inicio que no entra completo en la franja no se ofrece', function () {
        $this->disponibilidad->update(['hora_hasta' => '10:00']);

        expect(agendaDuracionHorarios(120))->toBe(['08:00-10:00'])
            ->and(agendaDuracionHorarios(150))->toBe([]);
    });

    test('un turno largo del profesional bloquea todos los horarios que pisa', function () {
        agendaDuracionTurno(); // 08:00 a 10:00

        expect(agendaDuracionHorarios())->toBe(['10:00-10:30', '10:30-11:00', '11:00-11:30', '11:30-12:00'])
            ->and(agendaDuracionHorarios(60))->toBe(['10:00-11:00', '10:30-11:30', '11:00-12:00']);
    });

    test('un turno largo de otro profesional en el mismo consultorio también los bloquea', function () {
        agendaDuracionTurno(['profesional_id' => $this->otroProfesional->id, 'hora_inicio' => '10:00', 'hora_fin' => '12:00']);

        expect(agendaDuracionHorarios())->toBe(['08:00-08:30', '08:30-09:00', '09:00-09:30', '09:30-10:00'])
            ->and(agendaDuracionHorarios(90))->toBe(['08:00-09:30', '08:30-10:00']);
    });

    test('el endpoint usa la duración del procedimiento o la ajustada; sin procedimiento, la grilla de siempre', function () {
        $procedimiento = agendaDuracionProcedimiento(120);
        $url = fn (array $parametros) => route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', ...$parametros]);

        $this->getJson($url(['procedimiento_id' => $procedimiento->id]))->assertOk()->assertJsonCount(5)
            ->assertJsonPath('0.hora_fin', '10:00');
        $this->getJson($url(['procedimiento_id' => $procedimiento->id, 'duracion_minutos' => 90]))->assertOk()->assertJsonCount(6)
            ->assertJsonPath('0.hora_fin', '09:30');
        $this->getJson($url(['procedimiento_id' => $procedimiento->id, 'duracion_minutos' => 481]))->assertOk()->assertExactJson([]);
        // Sin procedimiento la duración pedida no cuenta: bloques de la disponibilidad.
        $this->getJson($url(['duracion_minutos' => 120]))->assertOk()->assertJsonCount(8)->assertJsonPath('0.hora_fin', '08:30');
    });
});

describe('alta de un turno con procedimiento', function () {
    test('por defecto dura lo estimado del procedimiento', function () {
        $procedimiento = agendaDuracionProcedimiento(120);

        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id]))->assertSessionHasNoErrors();

        expect(substr(Turno::sole()->hora_fin, 0, 5))->toBe('10:00')
            ->and(agendaDuracionHorarios())->toBe(['10:00-10:30', '10:30-11:00', '11:00-11:30', '11:30-12:00']);
    });

    test('la duración ajustada se respeta dentro de 5 a 480 minutos', function () {
        $procedimiento = agendaDuracionProcedimiento(30);

        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id, 'duracion_minutos' => 4]))
            ->assertSessionHasErrors(['duracion_minutos' => 'La duración debe estar entre 5 y 480 minutos.']);
        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id, 'duracion_minutos' => 481]))
            ->assertSessionHasErrors('duracion_minutos');
        expect(Turno::count())->toBe(0);

        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id, 'duracion_minutos' => 90]))->assertSessionHasNoErrors();
        expect(substr(Turno::sole()->hora_fin, 0, 5))->toBe('09:30');
    });

    test('una estimada fuera de rango pide ajustarla', function () {
        $procedimiento = agendaDuracionProcedimiento(600);

        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id]))
            ->assertSessionHasErrors(['duracion_minutos' => 'La duración debe estar entre 5 y 480 minutos.']);
        expect(Turno::count())->toBe(0);
    });

    test('si no entra completo en la franja o pisa otro turno, se rechaza', function () {
        $procedimiento = agendaDuracionProcedimiento(120);

        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id, 'hora_inicio' => '10:30']))
            ->assertSessionHasErrors(['hora_inicio' => 'Ese horario no está disponible para un turno de 120 minutos: tiene que entrar completo en la franja del profesional y no pisarse con otros turnos. Elija uno de los horarios libres.']);

        agendaDuracionTurno(['profesional_id' => $this->otroProfesional->id, 'paciente_id' => Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000902'])->id,
            'hora_inicio' => '09:00', 'hora_fin' => '09:30']);
        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id, 'hora_inicio' => '08:00']))
            ->assertSessionHasErrors('hora_inicio');
        expect(Turno::count())->toBe(1);
    });

    test('sin procedimiento no cambia nada: el bloque de la disponibilidad, aunque se mande una duración', function () {
        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['duracion_minutos' => 120]))->assertSessionHasNoErrors();

        expect(substr(Turno::sole()->hora_fin, 0, 5))->toBe('08:30');
    });

    test('no se agenda en el pasado, tampoco con procedimiento', function () {
        $procedimiento = agendaDuracionProcedimiento(120);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:10:00', 'UTC')); // martes 09:10 en Paraguay

        expect(agendaDuracionHorarios(120))->toBe(['09:30-11:30', '10:00-12:00']);
        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id, 'hora_inicio' => '09:00']))
            ->assertSessionHasErrors('hora_inicio');
    });

    test('el formulario trae la duración de cada procedimiento', function () {
        $procedimiento = agendaDuracionProcedimiento(120);

        $this->get(route('admin.turnos.create'))->assertOk()
            ->assertSee('name="duracion_minutos"', false)
            ->assertSee('duraciones: '.Js::from([$procedimiento->id => 120]), false);
    });
});

test('la Agenda general muestra el turno largo en todas las filas que ocupa', function () {
    $turno = agendaDuracionTurno(); // 08:00 a 10:00

    $html = $this->get(route('admin.agenda-general.index', ['fecha' => '06/10/2026']))->assertOk()->getContent();

    expect(substr_count($html, 'data-continua="'.$turno->id.'"'))->toBe(3) // 08:30, 09:00 y 09:30
        ->and(substr_count($html, 'data-turno="'.$turno->id.'"'))->toBe(1)
        ->and($html)->toContain('08:00 – 10:00')->toContain('(hasta 10:00)');
});

describe('avisos de duración', function () {
    test('el aviso de éxito dice "Turno largo" solo si dura más que el bloque normal', function () {
        $largo = agendaDuracionProcedimiento(120);
        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $largo->id]))
            ->assertSessionHas('status', 'Turno dado para el 06/10/2026 a las 08:00 (Consultorio 1 — Sede Inventada). Turno largo: ocupa de 08:00 a 10:00 (120 min).');

        $normal = agendaDuracionProcedimiento(30);
        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $normal->id, 'hora_inicio' => '10:00', 'agendar_igual' => 1]))
            ->assertSessionHas('status', 'Turno dado para el 06/10/2026 a las 10:00 (Consultorio 1 — Sede Inventada).');
    });

    test('el formulario avisa cuánto ocupa el estudio y muestra cada horario de inicio a fin', function () {
        $procedimiento = agendaDuracionProcedimiento(120);

        // El endpoint trae el bloque normal: con él el formulario sabe que 120 min lo pasan.
        $this->getJson(route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'procedimiento_id' => $procedimiento->id]))
            ->assertJsonPath('0', ['hora_inicio' => '08:00', 'hora_fin' => '10:00', 'bloque_minutos' => 30, 'consultorio' => 'Consultorio 1 — Sede Inventada']);

        $this->get(route('admin.turnos.create'))->assertOk()
            ->assertSee('x-show="duracionLarga && ! cargando"', false)
            ->assertSee('Este estudio ocupa ${duracion} min: se ofrecen solo horarios con ese tiempo libre.', false)
            ->assertSee('`${horario.hora_inicio} a ${horario.hora_fin}`', false);
    });

    test('sin horarios con esa duración, lo explica en lugar de la lista vacía', function () {
        $procedimiento = agendaDuracionProcedimiento(120);
        agendaDuracionTurno(['hora_inicio' => '09:30', 'hora_fin' => '10:30']); // ningún tramo de 120 min queda libre

        $this->getJson(route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'procedimiento_id' => $procedimiento->id]))
            ->assertOk()->assertExactJson([]);

        $this->get(route('admin.turnos.create'))->assertOk()
            ->assertSee('No hay horarios con ${duracion} min libres seguidos ese día.', false)
            ->assertSee('horarios.length === 0 && duracionLarga', false);
    });
});

describe('los avisos de turno largo son genéricos', function () {
    test('salen para cualquier procedimiento más largo que el bloque, y no para uno igual al bloque', function (int $minutos, bool $largo) {
        $procedimiento = agendaDuracionProcedimiento($minutos);
        $fin = sprintf('%02d:%02d', intdiv(8 * 60 + $minutos, 60), $minutos % 60);

        // El bloque normal del día viaja en la cabecera: el formulario avisa si la duración lo pasa (duracionLarga).
        $this->getJson(route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'procedimiento_id' => $procedimiento->id]))
            ->assertOk()->assertHeader('X-Bloque-Minutos', '30')->assertJsonPath('0.hora_fin', $fin);

        $esperado = 'Turno dado para el 06/10/2026 a las 08:00 (Consultorio 1 — Sede Inventada).'
            .($largo ? " Turno largo: ocupa de 08:00 a {$fin} ({$minutos} min)." : '');
        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id]))->assertSessionHas('status', $esperado);
    })->with([
        'procedimiento de 90 min' => [90, true],
        'procedimiento de 40 min' => [40, true],
        'procedimiento igual al bloque (30 min)' => [30, false],
    ]);

    test('la cabecera del bloque llega aunque no quede ningún horario con esa duración', function () {
        $procedimiento = agendaDuracionProcedimiento(240);
        agendaDuracionTurno(['hora_inicio' => '09:30', 'hora_fin' => '10:00']);

        $this->getJson(route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'procedimiento_id' => $procedimiento->id]))
            ->assertOk()->assertExactJson([])->assertHeader('X-Bloque-Minutos', '30');
    });

    test('un procedimiento sin duración cargada se comporta como un turno normal, sin error', function () {
        $procedimiento = agendaDuracionProcedimiento(0);

        $this->getJson(route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'procedimiento_id' => $procedimiento->id]))
            ->assertOk()->assertJsonCount(8)->assertJsonPath('0.hora_fin', '08:30');
        $this->post(route('admin.turnos.store'), agendaDuracionAlta(['procedimiento_id' => $procedimiento->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Turno dado para el 06/10/2026 a las 08:00 (Consultorio 1 — Sede Inventada).');

        expect(Turno::sole())->procedimiento_id->toBe($procedimiento->id)
            ->and(substr(Turno::sole()->hora_fin, 0, 5))->toBe('08:30');
    });
});
