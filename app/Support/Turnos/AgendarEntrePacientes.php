<?php

namespace App\Support\Turnos;

use App\Enums\TipoTurno;
use App\Exceptions\AccionRechazada;
use App\Models\CupoEntrePacientes;
use App\Models\Turno;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agendar un paciente "entre pacientes" (sin horario): en una transacción con la fila del cupo del profesional
 * y la fecha BLOQUEADA (lockForUpdate), así dos envíos casi simultáneos no pasan el cupo (el segundo espera
 * al primero y ya lo ve usado). Sin cupo fijado vale 0. Un segundo turno del día con el mismo profesional
 * (de cualquier tipo) se agenda solo confirmando, como los de horario.
 */
final class AgendarEntrePacientes
{
    public const SIN_CUPO = 'El profesional no tiene cupo para pacientes entre pacientes ese día.';

    public const CUPO_COMPLETO = 'Ya se completó el cupo de pacientes entre pacientes de ese día.';

    /**
     * @param  array{paciente_id: int, profesional_id: int, fecha: string, origen_turno_id?: ?int, procedimiento_id?: ?int, observaciones?: ?string}  $datos  fecha en ISO
     */
    public static function ejecutar(array $datos, bool $agendarIgual = false): Turno
    {
        return DB::transaction(function () use ($datos, $agendarIgual) {
            $cupo = CupoEntrePacientes::where('profesional_id', $datos['profesional_id'])->whereDate('fecha', $datos['fecha'])->lockForUpdate()->first();
            $cantidad = $cupo?->cantidad ?? 0;
            if ($cantidad === 0) {
                throw new AccionRechazada(self::SIN_CUPO);
            }
            if (CupoEntrePacientes::usados((int) $datos['profesional_id'], $datos['fecha']) >= $cantidad) {
                throw new AccionRechazada(self::CUPO_COMPLETO);
            }

            $otro = Turno::otroDelDiaConElProfesional((int) $datos['paciente_id'], (int) $datos['profesional_id'], $datos['fecha']);
            if ($otro && ! $agendarIgual) {
                throw ValidationException::withMessages(['agendar_igual' => $otro->avisoSegundoDelDia()]);
            }

            return Auditoria::conDetalle($otro ? 'Entre pacientes. Segundo turno del día confirmado' : 'Entre pacientes', fn () => Turno::create([
                'tipo' => TipoTurno::ENTRE_PACIENTES,
                'paciente_id' => $datos['paciente_id'],
                'profesional_id' => $datos['profesional_id'],
                'fecha' => $datos['fecha'],
                'origen_turno_id' => $datos['origen_turno_id'] ?? null,
                'procedimiento_id' => $datos['procedimiento_id'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
            ]));
        });
    }
}
