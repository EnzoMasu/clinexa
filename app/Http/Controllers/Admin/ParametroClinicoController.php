<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ParametroClinico;
use App\Support\Atencion\EvaluadorSignos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Parámetros clínicos (Catálogos): el juego fijo de signos vitales con sus tres bandas. Se miran con VER y se
 * editan y aprueban con EDITAR sobre PARAMETROS_CLINICOS; aprobar además exige ser Profesional ACTIVO. Editar uno
 * aprobado lo devuelve a PENDIENTE (ParametroClinico). No se crean ni se desactivan. Auditado (no es contenido
 * clínico). Los rangos son para adultos (docs/triaje.md).
 */
class ParametroClinicoController extends Controller
{
    public const SOLO_PROFESIONAL = 'Solo un profesional activo puede aprobar los valores de referencia.';

    /** Lo máximo que admite cada signo en el examen físico (las columnas de examenes_fisicos). */
    private const TOPES = ['temperatura' => 999, 'peso' => 999, 'talla' => 9999];

    public function index(Request $request): Response
    {
        return $this->listado($request, 'admin.parametros-clinicos', [
            'parametros' => ParametroClinico::with(['estado', 'aprobadoPor.persona'])->orderBy('id')->get(),
            'faltanAprobados' => EvaluadorSignos::faltanAprobados(),
        ]);
    }

    public function edit(ParametroClinico $parametroClinico): View
    {
        return view('admin.parametros-clinicos.form', ['parametro' => $parametroClinico]);
    }

    public function update(Request $request, ParametroClinico $parametroClinico): RedirectResponse
    {
        $parametroClinico->update($this->validar($request, $parametroClinico));
        $estado = $parametroClinico->fresh()->aprobado() ? '' : ' Queda PENDIENTE: un médico lo tiene que volver a aprobar.';

        return redirect()->route('admin.parametros-clinicos.index')->with('status', "Parámetro {$parametroClinico->nombre} actualizado.{$estado}");
    }

    /** Aprobar: EDITAR sobre el módulo (la ruta) y Profesional ACTIVO; guarda quién y cuándo. */
    public function aprobar(Request $request, ParametroClinico $parametroClinico): RedirectResponse
    {
        abort_unless($request->user()->profesional?->estaActivo(), 403, self::SOLO_PROFESIONAL);
        $parametroClinico->aprobar($request->user());

        return redirect()->route('admin.parametros-clinicos.index')->with('status', "Parámetro {$parametroClinico->nombre} aprobado.");
    }

    /**
     * Las bandas, en pares (ambos o ninguno) y en orden: imposible ⊇ muy alterado ⊇ normal. El límite imposible es
     * obligatorio salvo en la presión (allí rigen el formato y que la sistólica sea mayor que la diastólica).
     */
    private function validar(Request $request, ParametroClinico $parametro): array
    {
        // Coma decimal (36,5) como en el examen físico.
        $request->merge(collect(ParametroClinico::BANDAS)->flatMap(fn ($b) => ["{$b}_min", "{$b}_max"])
            ->mapWithKeys(fn ($campo) => [$campo => is_string($v = $request->input($campo)) ? (trim($v) === '' ? null : str_replace(',', '.', trim($v))) : $v])->all());
        $tope = self::TOPES[$parametro->campo] ?? 99999;
        $numero = ['nullable', 'numeric', 'min:0', "max:{$tope}", 'decimal:0,2'];
        $esPresion = $parametro->campo === 'presion_arterial';

        $datos = $request->validate([
            'unidad' => ['required', 'string', 'max:20'],
            'imposible_min' => [$esPresion ? 'nullable' : 'required', ...array_slice($numero, 1)],
            'imposible_max' => [$esPresion ? 'nullable' : 'required', ...array_slice($numero, 1)],
            'muy_alterado_min' => $numero, 'muy_alterado_max' => $numero,
            'normal_min' => $numero, 'normal_max' => $numero,
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'imposible_min' => 'mínimo imposible', 'imposible_max' => 'máximo imposible',
            'muy_alterado_min' => 'mínimo muy alterado', 'muy_alterado_max' => 'máximo muy alterado',
            'normal_min' => 'mínimo normal', 'normal_max' => 'máximo normal',
        ]);
        if ($error = $this->errorDeBandas($request)) {
            throw ValidationException::withMessages(['bandas' => $error]);
        }

        return array_map(fn ($valor) => $valor === '' ? null : $valor, $datos);
    }

    private function errorDeBandas(Request $request): ?string
    {
        $valor = fn (string $campo) => is_numeric($request->input($campo)) ? (float) $request->input($campo) : null;
        $nombres = ['imposible' => 'imposible', 'muy_alterado' => 'muy alterado', 'normal' => 'normal'];
        $cadena = [];
        foreach ($nombres as $banda => $nombre) {
            [$minimo, $maximo] = [$valor("{$banda}_min"), $valor("{$banda}_max")];
            if (($minimo === null) !== ($maximo === null)) {
                return "Complete el mínimo y el máximo de la banda {$nombre}, o deje los dos vacíos.";
            }
            if ($minimo !== null && $minimo > $maximo) {
                return "En la banda {$nombre}, el mínimo no puede ser mayor que el máximo.";
            }
            if ($minimo !== null) {
                $cadena[$banda] = [$minimo, $maximo];
            }
        }
        // Cada banda contiene a la siguiente: imposible ⊇ muy alterado ⊇ normal.
        $anterior = null;
        foreach ($cadena as $banda => [$minimo, $maximo]) {
            if ($anterior && ($minimo < $anterior[1] || $maximo > $anterior[2])) {
                return "La banda {$nombres[$banda]} tiene que quedar dentro de la banda {$nombres[$anterior[0]]}.";
            }
            $anterior = [$banda, $minimo, $maximo];
        }

        return null;
    }
}
