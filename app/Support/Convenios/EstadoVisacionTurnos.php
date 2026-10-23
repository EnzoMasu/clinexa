<?php

namespace App\Support\Convenios;

use App\Models\CoberturaPaciente;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\Visacion;
use App\Support\Fecha;
use Illuminate\Support\Collection;

/**
 * Lo que recepción ve en cada turno (Agenda general, Turnos, Urgencias de hoy): "Seguro: aseguradora" y "Visación cargada" o "Falta
 * visación", solo si la paciente tiene cobertura vigente y el usuario tiene VER sobre VISACIONES. Sin montos y sin nada clínico; no frena
 * ninguna acción del turno. Dos consultas para toda la lista.
 */
class EstadoVisacionTurnos
{
    /** Si el usuario ve el botón y el estado (VER sobre VISACIONES). */
    public static function puedeVer(): bool
    {
        return auth()->user()?->tienePermiso('VISACIONES', 'VER') === true;
    }

    /** Los turnos de la lista con cobertura vigente: [turno_id => ['seguro' => aseguradora, 'visada' => bool]]. Vacío sin permiso. */
    public static function para(iterable $turnos): array
    {
        if (! self::puedeVer()) {
            return [];
        }
        $turnos = Collection::make($turnos)->filter(fn (Turno $turno) => ! $turno->tieneEstado(Estado::CANCELADO));
        if ($turnos->isEmpty()) {
            return [];
        }
        $hoy = Fecha::hoy()->format('Y-m-d');
        $coberturas = CoberturaPaciente::query()->whereIn('paciente_id', $turnos->pluck('paciente_id')->unique())->whereNull('cerrada_en')
            ->with('plan.convenio.responsablePago.persona')->get()
            ->filter(fn (CoberturaPaciente $cobertura) => $cobertura->aplicaA($hoy))->keyBy('paciente_id');
        if ($coberturas->isEmpty()) {
            return [];
        }
        $visaciones = Visacion::query()->vigentes()->whereIn('cobertura_paciente_id', $coberturas->pluck('id'))->get(['turno_id', 'consulta_id']);
        $conTurno = $visaciones->pluck('turno_id')->filter()->flip();
        $conConsulta = $visaciones->pluck('consulta_id')->filter()->flip();
        $consultas = \App\Models\Consulta::query()->whereIn('turno_id', $turnos->pluck('id'))->pluck('id', 'turno_id');

        $estado = [];
        foreach ($turnos as $turno) {
            $cobertura = $coberturas->get($turno->paciente_id);
            if ($cobertura === null) {
                continue;
            }
            $consultaId = $consultas->get($turno->id);
            $estado[$turno->id] = [
                'seguro' => $cobertura->plan->convenio->responsablePago->persona->nombre_completo,
                'visada' => $conTurno->has($turno->id) || ($consultaId !== null && $conConsulta->has($consultaId)),
            ];
        }

        return $estado;
    }
}
