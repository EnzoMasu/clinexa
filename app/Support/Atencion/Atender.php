<?php

namespace App\Support\Atencion;

use App\Exceptions\AccionRechazada;
use App\Exceptions\RequiereConfirmacion;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Atender un turno (con horario, entre pacientes o urgencia: las mismas reglas, sin validar la hora): con turno
 * y consulta bloqueados, la consulta EN_PREPARACION pasa a EN_CURSO (o se crea EN_CURSO si no se preparó),
 * iniciada_en = ahora, y el turno pasa a EN_CONSULTA. Un segundo envío (doble clic) encuentra la consulta ya en
 * curso y la devuelve sin repetir nada.
 *
 * Una urgencia (URGENCIA o triaje urgente) con otra consulta EN_CURSO del profesional pide confirmación
 * (RequiereConfirmacion); confirmada, la otra queda abierta (se retoma con "Continuar") y el detalle del evento lo dice.
 */
final class Atender
{
    public const CONFIRMAR_URGENCIA = 'Tiene una consulta en curso con %s. ¿Atender la urgencia igualmente?';

    public static function ejecutar(User $usuario, Turno $turno, bool $confirmaUrgencia = false): Consulta
    {
        try {
            return DB::transaction(function () use ($usuario, $turno, $confirmaUrgencia) {
                $turno = Apoyo::turnoBloqueado($turno);
                $consulta = Consulta::where('turno_id', $turno->id)->lockForUpdate()->first();

                if ($consulta?->enCurso() && $turno->tieneEstado(Estado::EN_CONSULTA)) {
                    Gate::forUser($usuario)->authorize('escribirClinico', $consulta);

                    return $consulta; // doble clic: ya se está atendiendo
                }
                Gate::forUser($usuario)->authorize('atenderTurno', [Consulta::class, $turno]);
                if ($consulta && ! $consulta->enPreparacion()) {
                    throw new AccionRechazada('Este turno ya tiene una consulta ('.mb_strtolower($consulta->estado->nombre).'): no se puede volver a atender.');
                }

                $turno->setRelation('consulta', $consulta);
                $enCurso = PendientesDelProfesional::esUrgencia($turno)
                    ? Consulta::with('historiaClinica.paciente.persona')->where('profesional_id', $turno->profesional_id)
                        ->where('estado_id', Estado::idDe(Estado::EN_CURSO))
                        ->where(fn ($query) => $query->whereNull('turno_id')->orWhere('turno_id', '!=', $turno->id))
                        ->orderBy('iniciada_en')->first()
                    : null;
                if ($enCurso && ! $confirmaUrgencia) {
                    throw new RequiereConfirmacion(sprintf(self::CONFIRMAR_URGENCIA, $enCurso->historiaClinica->paciente->persona->nombre_y_apellido));
                }
                $detalle = $enCurso ? "Atender. Urgencia con la consulta #{$enCurso->id} en curso, confirmado" : 'Atender';

                return Auditoria::conDetalle($detalle, function () use ($turno, $consulta) {
                    if ($consulta) {
                        $consulta->forceFill(['estado_id' => Estado::idDe(Estado::EN_CURSO), 'iniciada_en' => now()])->save();
                        $consulta->unsetRelation('estado');
                    } else {
                        $consulta = Apoyo::crearConsulta($turno->paciente->historiaClinica, $turno, Estado::EN_CURSO);
                    }
                    $turno->pasarA(Estado::EN_CONSULTA);

                    return $consulta;
                });
            });
        } catch (QueryException $e) {
            return Apoyo::siYaExiste($e, $turno);
        }
    }
}
