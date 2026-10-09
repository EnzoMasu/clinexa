<?php

namespace App\Support\Atencion;

use App\Models\Consulta;
use App\Models\ExamenFisico;
use App\Models\User;

/**
 * Guarda las secciones de una consulta con los datos ya validados (FormularioConsulta), solo las de los
 * grupos que el usuario puede escribir. Cada sección queda en la auditoría como EDITAR de la consulta,
 * con la lista de antes y la de después (si cambió). Nada se borra: las filas se retiran.
 *
 * Autoría: cada bloque de anamnesis guarda quién lo creó (usuario_id) y el último que cambió su contenido
 * (modificado_por_id); el examen guarda quién cargó los signos vitales y quién los hallazgos.
 *
 * Devuelve el id de cada fila nueva por su uid (el autoguardado lo necesita para no volver a crearla).
 */
final class GuardarSecciones
{
    /**
     * @param  list<string>  $grupos
     * @return array<string, array<string, int>> lista => [uid => id] de las filas creadas
     */
    public static function guardar(Consulta $consulta, array $datos, User $usuario, array $grupos): array
    {
        $creadas = [];
        $preparacion = in_array(FormularioConsulta::PREPARACION, $grupos, true);
        $clinico = in_array(FormularioConsulta::CLINICO, $grupos, true);

        if ($clinico && array_key_exists('motivo_consulta', $datos)) {
            $consulta->update(['motivo_consulta' => $datos['motivo_consulta']]);
        }

        if ($preparacion && ! empty($datos['con_anamnesis'])) {
            $consulta->auditarRelacion('bloquesAnamnesis', function () use ($consulta, $datos, $usuario, &$creadas) {
                $creadas['anamnesis'] = self::guardarFilas($consulta->bloquesAnamnesis(), $datos['anamnesis'] ?? [],
                    fn (array $fila) => ['tipo_bloque_anamnesis_id' => (int) $fila['tipo_bloque_anamnesis_id'], 'contenido' => $fila['contenido']],
                    nuevas: ['usuario_id' => $usuario->id], cambiadas: ['modificado_por_id' => $usuario->id]);
            });
        }

        $campos = [...($preparacion ? FormularioConsulta::SIGNOS_VITALES : []), ...($clinico ? ['hallazgos'] : [])];
        if ($campos !== []) {
            $consulta->auditarRelacion('examenFisico', fn () => self::guardarExamen($consulta, $datos['examen'] ?? [], $campos, $usuario));
        }

        if ($clinico && ! empty($datos['con_diagnosticos'])) {
            $principal = (string) ($datos['diagnostico_principal'] ?? '');
            $consulta->auditarRelacion('diagnosticos', function () use ($consulta, $datos, $principal, &$creadas) {
                $guardados = $consulta->diagnosticos()->get()->keyBy('id');
                foreach ($datos['diagnosticos'] ?? [] as $indice => $fila) {
                    $nueva = ! filled($fila['id'] ?? null);
                    $activo = $nueva || filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    $valores = [
                        'codigo_cie10' => (string) $fila['codigo_cie10'],
                        'tipo' => $fila['tipo'],
                        'descripcion_adicional' => $fila['descripcion_adicional'] ?? null,
                        'activo' => $activo,
                        'principal' => $activo && (string) $indice === $principal,
                    ];
                    if ($nueva) {
                        $creadas['diagnosticos'][(string) ($fila['uid'] ?? $indice)] = $consulta->diagnosticos()->create($valores)->id;
                    } else {
                        $guardados[(int) $fila['id']]->update($valores);
                    }
                }
            });
        }

        if ($clinico && ! empty($datos['con_indicaciones'])) {
            $consulta->auditarRelacion('indicaciones', function () use ($consulta, $datos, &$creadas) {
                $creadas['indicaciones'] = self::guardarFilas($consulta->indicaciones(), $datos['indicaciones'] ?? [],
                    fn (array $fila) => ['tipo_indicacion_id' => filled($fila['tipo_indicacion_id'] ?? null) ? (int) $fila['tipo_indicacion_id'] : null, 'descripcion' => $fila['descripcion']]);
            });
        }

        return array_filter($creadas);
    }

    /**
     * Filas con orden (anamnesis, indicaciones): actualiza o crea en el orden del formulario; nunca borra
     * (las que no vienen quedan como estaban). $nuevas se suma al crear; $cambiadas, al cambiar el contenido.
     *
     * @return array<string, int> uid => id de las creadas
     */
    private static function guardarFilas($relacion, array $filas, \Closure $valores, array $nuevas = [], array $cambiadas = []): array
    {
        $guardadas = $relacion->get()->keyBy('id');
        $creadas = [];

        foreach (array_values($filas) as $posicion => $fila) {
            $contenido = $valores($fila);
            if (filled($fila['id'] ?? null)) {
                $existente = $guardadas[(int) $fila['id']];
                $existente->fill([...$contenido, 'orden' => $posicion + 1, 'activo' => filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN)]);
                if (array_intersect(array_keys($existente->getDirty()), [...array_keys($contenido), 'activo']) !== []) {
                    $existente->fill($cambiadas);
                }
                $existente->save();
            } else {
                $creadas[(string) ($fila['uid'] ?? $posicion)] = $relacion->create([...$contenido, ...$nuevas, 'orden' => $posicion + 1])->id;
            }
        }

        return $creadas;
    }

    /**
     * El examen se crea solo si se cargó algún campo; si ya existe, se actualizan solo los campos del grupo.
     * Quién cargó los signos y quién los hallazgos se actualiza cuando cambian.
     */
    private static function guardarExamen(Consulta $consulta, array $examen, array $campos, User $usuario): void
    {
        $valores = collect($campos)->mapWithKeys(fn (string $campo) => [$campo => $examen[$campo] ?? null])->all();
        $existente = $consulta->examenFisico()->first();

        if (! $existente) {
            if (array_filter($valores, fn ($valor) => $valor !== null) === []) {
                return;
            }
            $existente = new ExamenFisico(['consulta_id' => $consulta->id]);
        }

        $existente->fill($valores);
        $cambios = array_keys($existente->exists ? $existente->getDirty() : array_filter($valores, fn ($valor) => $valor !== null));
        if (array_intersect($cambios, FormularioConsulta::SIGNOS_VITALES) !== []) {
            $existente->signos_usuario_id = $usuario->id;
        }
        if (in_array('hallazgos', $cambios, true)) {
            $existente->hallazgos_usuario_id = $usuario->id;
        }
        $existente->save();
    }
}
