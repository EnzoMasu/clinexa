<?php

namespace App\Support\Turnos;

use App\Enums\TipoTurno;
use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\OrigenTurno;
use App\Models\Paciente;
use App\Models\Turno;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registrar una urgencia (recepción): un turno URGENCIA de HOY, PENDIENTE, origen PRESENCIAL y llegada_en = ahora,
 * para una paciente registrada y activa y un profesional activo. Sin texto libre: lo clínico lo escribe el triaje.
 *
 * Una sola urgencia abierta (PENDIENTE, CONFIRMADO, SALTADO o EN_CONSULTA) por paciente, profesional y día: se
 * valida con la fila de la paciente bloqueada y la respalda el índice único parcial turnos_una_urgencia_abierta
 * (doble envío o dos altas simultáneas). Un segundo turno del día con el profesional (de cualquier tipo) pide
 * confirmación, como en el alta de turnos.
 */
final class RegistrarUrgencia
{
    public const YA_TIENE = 'La paciente ya tiene una urgencia abierta con ese profesional.';

    public const ABIERTAS = [Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO, Estado::EN_CONSULTA];

    /** SQLSTATE de clave única repetida (PostgreSQL 23505; SQLite informa 23000). */
    private const CLAVE_REPETIDA = ['23505', '23000'];

    public static function ejecutar(int $pacienteId, int $profesionalId, bool $agendarIgual = false): Turno
    {
        $hoy = Fecha::hoy()->format('Y-m-d');
        $origen = OrigenTurno::presencial();

        try {
            return DB::transaction(function () use ($pacienteId, $profesionalId, $agendarIgual, $hoy, $origen) {
                Paciente::whereKey($pacienteId)->lockForUpdate()->firstOrFail();

                if (self::abierta($pacienteId, $profesionalId, $hoy)) {
                    throw new AccionRechazada(self::YA_TIENE);
                }
                $otro = Turno::otroDelDiaConElProfesional($pacienteId, $profesionalId, $hoy);
                if ($otro && ! $agendarIgual) {
                    throw ValidationException::withMessages(['agendar_igual' => $otro->avisoSegundoDelDia()]);
                }

                return Auditoria::conDetalle($otro ? 'Urgencia registrada. Segundo turno del día confirmado' : 'Urgencia registrada', fn () => Turno::create([
                    'tipo' => TipoTurno::URGENCIA,
                    'paciente_id' => $pacienteId,
                    'profesional_id' => $profesionalId,
                    'fecha' => $hoy,
                    'estado_id' => Estado::idDe(Estado::PENDIENTE),
                    'origen_turno_id' => $origen->id,
                    'llegada_en' => now(),
                ]));
            });
        } catch (QueryException $e) {
            // El índice turnos_una_urgencia_abierta (PostgreSQL lo nombra; SQLite nombra sus columnas).
            if (in_array((string) $e->getCode(), self::CLAVE_REPETIDA, true) && (str_contains($e->getMessage(), 'turnos_una_urgencia_abierta')
                || str_contains($e->getMessage(), 'UNIQUE constraint failed: turnos.paciente_id, turnos.profesional_id, turnos.fecha'))) {
                throw new AccionRechazada(self::YA_TIENE);
            }
            throw $e;
        }
    }

    /** La paciente ya tiene una URGENCIA abierta con ese profesional ese día. */
    public static function abierta(int $pacienteId, int $profesionalId, string $fecha): bool
    {
        return Turno::delTipo(TipoTurno::URGENCIA)
            ->where('paciente_id', $pacienteId)->where('profesional_id', $profesionalId)->whereDate('fecha', $fecha)
            ->whereIn('estado_id', array_map(fn (string $codigo) => Estado::idDe($codigo), self::ABIERTAS))
            ->exists();
    }
}
