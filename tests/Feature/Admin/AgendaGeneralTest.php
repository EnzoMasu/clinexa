<?php

/*
 * Agenda general (grilla de recepción): permisos, filtros, privacidad clínica, rendimiento y escapado. "Ahora": martes
 * 06/10/2026 09:00 en Paraguay (epEscenario); la Dra. Benítez atiende los martes de 08:00 a 12:00 en bloques de 30 minutos.
 * Datos inventados. Funciones con el prefijo "ag".
 */

use App\Enums\TipoTurno;
use App\Models\Disponibilidad;
use App\Models\Especialidad;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    epEscenario();
    $this->ajax = ['X-Requested-With' => 'XMLHttpRequest'];
});

afterEach(fn () => Carbon::setTestNow());

/** Turno con horario de la Dra. Benítez (o del profesional indicado) hoy, directo en la base. */
function agTurno(string $hora, array $cambios = [], ?Paciente $paciente = null): Turno
{
    $fin = Carbon::parse($hora)->addMinutes(30)->format('H:i');

    return Turno::create([
        'tipo' => TipoTurno::NORMAL, 'paciente_id' => ($paciente ?? test()->paciente)->id, 'profesional_id' => test()->profesional->id,
        'consultorio_id' => test()->consultorio->id, 'fecha' => '2026-10-06', 'hora_inicio' => $hora, 'hora_fin' => $fin,
        'estado_id' => Estado::idDe(Estado::PENDIENTE), ...$cambios,
    ]);
}

/** Otro profesional activo, con disponibilidad los martes en su propio consultorio. */
function agProfesional(string $apellidos, int $n, ?Especialidad $especialidad = null): Profesional
{
    $profesional = Profesional::create(['persona_id' => Persona::factory()->create(['apellidos' => $apellidos, 'nombres' => 'Prueba'])->id, 'matricula' => "AG-{$n}"]);
    $consultorio = test()->consultorio->replicate()->fill(['nombre' => "Consultorio AG {$n}", 'codigo' => "AG{$n}"]);
    $consultorio->save();
    Disponibilidad::create([
        'profesional_id' => $profesional->id, 'consultorio_id' => $consultorio->id, 'dia_semana' => 'MAR',
        'hora_desde' => '08:00', 'hora_hasta' => '12:00', 'duracion_turno_minutos' => 30, 'vigencia_desde' => '2026-01-01',
    ]);
    $especialidad?->profesionales()->attach($profesional->id, ['fecha_desde' => '2020-01-01', 'activa' => true]);

    return $profesional;
}

function agEspecialidad(string $nombre): Especialidad
{
    return Especialidad::create(['nombre' => $nombre, 'estado_id' => Estado::idDe(Estado::ACTIVO)]);
}

test('permisos: sin VER sobre TURNOS no entra; con VER ve la grilla; los botones salen de los permisos que ya existen', function () {
    $sin = User::factory()->conPermisos(['PACIENTES' => ['VER']])->create();
    $this->actingAs($sin)->get(route('admin.agenda-general.index'))->assertForbidden();

    $soloVer = User::factory()->conPermisos(['TURNOS' => ['VER']])->create();
    agTurno('10:00');
    $html = $this->actingAs($soloVer)->get(route('admin.agenda-general.index'))->assertOk()->assertSee('Agenda general')->getContent();
    expect($html)->toContain('Duarte')->not->toContain('Libre · dar turno')->not->toContain(route('admin.turnos.estado', Turno::first()))
        ->and($html)->toContain('data-libre'); // libre, pero sin enlace al alta

    $html = $this->actingAs($this->recepcion)->get(route('admin.agenda-general.index'))->assertOk()->getContent();
    expect($html)->toContain('Libre · dar turno')->toContain(route('admin.turnos.estado', Turno::first()))
        ->toContain(e(route('admin.turnos.create', ['profesional' => $this->profesional->id, 'fecha' => '06/10/2026', 'hora' => '09:30'])));
    $this->get(route('admin.turnos.create', ['profesional' => $this->profesional->id, 'fecha' => '06/10/2026', 'hora' => '09:30']))
        ->assertOk()->assertSee('06/10/2026')->assertSee('value="09:30"', false);
});

test('la grilla: los pasados no se ofrecen, el ocupado muestra paciente y estado, el cancelado libera el horario', function () {
    agTurno('10:00', ['estado_id' => Estado::idDe(Estado::CONFIRMADO)]);
    agTurno('11:00', ['estado_id' => Estado::idDe(Estado::CANCELADO)]);
    $html = $this->actingAs($this->recepcion)->get(route('admin.agenda-general.index'))->assertOk()->getContent();

    expect($html)->toContain('Duarte')->toContain('Confirmado')->toContain('Sin triaje')
        ->and($html)->toContain('data-hora="10:00"')
        ->and($html)->not->toContain('data-hora="08:00"') // ya pasó
        ->and($html)->toContain(e(route('admin.turnos.create', ['profesional' => $this->profesional->id, 'fecha' => '06/10/2026', 'hora' => '11:00'])));
});

test('filtros: fecha, profesional, especialidad, estado, tipo y búsqueda por paciente o ficha', function () {
    $ginecologia = agEspecialidad('Ginecología');
    $otro = agProfesional('Zapata', 9, agEspecialidad('Cardiología'));
    $this->profesional->especialidades()->attach($ginecologia->id, ['fecha_desde' => '2020-01-01', 'activa' => true]);
    agTurno('10:00', ['estado_id' => Estado::idDe(Estado::CONFIRMADO)]);
    agTurno('10:30', ['paciente_id' => $this->otraPaciente->id, 'estado_id' => Estado::idDe(Estado::CANCELADO)]);
    epCupo(2);
    epTurno(TipoTurno::URGENCIA, ['paciente_id' => $this->otraPaciente->id, 'llegada_en' => now()]);

    $this->actingAs($this->recepcion);
    $col = fn (Profesional $profesional) => 'scope="col" data-profesional="'.$profesional->id.'"';
    $ver = fn (array $filtros) => $this->get(route('admin.agenda-general.index', $filtros))->assertOk()->getContent();

    // Por defecto, hoy y sin cancelados; ambos profesionales (los dos atienden los martes).
    expect($ver([]))->toContain($col($this->profesional))->toContain($col($otro));
    expect($ver(['profesional' => $otro->id]))->toContain($col($otro))->not->toContain($col($this->profesional))->not->toContain('data-turno=');
    expect($ver(['especialidad' => $ginecologia->id]))->toContain($col($this->profesional))->not->toContain($col($otro));
    expect($ver(['fecha' => '07/10/2026']))->toContain('No hay profesionales ni turnos')->toContain('07/10/2026');
    expect($ver(['fecha' => '13/10/2026']))->toContain($col($this->profesional))->toContain($col($otro));
    expect($ver(['estado' => 'CANCELADO']))->toContain('Cancelado')->toContain($col($this->profesional))->not->toContain($col($otro));
    expect($ver(['tipo' => 'URGENCIA']))->toContain('data-franja')->not->toContain($col($otro));
    expect($ver(['q' => $this->paciente->nro_ficha]))->toContain($col($this->profesional))->not->toContain($col($otro));
    expect($ver(['q' => 'Inexistente']))->toContain('No hay profesionales ni turnos');
    // El fragmento en vivo es solo la grilla.
    $this->get(route('admin.agenda-general.index', ['q' => 'Duarte']), $this->ajax)->assertOk()->assertDontSee('<html', false)->assertSee('data-refresco', false);
});

test('privacidad: recepción no ve urgente por triaje, observación, signos alterados ni diagnósticos (HTML y fragmento)', function () {
    $turno = agTurno('10:00');
    $consulta = epTriaje($turno, urgente: true, observacion: 'OBS-RESERVADA dolor torácico', alterados: true);
    $this->actingAs($this->recepcion);

    $enCriterio = function () {
        $pantallas = [
            $this->get(route('admin.agenda-general.index'))->assertOk()->getContent(),
            $this->get(route('admin.agenda-general.index'), $this->ajax)->assertOk()->getContent(),
            $this->get(route('admin.agenda-general.index', ['profesional' => $this->profesional->id, 'q' => 'Duarte']), $this->ajax)->assertOk()->getContent(),
        ];
        foreach ($pantallas as $html) {
            expect($html)->toContain('Con triaje')
                ->not->toContain('OBS-RESERVADA')->not->toContain('dolor tor')->not->toContain('triaje_urgente')->not->toContain('triaje_observacion')
                ->not->toContain('ALTERADO')->not->toContain('signos_alertas')->not->toContain('39.5')->not->toContain('Diagnóstico')->not->toContain('diagnostic');
        }

        // Marcar o no la urgencia en el triaje no cambia nada de lo que ve recepción (salvo el token del formulario).
        return preg_replace('/name="_token" value="[^"]*"|data-turno="\d+"/', '', $pantallas[1]);
    };

    $conUrgencia = $enCriterio();
    $consulta->forceFill(['triaje_urgente' => false, 'triaje_observacion' => null])->save();
    expect($enCriterio())->toBe($conUrgencia);
});

test('el refresco automático (fragmento) no registra lecturas en la auditoría', function () {
    agTurno('10:00');
    $this->actingAs($this->recepcion)->get(route('admin.agenda-general.index'))->assertOk();
    $antes = LogAuditoria::count();

    $this->get(route('admin.agenda-general.index'), $this->ajax)->assertOk();
    $this->get(route('admin.agenda-general.index', ['q' => 'Duarte']), $this->ajax)->assertOk();

    expect(LogAuditoria::count())->toBe($antes)
        ->and($this->get(route('admin.agenda-general.index'))->headers->get('Cache-Control'))->toContain('no-store');
});

test('rendimiento: cantidad fija de consultas SQL con 10 y con 40 profesionales', function () {
    $this->actingAs($this->recepcion);
    $cargar = function (int $desde, int $hasta) {
        foreach (range($desde, $hasta) as $n) {
            $profesional = agProfesional("Prueba{$n}", $n, agEspecialidad("Especialidad {$n}"));
            $paciente = epPaciente(['apellidos' => "Paciente{$n}"], 'FP-'.(7000 + $n));
            $turno = agTurno('10:00', ['profesional_id' => $profesional->id, 'consultorio_id' => Disponibilidad::where('profesional_id', $profesional->id)->value('consultorio_id')], $paciente);
            epTriaje($turno, urgente: $n % 2 === 0, alterados: true);
            epTurno(TipoTurno::ENTRE_PACIENTES, ['profesional_id' => $profesional->id, 'paciente_id' => $paciente->id]);
        }
    };

    $cargar(1, 10);
    $con10 = consultasSql(route('admin.agenda-general.index'));
    $filtrado10 = consultasSql(route('admin.agenda-general.index', ['q' => 'Paciente']), $this->ajax);
    $cargar(11, 40);
    $con40 = consultasSql(route('admin.agenda-general.index'));
    $filtrado40 = consultasSql(route('admin.agenda-general.index', ['q' => 'Paciente']), $this->ajax);

    expect($con40)->toBe($con10)->and($filtrado40)->toBe($filtrado10);
    // Paginado: una página de 10 columnas, con enlaces a las demás.
    $html = $this->get(route('admin.agenda-general.index'))->getContent();
    expect(substr_count($html, 'scope="col" data-profesional'))->toBe(10)->and($html)->toContain('page=2');
});

test('escapado: nombres con HTML se muestran escapados', function () {
    $this->paciente->persona->update(['apellidos' => 'Duarte <img src=x onerror=alert(1)>']);
    $this->profesional->persona->update(['apellidos' => 'Benítez <script>alert(2)</script>']);
    agTurno('10:00');

    $html = $this->actingAs($this->recepcion)->get(route('admin.agenda-general.index'))->assertOk()->getContent();
    expect($html)->not->toContain('<img src=x')->not->toContain('<script>alert(2)')
        ->and($html)->toContain('&lt;img src=x onerror=alert(1)&gt;')->toContain('&lt;script&gt;alert(2)&lt;/script&gt;');
});
