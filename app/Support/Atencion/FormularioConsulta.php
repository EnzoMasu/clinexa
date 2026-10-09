<?php

namespace App\Support\Atencion;

use App\Enums\TipoDiagnostico;
use App\Models\CatalogoCIE10;
use App\Models\Consulta;
use App\Models\TipoBloqueAnamnesis;
use App\Models\TipoIndicacion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reglas del formulario de la consulta, en un solo lugar: las FINALES (Finalizar y "Guardar cambios")
 * y las de BORRADOR (autoguardado, más permisivas), separadas por grupo de campos.
 *
 * - PREPARACION: bloques de anamnesis y signos vitales.
 * - CLINICO: motivo, hallazgos del examen, diagnósticos e indicaciones generales.
 *
 * Borrador: se validan formato, largos y rangos, pero ningún campo es obligatorio; las filas
 * incompletas (bloque sin contenido o sin tipo, diagnóstico sin código, indicación sin texto) no se
 * guardan y se informan por su uid ("Fila incompleta: todavía no se guardó"). Final: además, el motivo
 * es obligatorio y entre los diagnósticos vigentes hay exactamente un principal.
 *
 * Los retirados (activo = false) no cuentan para los límites, los repetidos ni el principal.
 */
final class FormularioConsulta
{
    public const PREPARACION = 'preparacion';

    public const CLINICO = 'clinico';

    public const MAXIMO_BLOQUES = 30;

    public const MAXIMO_DIAGNOSTICOS = 10;

    public const MAXIMO_INDICACIONES = 10;

    /** Campos del examen físico de cada grupo: los signos vitales son de la preparación; los hallazgos, clínicos. */
    public const SIGNOS_VITALES = ['presion_arterial', 'frecuencia_cardiaca', 'frecuencia_respiratoria', 'temperatura', 'peso', 'talla', 'saturacion_oxigeno'];

    /** Claves del pedido que son del grupo CLÍNICO (además de examen.hallazgos). */
    public const CLAVES_CLINICAS = ['motivo_consulta', 'con_diagnosticos', 'diagnosticos', 'diagnostico_principal', 'con_indicaciones', 'indicaciones'];

    public const FILA_INCOMPLETA = 'Fila incompleta: todavía no se guardó.';

    /** El pedido trae algún campo del grupo CLÍNICO (aunque venga vacío). */
    public static function traeCamposClinicos(array $entrada): bool
    {
        return array_intersect(self::CLAVES_CLINICAS, array_keys($entrada)) !== []
            || (is_array($entrada['examen'] ?? null) && array_key_exists('hallazgos', $entrada['examen']));
    }

    /**
     * Valida y devuelve [datos, incompletas]. $grupos: los que el usuario puede escribir (el resto del
     * pedido se ignora). $final: reglas finales; si no, de borrador.
     *
     * @param  list<string>  $grupos
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    public static function validar(Request $request, ?Consulta $consulta, array $grupos, bool $final): array
    {
        $entrada = self::normalizar($request->all(), $grupos);
        $incompletas = $final ? [] : self::quitarIncompletas($entrada);
        $request->replace([...$request->except(array_keys($entrada)), ...$entrada]);

        $datos = $request->validate(
            self::reglas($request, $consulta, $grupos, $final),
            self::mensajes(),
            self::atributos(),
        );

        return [$datos, $incompletas];
    }

    /**
     * Recorta los textos, acepta coma decimal en temperatura, peso y talla (36,5 -> 36.5), deja solo los
     * campos de los grupos pedidos y marca como vigente toda fila nueva (no se retira: se descarta).
     */
    private static function normalizar(array $entrada, array $grupos): array
    {
        $recortar = fn ($valor) => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $filas = fn (array $lista) => array_map(fn ($fila) => is_array($fila)
            ? [...array_map($recortar, $fila), ...(filled($fila['id'] ?? null) ? [] : ['activo' => '1'])]
            : $fila, $lista);
        $salida = [];

        $examen = is_array($entrada['examen'] ?? null) ? array_map($recortar, $entrada['examen']) : [];
        foreach (['temperatura', 'peso', 'talla'] as $campo) {
            if (is_string($examen[$campo] ?? null)) {
                $examen[$campo] = str_replace(',', '.', $examen[$campo]);
            }
        }
        $campos = [
            ...(in_array(self::PREPARACION, $grupos, true) ? self::SIGNOS_VITALES : []),
            ...(in_array(self::CLINICO, $grupos, true) ? ['hallazgos'] : []),
        ];
        $salida['examen'] = array_intersect_key($examen, array_flip($campos));

        if (in_array(self::PREPARACION, $grupos, true)) {
            $salida['con_anamnesis'] = $entrada['con_anamnesis'] ?? null;
            if (is_array($entrada['anamnesis'] ?? null)) {
                $salida['anamnesis'] = $filas($entrada['anamnesis']);
            }
        }

        if (in_array(self::CLINICO, $grupos, true)) {
            $salida['motivo_consulta'] = $recortar($entrada['motivo_consulta'] ?? null);
            $salida['con_diagnosticos'] = $entrada['con_diagnosticos'] ?? null;
            $salida['diagnostico_principal'] = $entrada['diagnostico_principal'] ?? null;
            $salida['con_indicaciones'] = $entrada['con_indicaciones'] ?? null;
            foreach (['diagnosticos', 'indicaciones'] as $lista) {
                if (is_array($entrada[$lista] ?? null)) {
                    $salida[$lista] = $filas($entrada[$lista]);
                }
            }
        }

        return $salida;
    }

    /**
     * Borrador: saca las filas incompletas (se conservan las claves, así el principal sigue apuntando a la
     * fila marcada) y devuelve sus uid por lista.
     *
     * @return array<string, list<string>>
     */
    private static function quitarIncompletas(array &$entrada): array
    {
        $incompleta = [
            'anamnesis' => fn (array $fila) => ! filled($fila['contenido'] ?? null) || ! filled($fila['tipo_bloque_anamnesis_id'] ?? null),
            'diagnosticos' => fn (array $fila) => ! filled($fila['codigo_cie10'] ?? null) || ! filled($fila['tipo'] ?? null),
            'indicaciones' => fn (array $fila) => ! filled($fila['descripcion'] ?? null),
        ];
        $incompletas = [];

        foreach ($incompleta as $lista => $esIncompleta) {
            if (! is_array($entrada[$lista] ?? null)) {
                continue;
            }
            foreach ($entrada[$lista] as $indice => $fila) {
                if (is_array($fila) && $esIncompleta($fila)) {
                    $incompletas[$lista][] = (string) ($fila['uid'] ?? $indice);
                    unset($entrada[$lista][$indice]);
                }
            }
        }

        return $incompletas;
    }

    private static function reglas(Request $request, ?Consulta $consulta, array $grupos, bool $final): array
    {
        $guardados = [
            'anamnesis' => $consulta?->bloquesAnamnesis()->get()->keyBy('id') ?? collect(),
            'diagnosticos' => $consulta?->diagnosticos()->get()->keyBy('id') ?? collect(),
            'indicaciones' => $consulta?->indicaciones()->get()->keyBy('id') ?? collect(),
        ];
        // El tipo (o el código) que la fila ya tenía guardado: vale aunque hoy esté inactivo.
        $original = function (string $atributo, string $lista, string $campo) use ($request, $guardados): mixed {
            $indice = explode('.', $atributo)[1] ?? null;
            $id = $request->input("{$lista}.{$indice}.id");

            return filled($id) ? $guardados[$lista]->get((int) $id)?->{$campo} : null;
        };
        $activas = fn (mixed $filas) => collect(is_array($filas) ? $filas : [])
            ->filter(fn ($fila) => is_array($fila) && filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN));
        $deEstaConsulta = fn (string $tabla) => Rule::exists($tabla, 'id')->where('consulta_id', $consulta?->id ?? 0);

        $reglas = ['examen' => ['nullable', 'array']];

        if (in_array(self::PREPARACION, $grupos, true)) {
            $reglas += [
                'con_anamnesis' => ['nullable', 'boolean'],
                'anamnesis' => ['nullable', 'array', function (string $atributo, mixed $filas, Closure $fail) use ($activas) {
                    if ($activas($filas)->count() > self::MAXIMO_BLOQUES) {
                        $fail('La anamnesis puede tener hasta '.self::MAXIMO_BLOQUES.' bloques vigentes. Retire o descarte alguno.');
                    }
                }],
                'anamnesis.*.id' => ['nullable', 'integer', $deEstaConsulta('bloques_anamnesis')],
                'anamnesis.*.activo' => ['nullable', 'boolean'],
                'anamnesis.*.tipo_bloque_anamnesis_id' => ['required', 'integer', function (string $atributo, mixed $valor, Closure $fail) use ($original) {
                    if (! TipoBloqueAnamnesis::activos()->whereKey((int) $valor)->exists() && (int) $original($atributo, 'anamnesis', 'tipo_bloque_anamnesis_id') !== (int) $valor) {
                        $fail('Elija un tipo de bloque activo.');
                    }
                }],
                'anamnesis.*.contenido' => ['required', 'string', 'max:5000'],
                'examen.presion_arterial' => ['nullable', 'string', 'max:10', 'regex:#^\d{2,3}/\d{2,3}$#'],
                'examen.frecuencia_cardiaca' => ['nullable', 'integer', 'between:20,300'],
                'examen.frecuencia_respiratoria' => ['nullable', 'integer', 'between:5,80'],
                'examen.temperatura' => ['nullable', 'numeric', 'decimal:0,1', 'between:30,45'],
                'examen.peso' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:500'],
                'examen.talla' => ['nullable', 'numeric', 'decimal:0,1', 'between:20,250'],
                'examen.saturacion_oxigeno' => ['nullable', 'integer', 'between:0,100'],
            ];
        }

        if (in_array(self::CLINICO, $grupos, true)) {
            $reglas += [
                'motivo_consulta' => [$final ? 'required' : 'nullable', 'string', 'max:500'],
                'examen.hallazgos' => ['nullable', 'string', 'max:2000'],
                'con_diagnosticos' => ['nullable', 'boolean'],
                'diagnostico_principal' => ['nullable'],
                'diagnosticos' => ['nullable', 'array', function (string $atributo, mixed $filas, Closure $fail) use ($activas, $request, $final) {
                    $vigentes = $activas($filas);
                    if ($vigentes->count() > self::MAXIMO_DIAGNOSTICOS) {
                        $fail('La consulta puede tener hasta '.self::MAXIMO_DIAGNOSTICOS.' diagnósticos vigentes. Retire o descarte alguno.');

                        return;
                    }

                    $repetidos = $vigentes->pluck('codigo_cie10')->filter()->map(fn ($codigo) => mb_strtoupper((string) $codigo))->duplicates();
                    if ($repetidos->isNotEmpty()) {
                        $fail('El código '.$repetidos->first().' está repetido entre los diagnósticos vigentes.');
                    }

                    $principal = (string) $request->input('diagnostico_principal');
                    if ($final && $vigentes->isNotEmpty() && ! $vigentes->keys()->map(fn ($indice) => (string) $indice)->contains($principal)) {
                        $fail('Marque cuál de los diagnósticos vigentes es el principal.');
                    }
                }],
                'diagnosticos.*.id' => ['nullable', 'integer', $deEstaConsulta('diagnosticos')],
                'diagnosticos.*.activo' => ['nullable', 'boolean'],
                'diagnosticos.*.codigo_cie10' => ['required', 'string', 'max:10', function (string $atributo, mixed $valor, Closure $fail) use ($original) {
                    if (! CatalogoCIE10::activos()->whereKey((string) $valor)->exists() && $original($atributo, 'diagnosticos', 'codigo_cie10') !== (string) $valor) {
                        $fail('El código CIE-10 no existe o está inactivo. Elíjalo con el buscador.');
                    }
                }],
                'diagnosticos.*.tipo' => ['required', Rule::enum(TipoDiagnostico::class)],
                'diagnosticos.*.descripcion_adicional' => ['nullable', 'string', 'max:500'],
                'con_indicaciones' => ['nullable', 'boolean'],
                'indicaciones' => ['nullable', 'array', function (string $atributo, mixed $filas, Closure $fail) use ($activas) {
                    if ($activas($filas)->count() > self::MAXIMO_INDICACIONES) {
                        $fail('La consulta puede tener hasta '.self::MAXIMO_INDICACIONES.' indicaciones generales vigentes. Retire o descarte alguna.');
                    }
                }],
                'indicaciones.*.id' => ['nullable', 'integer', $deEstaConsulta('indicaciones')],
                'indicaciones.*.activo' => ['nullable', 'boolean'],
                'indicaciones.*.tipo_indicacion_id' => ['nullable', 'integer', function (string $atributo, mixed $valor, Closure $fail) use ($original) {
                    if (! TipoIndicacion::activos()->whereKey((int) $valor)->exists() && (int) $original($atributo, 'indicaciones', 'tipo_indicacion_id') !== (int) $valor) {
                        $fail('Elija un tipo de indicación activo, o déjelo sin tipo.');
                    }
                }],
                'indicaciones.*.descripcion' => ['required', 'string', 'max:500'],
            ];
        }

        return $reglas;
    }

    private static function mensajes(): array
    {
        return [
            'motivo_consulta.required' => 'Escriba el motivo de consulta para finalizar.',
            'anamnesis.*.contenido.required' => 'Escriba el contenido del bloque de anamnesis.',
            'anamnesis.*.tipo_bloque_anamnesis_id.required' => 'Elija el tipo del bloque de anamnesis.',
            'examen.presion_arterial.regex' => 'La presión arterial debe tener el formato 120/80.',
            'examen.temperatura.decimal' => 'La temperatura admite un solo decimal.',
            'examen.peso.decimal' => 'El peso admite hasta dos decimales.',
            'examen.talla.decimal' => 'La talla admite un solo decimal.',
            'examen.peso.gt' => 'El peso debe ser mayor que 0.',
            'examen.peso.max' => 'El peso no puede superar los 500 kg.',
            'diagnosticos.*.codigo_cie10.required' => 'Elija el código CIE-10 del diagnóstico con el buscador.',
            'diagnosticos.*.tipo.required' => 'Indique si el diagnóstico es presuntivo o confirmado.',
            'anamnesis.*.id.exists' => 'Uno de los bloques de anamnesis no pertenece a esta consulta.',
            'diagnosticos.*.id.exists' => 'Uno de los diagnósticos no pertenece a esta consulta.',
            'indicaciones.*.descripcion.required' => 'Escriba el texto de la indicación general.',
            'indicaciones.*.id.exists' => 'Una de las indicaciones generales no pertenece a esta consulta.',
        ];
    }

    private static function atributos(): array
    {
        return [
            'motivo_consulta' => 'motivo de consulta',
            'anamnesis.*.contenido' => 'contenido del bloque',
            'anamnesis.*.tipo_bloque_anamnesis_id' => 'tipo de bloque',
            'examen.presion_arterial' => 'presión arterial',
            'examen.frecuencia_cardiaca' => 'frecuencia cardíaca',
            'examen.frecuencia_respiratoria' => 'frecuencia respiratoria',
            'examen.temperatura' => 'temperatura',
            'examen.peso' => 'peso',
            'examen.talla' => 'talla',
            'examen.saturacion_oxigeno' => 'saturación de oxígeno',
            'examen.hallazgos' => 'hallazgos',
            'diagnosticos.*.codigo_cie10' => 'código CIE-10',
            'diagnosticos.*.tipo' => 'tipo de diagnóstico',
            'diagnosticos.*.descripcion_adicional' => 'descripción adicional',
            'indicaciones.*.descripcion' => 'texto de la indicación',
            'indicaciones.*.tipo_indicacion_id' => 'tipo de indicación',
        ];
    }
}
