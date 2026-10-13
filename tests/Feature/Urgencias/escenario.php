<?php

/*
 * Escenario de los tests de entre pacientes y urgencias (lo carga tests/Pest.php). Se apoya en hcEscenario
 * ("ahora": martes 06/10/2026 09:00 en Paraguay; Dra. Benítez y Dr. Insfrán; paciente Carmen Duarte) y suma:
 *
 * - disponibilidad de la Dra. Benítez los martes de 08:00 a 12:00 (turnos de 30 minutos);
 * - recepcion: usuario con TURNOS (VER, CREAR, EDITAR) y DISPONIBILIDAD (VER, CREAR, EDITAR), sin nada clínico;
 * - el origen de turno PRESENCIAL;
 * - otraPaciente: Lidia Gómez.
 *
 * Datos inventados. Funciones con el prefijo "ep".
 */

use App\Enums\TipoTurno;
use App\Models\CupoEntrePacientes;
use App\Models\Disponibilidad;
use App\Models\Estado;
use App\Models\OrigenTurno;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;

const EP_PERMISOS_RECEPCION = ['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'DISPONIBILIDAD' => ['VER', 'CREAR', 'EDITAR']];

function epEscenario(): void
{
    hcEscenario();
    $t = test();

    $t->disponibilidad = epDisponibilidad($t->profesional);
    $t->recepcion = User::factory()->conPermisos(EP_PERMISOS_RECEPCION)->conPersona(['apellidos' => 'Ramírez', 'nombres' => 'Sonia'])->create();
    $t->presencial = OrigenTurno::create(['codigo' => 'PRESENCIAL', 'nombre' => 'Presencial']);
    $t->otraPaciente = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Gómez', 'nombres' => 'Lidia', 'fecha_nacimiento' => '1985-01-20', 'sexo' => 'F'])->id, 'nro_ficha' => 'FP-0000002']);
}

/** Disponibilidad ACTIVA del profesional los martes (por defecto) de 08:00 a 12:00. */
function epDisponibilidad(Profesional $profesional, array $cambios = []): Disponibilidad
{
    return Disponibilidad::create([
        'profesional_id' => $profesional->id, 'consultorio_id' => test()->consultorio->id, 'dia_semana' => 'MAR',
        'hora_desde' => '08:00', 'hora_hasta' => '12:00', 'duracion_turno_minutos' => 30, 'vigencia_desde' => '2026-01-01',
        ...$cambios,
    ]);
}

/** Cupo de la Dra. Benítez (o del profesional indicado) para hoy, directo en la base. */
function epCupo(int $cantidad, ?Profesional $profesional = null, string $fecha = '2026-10-06'): CupoEntrePacientes
{
    return CupoEntrePacientes::create(['profesional_id' => ($profesional ?? test()->profesional)->id, 'fecha' => $fecha, 'cantidad' => $cantidad, 'creado_por' => test()->recepcion->id]);
}

/** Turno sin horario (ENTRE_PACIENTES por defecto) de hoy, de la Dra. Benítez con Carmen Duarte, directo en la base. */
function epTurno(TipoTurno $tipo = TipoTurno::ENTRE_PACIENTES, array $cambios = []): Turno
{
    return Turno::create([
        'tipo' => $tipo, 'paciente_id' => test()->paciente->id, 'profesional_id' => test()->profesional->id,
        'fecha' => '2026-10-06', 'estado_id' => Estado::idDe(Estado::PENDIENTE),
        ...$cambios,
    ]);
}

/** Un paciente nuevo, ACTIVO, con estos datos de persona. */
function epPaciente(array $persona, string $ficha): Paciente
{
    return Paciente::create(['persona_id' => Persona::factory()->create(['fecha_nacimiento' => '1990-01-01', 'sexo' => 'F', ...$persona])->id, 'nro_ficha' => $ficha]);
}
