<?php

namespace App\Support\Convenios;

use App\Models\CoberturaPaciente;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\Visacion;
use App\Support\Fecha;
use App\Support\Permisos;
use Illuminate\Support\Collection;

/**
 * Lo que decide si el turno (o la atención) lleva el botón "Planilla de aceptación" y qué aviso muestra: solo con EXPORTAR sobre VISACIONES
 * y si la paciente tiene una cobertura abierta, aunque esté vencida (no frena nada: la planilla sale con la leyenda). Sin cobertura abierta,
 * sin botón. Dos consultas para toda la lista; sin montos y sin nada clínico.
 */
class PlanillaTurnos
{
    public static function puede(): bool
    {
        return Permisos::puedeRuta('admin.visaciones.aceptacion-turno');
    }

    /** [turno_id => ['vencida' => 'dd/mm/aaaa'|null, 'visada' => bool]] de los turnos con cobertura abierta. Vacío sin permiso. */
    public static function para(iterable $turnos): array
    {
        if (! self::puede()) {
            return [];
        }
        $turnos = Collection::make($turnos)->filter(fn (Turno $turno) => ! $turno->tieneEstado(Estado::CANCELADO));
        if ($turnos->isEmpty()) {
            return [];
        }
        $coberturas = CoberturaPaciente::query()->whereIn('paciente_id', $turnos->pluck('paciente_id')->unique())->whereNull('cerrada_en')
            ->orderBy('vigente_desde')->orderBy('id')->get()->keyBy('paciente_id');
        if ($coberturas->isEmpty()) {
            return [];
        }
        $visaciones = Visacion::query()->vigentes()->whereIn('cobertura_paciente_id', $coberturas->pluck('id'))->get(['turno_id', 'consulta_id']);
        $conTurno = $visaciones->pluck('turno_id')->filter()->flip();
        $conConsulta = $visaciones->pluck('consulta_id')->filter()->flip();
        $consultas = Consulta::query()->whereIn('turno_id', $turnos->pluck('id'))->pluck('id', 'turno_id');

        $estado = [];
        foreach ($turnos as $turno) {
            $cobertura = $coberturas->get($turno->paciente_id);
            if ($cobertura === null) {
                continue;
            }
            $consultaId = $consultas->get($turno->id);
            $estado[$turno->id] = [
                'vencida' => $cobertura->aplicaA($turno->fecha->format('Y-m-d')) ? null : Fecha::mostrar($cobertura->vigente_hasta),
                'visada' => $conTurno->has($turno->id) || ($consultaId !== null && $conConsulta->has($consultaId)),
            ];
        }

        return $estado;
    }

    /** Lo mismo para una sola consulta (la pantalla de atención): null si no hay permiso o cobertura abierta. */
    public static function paraConsulta(Consulta $consulta): ?array
    {
        if (! Permisos::puedeRuta('admin.visaciones.ticket')) {
            return null;
        }
        $paciente = $consulta->historiaClinica->paciente;
        $cobertura = TicketVisacion::coberturaAbierta($paciente->id);
        if ($cobertura === null) {
            return null;
        }
        $dia = Fecha::diaLocal($consulta->fechaAtencion());
        $visada = Visacion::query()->vigentes()->where('cobertura_paciente_id', $cobertura->id)
            ->where(fn ($q) => $q->where('consulta_id', $consulta->id)->when($consulta->turno_id, fn ($q) => $q->orWhere('turno_id', $consulta->turno_id)))->exists();

        return ['vencida' => $cobertura->aplicaA($dia) ? null : Fecha::mostrar($cobertura->vigente_hasta), 'visada' => $visada];
    }
}
