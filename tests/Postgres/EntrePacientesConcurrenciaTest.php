<?php

use App\Enums\TipoTurno;
use App\Exceptions\AccionRechazada;
use App\Models\CupoEntrePacientes;
use App\Models\OrigenTurno;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use App\Support\Fecha;
use App\Support\Turnos\AgendarEntrePacientes;
use App\Support\Turnos\RegistrarUrgencia;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Concurrencia de verdad, con DOS conexiones a clinexa_test: la conexión A deja su transacción abierta a mitad de
 * camino y la B intenta lo mismo. Para que B vea los datos, este test los CONFIRMA (fuera de la transacción de
 * RefreshDatabase) y los borra al final, uno por uno por id (solo lo que creó). Datos inventados.
 *
 * - Cupo 1: mientras A tiene la fila del cupo bloqueada, B espera (lock_timeout corto => 55P03); cuando A confirma,
 *   B ya ve el cupo usado y se rechaza. Nunca hay dos.
 * - Urgencia: mientras A tiene la paciente bloqueada, B espera; después ve la urgencia abierta y se rechaza.
 */
beforeEach(function () {
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');

    DB::commit(); // cierra la transacción de RefreshDatabase: lo que sigue queda confirmado
    $this->creados = [];
    $persona = fn (string $apellido) => tap(Persona::factory()->create(['apellidos' => $apellido, 'nombres' => 'Prueba']), fn ($p) => $this->creados['personas'][] = $p->id);

    $this->profesional = Profesional::create(['persona_id' => $persona('Concurrente')->id, 'matricula' => 'MP-CONC']);
    $this->paciente = Paciente::create(['persona_id' => $persona('Primera')->id, 'nro_ficha' => 'FP-CONC01']);
    $this->otraPaciente = Paciente::create(['persona_id' => $persona('Segunda')->id, 'nro_ficha' => 'FP-CONC02']);
    $this->usuario = User::factory()->sinPerfiles()->create(['persona_id' => $persona('Recepcion')->id]);
    $this->presencial = OrigenTurno::firstOrCreate(['codigo' => 'PRESENCIAL'], ['nombre' => 'Presencial']);
    $this->origenCreado = $this->presencial->wasRecentlyCreated;
    $this->hoy = Fecha::hoy()->format('Y-m-d');
});

afterEach(function () {
    DB::setDefaultConnection('pgsql');
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::connection('pgsql_b')->rollBack(0);
    DB::disconnect('pgsql_b');

    // Solo lo que creó este test, por id.
    $pacientes = [$this->paciente->id, $this->otraPaciente->id];
    DB::table('turnos')->whereIn('paciente_id', $pacientes)->delete();
    DB::table('cupos_entre_pacientes')->where('profesional_id', $this->profesional->id)->delete();
    DB::table('historias_clinicas')->whereIn('paciente_id', $pacientes)->delete();
    DB::table('pacientes')->whereIn('id', $pacientes)->delete();
    DB::table('profesionales')->where('id', $this->profesional->id)->delete();
    DB::table('usuario_perfil')->where('usuario_id', $this->usuario->id)->delete();
    DB::table('users')->where('id', $this->usuario->id)->delete();
    DB::table('personas')->whereIn('id', $this->creados['personas'])->delete();
    if ($this->origenCreado) {
        DB::table('origenes_turno')->where('id', $this->presencial->id)->delete();
    }

    DB::beginTransaction(); // la que RefreshDatabase espera deshacer al terminar
});

/** Corre $accion en la conexión B con un tiempo de espera de bloqueo corto. */
function concurrenciaEnB(Closure $accion): mixed
{
    DB::setDefaultConnection('pgsql_b');
    try {
        return DB::transaction(function () use ($accion) {
            DB::statement("SET LOCAL lock_timeout = '700ms'");

            return $accion();
        });
    } finally {
        DB::setDefaultConnection('pgsql');
    }
}

test('dos altas entre pacientes casi simultáneas con cupo 1: la segunda espera y después se rechaza', function () {
    CupoEntrePacientes::create(['profesional_id' => $this->profesional->id, 'fecha' => $this->hoy, 'cantidad' => 1, 'creado_por' => $this->usuario->id]);
    $datos = fn (Paciente $paciente) => ['paciente_id' => $paciente->id, 'profesional_id' => $this->profesional->id, 'fecha' => $this->hoy];

    DB::beginTransaction(); // A: agenda y todavía no confirma
    AgendarEntrePacientes::ejecutar($datos($this->paciente));

    expect(fn () => concurrenciaEnB(fn () => AgendarEntrePacientes::ejecutar($datos($this->otraPaciente))))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03')); // B espera el bloqueo de A

    DB::commit(); // A confirma
    expect(fn () => concurrenciaEnB(fn () => AgendarEntrePacientes::ejecutar($datos($this->otraPaciente))))
        ->toThrow(AccionRechazada::class, AgendarEntrePacientes::CUPO_COMPLETO);

    expect(Turno::where('profesional_id', $this->profesional->id)->where('tipo', TipoTurno::ENTRE_PACIENTES->value)->count())->toBe(1);
});

test('dos registros casi simultáneos de la misma urgencia: una sola abierta', function () {
    DB::beginTransaction(); // A: registra y todavía no confirma
    RegistrarUrgencia::ejecutar($this->paciente->id, $this->profesional->id);

    expect(fn () => concurrenciaEnB(fn () => RegistrarUrgencia::ejecutar($this->paciente->id, $this->profesional->id)))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));

    DB::commit();
    expect(fn () => concurrenciaEnB(fn () => RegistrarUrgencia::ejecutar($this->paciente->id, $this->profesional->id)))
        ->toThrow(AccionRechazada::class, RegistrarUrgencia::YA_TIENE);

    expect(Turno::where('paciente_id', $this->paciente->id)->where('tipo', TipoTurno::URGENCIA->value)->count())->toBe(1);
});

test('y si dos inserciones se saltean la aplicación, el índice único parcial de la base frena la segunda', function () {
    $urgencia = fn () => ['tipo' => 'URGENCIA', 'paciente_id' => $this->paciente->id, 'profesional_id' => $this->profesional->id, 'fecha' => $this->hoy,
        'estado_id' => DB::table('estados')->where('codigo', 'PENDIENTE')->value('id'), 'created_at' => now(), 'updated_at' => now()];
    DB::table('turnos')->insert($urgencia());

    expect(fn () => DB::transaction(fn () => DB::table('turnos')->insert($urgencia())))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23505')->and($e->getMessage())->toContain('turnos_una_urgencia_abierta'));
});
