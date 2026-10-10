<?php

use App\Models\Consultorio;
use App\Models\Disponibilidad;
use App\Models\Estado;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use App\Support\Agenda;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * La restricción de no superposición de turnos es de PostgreSQL (EXCLUDE + tsrange + btree_gist):
 * estos tests corren contra la base de prueba clinexa_test (PostgresTestCase). Lo demás de la
 * agenda (cálculo de horarios, alta, estados, CRUDs) está en tests/Feature/Admin/AgendaTest.php.
 */
beforeEach(function () {
    $this->seed(ModuloSistemaSeeder::class);

    $sucursal = Sucursal::create(['nombre' => 'Plenitud Mujer', 'direccion' => 'Iturbe', 'telefono' => '0975']);
    $this->consultorio = Consultorio::create(['sucursal_id' => $sucursal->id, 'nombre' => 'Consultorio 1']);
    $this->consultorio2 = Consultorio::create(['sucursal_id' => $sucursal->id, 'nombre' => 'Consultorio 2']);
    $this->profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);
    $this->otroProfesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-2']);
    $paciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000001']);
    $this->paciente = $paciente;

    // Cada inserción en su propia transacción (savepoint): si la base la rechaza, el test sigue.
    $this->turno = fn (array $cambios = []) => DB::transaction(fn () => Turno::create([
        'paciente_id' => $paciente->id, 'profesional_id' => $this->profesional->id, 'consultorio_id' => $this->consultorio->id,
        'fecha' => '2026-10-06', 'hora_inicio' => '08:00', 'hora_fin' => '08:30', ...$cambios,
    ]));
});

test('corre de verdad contra PostgreSQL, con la restricción creada', function () {
    expect(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('clinexa_test')
        ->and(collect(DB::select("select conname from pg_constraint where conrelid = 'turnos'::regclass and contype = 'x'"))->pluck('conname')->sort()->values()->all())
        ->toBe(['turnos_sin_superposicion_consultorio', 'turnos_sin_superposicion_paciente', 'turnos_sin_superposicion_profesional']);
});

test('rechaza un turno superpuesto del mismo profesional (aunque sea en otro consultorio)', function () {
    ($this->turno)();

    expect(fn () => ($this->turno)(['consultorio_id' => $this->consultorio2->id, 'hora_inicio' => '08:15', 'hora_fin' => '08:45']))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23P01')->and($e->getMessage())->toContain('turnos_sin_superposicion_profesional'));
    expect(Turno::count())->toBe(1);
});

test('rechaza un turno superpuesto en el mismo consultorio (aunque sea de otro profesional)', function () {
    ($this->turno)();

    expect(fn () => ($this->turno)(['profesional_id' => $this->otroProfesional->id, 'hora_inicio' => '08:20', 'hora_fin' => '08:50']))
        ->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('turnos_sin_superposicion_consultorio'));
    expect(Turno::count())->toBe(1);
});

test('turnos consecutivos no se pisan (el rango es semiabierto)', function () {
    ($this->turno)();
    ($this->turno)(['hora_inicio' => '08:30', 'hora_fin' => '09:00']);

    expect(Turno::count())->toBe(2);
});

test('el mismo horario otro día, u otro profesional en otro consultorio con OTRA paciente, se permite', function () {
    ($this->turno)();
    ($this->turno)(['fecha' => '2026-10-07']);
    // Otra paciente: con la misma, la superposición la rechaza turnos_sin_superposicion_paciente.
    $otraPaciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000002']);
    ($this->turno)(['paciente_id' => $otraPaciente->id, 'profesional_id' => $this->otroProfesional->id, 'consultorio_id' => $this->consultorio2->id]);

    expect(Turno::count())->toBe(3);
});

test('un turno CANCELADO no ocupa el horario: se puede superponer con él', function () {
    ($this->turno)(['estado_id' => Estado::idDe(Estado::CANCELADO)]);
    ($this->turno)();
    ($this->turno)(['estado_id' => Estado::idDe(Estado::CANCELADO), 'hora_inicio' => '08:10', 'hora_fin' => '08:40']);

    expect(Turno::count())->toBe(3)->and(Turno::ocupanHorario()->count())->toBe(1);
});

test('solo CANCELADO libera: SALTADO, EN_CONSULTA, ATENDIDO y AUSENTE siguen ocupando el horario', function (string $estado) {
    ($this->turno)(['estado_id' => Estado::idDe($estado)]);

    expect(fn () => ($this->turno)())
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23P01'));
    expect(Turno::count())->toBe(1)->and(Turno::ocupanHorario()->count())->toBe(1);
})->with(['SALTADO', 'EN_CONSULTA', 'ATENDIDO', 'AUSENTE']);

test('al cancelar un turno, su horario se puede volver a dar', function () {
    $turno = ($this->turno)();
    $turno->update(['estado_id' => Estado::idDe(Estado::CANCELADO)]);

    ($this->turno)();

    expect(Turno::ocupanHorario()->count())->toBe(1);
});

test('hora_fin tiene que ser posterior a hora_inicio', function () {
    expect(fn () => ($this->turno)(['hora_inicio' => '09:00', 'hora_fin' => '09:00']))
        ->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('turnos_horario_valido'));
});

test('si otro ocupa el horario entre que se eligió y se guardó, la base lo rechaza y el alta avisa', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC')); // lunes; el turno es el martes 06/10
    $this->actingAs(User::factory()->administrador()->create());
    Disponibilidad::create([
        'profesional_id' => $this->profesional->id, 'consultorio_id' => $this->consultorio->id, 'dia_semana' => 'MAR',
        'hora_desde' => '08:00', 'hora_hasta' => '10:00', 'duracion_turno_minutos' => 30, 'vigencia_desde' => '2026-01-01',
    ]);
    $paciente = Paciente::sole();
    expect(Agenda::horario($this->profesional, Carbon::parse('2026-10-06'), '08:30'))->not->toBeNull();

    // Carrera: justo antes de insertar, "otra persona" da un turno al profesional en ese horario.
    Turno::creating(function () use ($paciente) {
        DB::table('turnos')->insert([
            'paciente_id' => $paciente->id, 'profesional_id' => $this->profesional->id, 'consultorio_id' => $this->consultorio2->id,
            'fecha' => '2026-10-06', 'hora_inicio' => '08:30', 'hora_fin' => '09:00', 'estado_id' => Estado::idDe(Estado::PENDIENTE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->from(route('admin.turnos.create'))->post(route('admin.turnos.store'), [
        'paciente_id' => $paciente->id, 'profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'hora_inicio' => '08:30',
    ])->assertRedirect(route('admin.turnos.create'))
        ->assertSessionHasErrors(['hora_inicio' => 'Ese horario se acaba de ocupar. Elija otro.']);

    // Se deshizo solo la transacción del alta y la conexión sigue usable.
    expect(Turno::count())->toBe(0);

    Carbon::setTestNow();
});

describe('misma paciente (turnos_sin_superposicion_paciente)', function () {
    /** Un turno de la paciente con la OTRA profesional en el OTRO consultorio: solo puede chocar la restricción de la paciente. */
    function conLaOtra(array $cambios = []): Turno
    {
        return (test()->turno)(['profesional_id' => test()->otroProfesional->id, 'consultorio_id' => test()->consultorio2->id, ...$cambios]);
    }

    test('la base rechaza dos turnos activos superpuestos de la paciente aunque se salte la aplicación', function () {
        ($this->turno)(); // 08:00–08:30 con la primera profesional

        expect(fn () => conLaOtra(['hora_inicio' => '08:15', 'hora_fin' => '08:45']))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23P01')->and($e->getMessage())->toContain('turnos_sin_superposicion_paciente'));
        expect(Turno::count())->toBe(1);
    });

    test('contiguos sí (el rango es semiabierto)', function () {
        ($this->turno)();
        conLaOtra(['hora_inicio' => '08:30', 'hora_fin' => '09:00']);

        expect(Turno::count())->toBe(2);
    });

    test('un turno CANCELADO o AUSENTE de la paciente no bloquea; ATENDIDO sí', function (string $estado, bool $bloquea) {
        ($this->turno)(['estado_id' => Estado::idDe($estado)]);

        $bloquea
            ? expect(fn () => conLaOtra())->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('turnos_sin_superposicion_paciente'))
            : expect(conLaOtra()->exists)->toBeTrue();
    })->with([['CANCELADO', false], ['AUSENTE', false], ['ATENDIDO', true]]);

    test('concurrencia: si otro turno de la paciente se guarda entre la validación y el alta, la base lo rechaza y el alta avisa igual', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC')); // lunes; el turno es el martes 06/10
        $this->actingAs(User::factory()->administrador()->create());
        Disponibilidad::create([
            'profesional_id' => $this->profesional->id, 'consultorio_id' => $this->consultorio->id, 'dia_semana' => 'MAR',
            'hora_desde' => '08:00', 'hora_hasta' => '10:00', 'duracion_turno_minutos' => 30, 'vigencia_desde' => '2026-01-01',
        ]);

        // Carrera: justo antes de insertar, "otra persona" le da a la paciente un turno con la otra profesional a la misma hora.
        Turno::creating(function () {
            DB::table('turnos')->insert([
                'paciente_id' => $this->paciente->id, 'profesional_id' => $this->otroProfesional->id, 'consultorio_id' => $this->consultorio2->id,
                'fecha' => '2026-10-06', 'hora_inicio' => '08:30', 'hora_fin' => '09:00', 'estado_id' => Estado::idDe(Estado::PENDIENTE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $nombre = $this->otroProfesional->persona->nombre_y_apellido;
        $this->from(route('admin.turnos.create'))->post(route('admin.turnos.store'), [
            'paciente_id' => $this->paciente->id, 'profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'hora_inicio' => '08:30',
        ])->assertRedirect(route('admin.turnos.create'))
            ->assertSessionHasErrors('hora_inicio');

        // El otro alta (dentro de la misma transacción del intento) también se deshizo: no queda nada a medias.
        expect(Turno::count())->toBe(0)
            ->and(session('errors')->first('hora_inicio'))->toBeIn(["La paciente ya tiene un turno con {$nombre} a las 08:30.", 'La paciente ya tiene otro turno en ese horario.']);

        Carbon::setTestNow();
    });

    test('dos altas casi simultáneas por la aplicación: la primera queda, la segunda recibe el aviso de la paciente', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
        $this->actingAs(User::factory()->administrador()->create());
        foreach ([[$this->profesional, $this->consultorio], [$this->otroProfesional, $this->consultorio2]] as [$profesional, $consultorio]) {
            Disponibilidad::create(['profesional_id' => $profesional->id, 'consultorio_id' => $consultorio->id, 'dia_semana' => 'MAR',
                'hora_desde' => '08:00', 'hora_hasta' => '10:00', 'duracion_turno_minutos' => 30, 'vigencia_desde' => '2026-01-01']);
        }
        $alta = fn ($profesional) => $this->from(route('admin.turnos.create'))->post(route('admin.turnos.store'), [
            'paciente_id' => $this->paciente->id, 'profesional_id' => $profesional->id, 'fecha' => '06/10/2026', 'hora_inicio' => '08:30']);

        $alta($this->profesional)->assertSessionHasNoErrors();
        $alta($this->otroProfesional)->assertSessionHasErrors(['hora_inicio' => 'La paciente ya tiene un turno con '.$this->profesional->persona->nombre_y_apellido.' a las 08:30.']);
        expect(Turno::count())->toBe(1);

        Carbon::setTestNow();
    });
});