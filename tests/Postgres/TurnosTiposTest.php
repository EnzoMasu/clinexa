<?php

use App\Enums\TipoTurno;
use App\Models\Consultorio;
use App\Models\CupoEntrePacientes;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Tipos de turno en PostgreSQL (2026_10_14_100001): rango NULL sin horas (un tsrange con bordes NULL sería
 * ilimitado y chocaría con todo), coexistencia con las tres EXCLUDE y los CHECK. Datos inventados.
 */
beforeEach(function () {
    $this->seed(ModuloSistemaSeeder::class);

    $sucursal = Sucursal::create(['nombre' => 'Sede Prueba', 'direccion' => 'Calle Falsa 123', 'telefono' => '0900']);
    $this->consultorio = Consultorio::create(['sucursal_id' => $sucursal->id, 'nombre' => 'Consultorio 1']);
    $this->profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-T1']);
    $this->paciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-T000001']);
    $this->otraPaciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-T000002']);

    // Cada inserción en su propia transacción (savepoint): si la base la rechaza, el test sigue.
    $this->turno = fn (array $cambios = []) => DB::transaction(fn () => Turno::create([
        'paciente_id' => $this->paciente->id, 'profesional_id' => $this->profesional->id, 'consultorio_id' => $this->consultorio->id,
        'fecha' => '2026-10-06', 'hora_inicio' => '08:00', 'hora_fin' => '08:30', ...$cambios,
    ]));
    $this->sinHora = fn (TipoTurno $tipo, array $cambios = []) => ($this->turno)([
        'tipo' => $tipo, 'hora_inicio' => null, 'hora_fin' => null, 'consultorio_id' => null, ...$cambios,
    ]);
});

test('la trampa: un tsrange con bordes NULL es ilimitado; el rango de un turno sin horas es NULL', function () {
    expect(DB::selectOne("select tsrange(null::timestamp, null::timestamp, '[)') is null as es_null")->es_null)->toBeFalse();

    $turno = ($this->sinHora)(TipoTurno::ENTRE_PACIENTES);

    expect(DB::table('turnos')->where('id', $turno->id)->value('rango'))->toBeNull()
        ->and(DB::table('turnos')->where('id', ($this->turno)()->id)->value('rango'))->toBe('["2026-10-06 08:00:00","2026-10-06 08:30:00")');
});

test('dos ENTRE_PACIENTES del mismo profesional y día coexisten (también de la misma paciente)', function () {
    ($this->sinHora)(TipoTurno::ENTRE_PACIENTES);
    ($this->sinHora)(TipoTurno::ENTRE_PACIENTES);
    ($this->sinHora)(TipoTurno::ENTRE_PACIENTES, ['paciente_id' => $this->otraPaciente->id]);

    expect(Turno::where('tipo', 'ENTRE_PACIENTES')->count())->toBe(3);
});

test('un ENTRE_PACIENTES y una URGENCIA coexisten con un NORMAL del mismo profesional, consultorio y paciente', function () {
    ($this->turno)();
    ($this->sinHora)(TipoTurno::ENTRE_PACIENTES, ['consultorio_id' => $this->consultorio->id]);
    ($this->sinHora)(TipoTurno::URGENCIA);

    expect(Turno::count())->toBe(3);
});

test('un NORMAL superpuesto se sigue bloqueando como siempre', function () {
    ($this->turno)();

    expect(fn () => ($this->turno)(['paciente_id' => $this->otraPaciente->id, 'hora_inicio' => '08:15', 'hora_fin' => '08:45']))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23P01')->and($e->getMessage())->toContain('turnos_sin_superposicion_profesional'));
    expect(fn () => ($this->turno)(['profesional_id' => Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-T2'])->id, 'hora_inicio' => '08:15', 'hora_fin' => '08:45']))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23P01'));
    expect(Turno::count())->toBe(1);
});

test('las tres EXCLUDE siguen con su definición de siempre', function () {
    $definiciones = collect(DB::select("select conname, pg_get_constraintdef(oid) def from pg_constraint where conrelid = 'turnos'::regclass and contype = 'x'"))->pluck('def', 'conname');

    expect($definiciones->keys()->sort()->values()->all())->toBe(['turnos_sin_superposicion_consultorio', 'turnos_sin_superposicion_paciente', 'turnos_sin_superposicion_profesional'])
        ->and($definiciones['turnos_sin_superposicion_profesional'])->toContain('EXCLUDE USING gist (profesional_id WITH =, rango WITH &&)')
        ->and($definiciones['turnos_sin_superposicion_consultorio'])->toContain('EXCLUDE USING gist (consultorio_id WITH =, rango WITH &&)')
        ->and($definiciones['turnos_sin_superposicion_paciente'])->toContain('EXCLUDE USING gist (paciente_id WITH =, rango WITH &&)');
});

test('CHECK: NORMAL exige horas y consultorio; los otros tipos exigen horas nulas; tipo válido', function () {
    $violacion = fn (string $restriccion) => fn (QueryException $e) => expect($e->getCode())->toBe('23514')->and($e->getMessage())->toContain($restriccion);

    expect(fn () => ($this->turno)(['hora_inicio' => null, 'hora_fin' => null]))->toThrow($violacion('turnos_horas_segun_tipo'));
    expect(fn () => ($this->turno)(['consultorio_id' => null]))->toThrow($violacion('turnos_horas_segun_tipo'));
    expect(fn () => ($this->turno)(['tipo' => TipoTurno::URGENCIA]))->toThrow($violacion('turnos_horas_segun_tipo'));
    expect(fn () => DB::transaction(fn () => DB::table('turnos')->where('id', ($this->sinHora)(TipoTurno::URGENCIA)->id)->update(['tipo' => 'OTRO'])))
        ->toThrow($violacion('turnos_tipo_valido'));
});

test('cupo: único por profesional y fecha, cantidad de 0 a 30', function () {
    $usuario = User::factory()->create();
    $cupo = fn (int $cantidad, string $fecha = '2026-10-06') => DB::transaction(fn () => CupoEntrePacientes::create([
        'profesional_id' => $this->profesional->id, 'fecha' => $fecha, 'cantidad' => $cantidad, 'creado_por' => $usuario->id,
    ]));

    $cupo(30);
    expect(fn () => $cupo(5))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23505'));
    expect(fn () => $cupo(31, '2026-10-07'))->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('cupos_entre_pacientes_cantidad_valida'));
    expect(CupoEntrePacientes::count())->toBe(1);
});
