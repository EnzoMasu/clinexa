<?php

namespace App\Support\Atencion;

use App\Enums\TipoDiagnostico;
use App\Models\CatalogoCIE10;
use App\Models\Consulta;
use App\Models\TipoBloqueAnamnesis;
use App\Models\TipoIndicacion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Los datos de las secciones del formulario de la consulta (consultas/secciones/*), para la pantalla de
 * atención y la de preparación: las filas de cada lista (las del error de validación, o las guardadas),
 * los valores del examen y la autoría. Las filas guardadas llevan uid "g-{id}" (estable); las nuevas, el
 * que les dio el navegador. El autoguardado devuelve el id de cada fila nueva por su uid.
 */
final class DatosFormulario
{
    /** Relaciones que hacen falta (con la autoría). */
    public const RELACIONES = [
        'bloquesAnamnesis.tipoBloqueAnamnesis', 'bloquesAnamnesis.usuario.persona', 'bloquesAnamnesis.modificadoPor.persona',
        'examenFisico.signosUsuario.persona', 'examenFisico.hallazgosUsuario.persona',
        'diagnosticos.cie10', 'indicaciones.tipoIndicacion',
    ];

    /** @return array<string, mixed> */
    public static function de(Consulta $consulta): array
    {
        $consulta->loadMissing(self::RELACIONES);
        $tiposBloque = TipoBloqueAnamnesis::activos()->orderBy('nombre')->pluck('nombre', 'id');
        $tiposIndicacion = TipoIndicacion::activos()->orderBy('nombre')->pluck('nombre', 'id');
        $examen = $consulta->examenFisico;

        return [
            'filasAnamnesis' => self::filasAnamnesis($consulta, $tiposBloque),
            'tiposBloque' => self::opciones($tiposBloque),
            'filasDiagnosticos' => self::filasDiagnosticos($consulta),
            'tiposDiagnostico' => collect(TipoDiagnostico::cases())->map(fn ($tipo) => ['valor' => $tipo->value, 'nombre' => $tipo->etiqueta()])->all(),
            'filasIndicaciones' => self::filasIndicaciones($consulta, $tiposIndicacion),
            'tiposIndicacion' => self::opciones($tiposIndicacion),
            // Coma decimal solo en los números con decimales; el resto (presión, hallazgos) tal cual.
            'valorExamen' => fn (string $campo) => old("examen.{$campo}", in_array($campo, ['temperatura', 'peso', 'talla'], true) ? self::decimalConComa($examen?->{$campo}) : $examen?->{$campo}),
            'autorSignos' => self::autoria($examen?->signosUsuario),
            'autorHallazgos' => self::autoria($examen?->hallazgosUsuario),
        ];
    }

    /** "Cargado por Rosa Benítez" (y ", modificado por …" si lo cambió otra persona), o null. */
    public static function autoria(?User $cargo, ?User $modifico = null): ?string
    {
        if (! $cargo && ! $modifico) {
            return null;
        }

        return trim(($cargo ? 'Cargado por '.self::nombre($cargo) : '')
            .($modifico && $modifico->id !== $cargo?->id ? ($cargo ? ', modificado por ' : 'Modificado por ').self::nombre($modifico) : ''));
    }

    /** Nombre completo desde la persona del usuario. */
    public static function nombre(User $usuario): string
    {
        return $usuario->persona ? trim($usuario->persona->nombres.' '.$usuario->persona->apellidos) : $usuario->name;
    }

    private static function filasAnamnesis(Consulta $consulta, Collection $tiposActivos): array
    {
        $guardados = $consulta->bloquesAnamnesis->keyBy('id');

        return collect(old('con_anamnesis')
                ? (array) old('anamnesis', [])
                : $guardados->sortBy(['orden', 'id'])->map(fn ($bloque) => $bloque->only(['id', 'tipo_bloque_anamnesis_id', 'contenido', 'activo']))->all())
            ->map(function ($fila) use ($guardados, $tiposActivos) {
                $guardado = filled($fila['id'] ?? null) ? $guardados->get((int) $fila['id']) : null;
                $tipo = $guardado?->tipoBloqueAnamnesis;

                return [
                    'uid' => self::uid($fila, $guardado?->id),
                    'id' => $guardado ? (string) $guardado->id : '',
                    'tipo_bloque_anamnesis_id' => (string) ($fila['tipo_bloque_anamnesis_id'] ?? ''),
                    'contenido' => (string) ($fila['contenido'] ?? ''),
                    'activo' => filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'tipoInactivo' => $tipo && ! $tiposActivos->has($tipo->id) ? ['id' => (string) $tipo->id, 'nombre' => $tipo->nombre.' (inactivo)'] : null,
                    'autor' => $guardado ? self::autoria($guardado->usuario, $guardado->modificadoPor) : null,
                    'incompleta' => false,
                ];
            })->values()->all();
    }

    private static function filasDiagnosticos(Consulta $consulta): array
    {
        $guardados = $consulta->diagnosticos->keyBy('id');
        $principal = (string) old('diagnostico_principal');
        $filas = collect(old('con_diagnosticos')
            ? collect((array) old('diagnosticos', []))->map(fn ($fila, $indice) => [...(array) $fila, 'principal' => (string) $indice === $principal])->all()
            : $guardados->sortBy('id')->map(fn ($diagnostico) => [...$diagnostico->only(['id', 'codigo_cie10', 'descripcion_adicional', 'activo', 'principal']), 'tipo' => $diagnostico->tipo->value])->all());
        $descripciones = CatalogoCIE10::whereIn('codigo', $filas->pluck('codigo_cie10')->filter()->unique()->values())->pluck('descripcion', 'codigo');

        return $filas->map(fn ($fila) => [
            'uid' => self::uid($fila, filled($fila['id'] ?? null) && $guardados->has((int) $fila['id']) ? (int) $fila['id'] : null),
            'id' => filled($fila['id'] ?? null) && $guardados->has((int) $fila['id']) ? (string) $fila['id'] : '',
            'codigo_cie10' => (string) ($fila['codigo_cie10'] ?? ''),
            'texto' => filled($fila['codigo_cie10'] ?? null) ? $fila['codigo_cie10'].' — '.($descripciones[$fila['codigo_cie10']] ?? '') : '',
            'tipo' => (string) ($fila['tipo'] ?? TipoDiagnostico::PRESUNTIVO->value),
            'descripcion_adicional' => (string) ($fila['descripcion_adicional'] ?? ''),
            'activo' => filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'principal' => (bool) ($fila['principal'] ?? false),
            'incompleta' => false,
        ])->values()->all();
    }

    private static function filasIndicaciones(Consulta $consulta, Collection $tiposActivos): array
    {
        $guardadas = $consulta->indicaciones->keyBy('id');

        return collect(old('con_indicaciones')
                ? (array) old('indicaciones', [])
                : $guardadas->sortBy(['orden', 'id'])->map(fn ($indicacion) => $indicacion->only(['id', 'tipo_indicacion_id', 'descripcion', 'activo']))->all())
            ->map(function ($fila) use ($guardadas, $tiposActivos) {
                $guardada = filled($fila['id'] ?? null) ? $guardadas->get((int) $fila['id']) : null;
                $tipo = $guardada?->tipoIndicacion;

                return [
                    'uid' => self::uid($fila, $guardada?->id),
                    'id' => $guardada ? (string) $guardada->id : '',
                    'tipo_indicacion_id' => (string) ($fila['tipo_indicacion_id'] ?? ''),
                    'descripcion' => (string) ($fila['descripcion'] ?? ''),
                    'activo' => filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'tipoInactivo' => $tipo && ! $tiposActivos->has($tipo->id) ? ['id' => (string) $tipo->id, 'nombre' => $tipo->nombre.' (inactivo)'] : null,
                    'incompleta' => false,
                ];
            })->values()->all();
    }

    private static function uid(array $fila, ?int $id): string
    {
        return $id ? "g-{$id}" : (string) ($fila['uid'] ?? uniqid('n-', true));
    }

    private static function opciones(Collection $tipos): array
    {
        return $tipos->map(fn ($nombre, $id) => ['id' => (string) $id, 'nombre' => $nombre])->values()->all();
    }

    /** 36.50 -> 36,5; 170.0 -> 170; sin decimales queda igual. */
    private static function decimalConComa(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return str_contains((string) $valor, '.') ? str_replace('.', ',', rtrim(rtrim((string) $valor, '0'), '.')) : (string) $valor;
    }
}
