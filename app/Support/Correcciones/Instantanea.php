<?php

namespace App\Support\Correcciones;

use App\Models\Consulta;

/**
 * Las secciones corregibles de una consulta tal como están: lo que se guarda en consulta_versiones antes de cada
 * corrección y con lo que se compara después (si no cambió nada, no se consume el desbloqueo).
 */
final class Instantanea
{
    /** @return array<string, mixed> */
    public static function de(Consulta $consulta): array
    {
        $examen = $consulta->examenFisico()->first();

        return [
            'motivo_consulta' => $consulta->motivo_consulta,
            'anamnesis' => $consulta->bloquesAnamnesis()->with('tipoBloqueAnamnesis')->orderBy('id')->get()->map(fn ($bloque) => [
                'id' => $bloque->id, 'tipo_bloque_anamnesis_id' => $bloque->tipo_bloque_anamnesis_id, 'tipo' => $bloque->tipoBloqueAnamnesis?->nombre,
                'contenido' => $bloque->contenido, 'orden' => $bloque->orden, 'activo' => (bool) $bloque->activo,
                'usuario_id' => $bloque->usuario_id, 'modificado_por_id' => $bloque->modificado_por_id,
            ])->all(),
            'hallazgos' => $examen?->hallazgos,
            'hallazgos_usuario_id' => $examen?->hallazgos_usuario_id,
            'diagnosticos' => $consulta->diagnosticos()->orderBy('id')->get()->map(fn ($diagnostico) => [
                'id' => $diagnostico->id, 'codigo_cie10' => $diagnostico->codigo_cie10, 'tipo' => $diagnostico->tipo?->value,
                'principal' => (bool) $diagnostico->principal, 'descripcion_adicional' => $diagnostico->descripcion_adicional, 'activo' => (bool) $diagnostico->activo,
            ])->all(),
            'indicaciones' => $consulta->indicaciones()->with('tipoIndicacion')->orderBy('id')->get()->map(fn ($indicacion) => [
                'id' => $indicacion->id, 'tipo_indicacion_id' => $indicacion->tipo_indicacion_id, 'tipo' => $indicacion->tipoIndicacion?->nombre,
                'descripcion' => $indicacion->descripcion, 'orden' => $indicacion->orden, 'activo' => (bool) $indicacion->activo,
            ])->all(),
            'informes' => $consulta->informesEstudio()->orderBy('id')->get()->map(fn ($informe) => [
                'id' => $informe->id, 'estudio_id' => $informe->estudio_id, 'estudio_nombre' => $informe->estudio_nombre, 'solicitante' => $informe->solicitante,
                'texto' => $informe->texto, 'orden' => $informe->orden, 'activo' => (bool) $informe->activo,
                'usuario_id' => $informe->usuario_id, 'modificado_por_id' => $informe->modificado_por_id,
            ])->all(),
        ];
    }
}
