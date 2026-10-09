<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\DetalleReceta;
use App\Models\Estado;
use App\Models\Receta;
use App\Support\Fecha;
use App\Support\HojaReceta;
use App\Support\MediaHoja;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Recetas de una consulta: borrador (crear, editar), vista previa, emisión, hoja impresa, anulación
 * y "anular y corregir". Quién escribe lo decide RecetaPolicy; la inmutabilidad de una receta emitida
 * o anulada la garantiza además el modelo (Receta, DetalleReceta).
 */
class RecetaController extends Controller
{
    public const MAXIMO_RECETAS = 10;

    public const MINIMO_RENGLONES = 1;

    public const MAXIMO_RENGLONES = 6;

    public const MODIFICADA_EN_OTRA_VENTANA = 'La receta fue modificada desde otra ventana. Vuelva a abrirla.';

    public const VISTA_PREVIA_VIEJA = 'La receta fue modificada desde otra ventana. Vuelva a abrir la vista previa.';

    /** SQLSTATE de clave única repetida (PostgreSQL 23505; SQLite informa 23000). */
    private const CLAVE_REPETIDA = ['23505', '23000'];

    /** Intentos de asignar el número si otra emisión simultánea tomó el mismo. */
    private const INTENTOS_NUMERO = 5;

    public function create(Consulta $consulta): View
    {
        Gate::authorize('create', [Receta::class, $consulta]);

        return $this->formulario(new Receta(['consulta_id' => $consulta->id]), $consulta);
    }

    public function store(Request $request, Consulta $consulta): RedirectResponse
    {
        Gate::authorize('create', [Receta::class, $consulta]);
        $datos = $this->validar($request, null, $consulta);

        $receta = DB::transaction(function () use ($consulta, $datos) {
            $receta = $consulta->recetas()->create(['observaciones' => $datos['observaciones'] ?? null]);
            $this->guardarRenglones($receta, $datos['detalles']);

            return $receta;
        });

        return redirect()->route('admin.recetas.vista-previa', $receta)->with('status', 'Borrador guardado. Revise la vista previa antes de emitir.');
    }

    public function edit(Receta $receta): View|RedirectResponse
    {
        if (! $receta->esBorrador()) {
            return redirect()->route('admin.recetas.imprimir', $receta);
        }
        Gate::authorize('update', $receta);

        return $this->formulario($receta, $receta->consulta);
    }

    public function update(Request $request, Receta $receta): RedirectResponse
    {
        Gate::authorize('update', $receta);

        if ((string) $request->input('version') !== $receta->version()) {
            return back()->withInput()->with('error', self::MODIFICADA_EN_OTRA_VENTANA);
        }

        $datos = $this->validar($request, $receta, $receta->consulta);

        $guardada = DB::transaction(function () use ($receta, $datos) {
            $actual = Receta::whereKey($receta->id)->lockForUpdate()->first();
            if (! $actual->esBorrador() || $actual->version() !== $receta->version()) {
                return false;
            }

            $receta->update(['observaciones' => $datos['observaciones'] ?? null]);
            if (! empty($datos['con_detalles'])) {
                $this->guardarRenglones($receta, $datos['detalles']);
            }
            $receta->touch(); // nueva versión aunque solo hayan cambiado los renglones

            return true;
        });

        if (! $guardada) {
            return back()->withInput()->with('error', self::MODIFICADA_EN_OTRA_VENTANA);
        }

        return redirect()->route('admin.recetas.vista-previa', $receta)->with('status', 'Borrador guardado. Revise la vista previa antes de emitir.');
    }

    /** La hoja del borrador con los datos de hoy, marcada "VISTA PREVIA - NO VÁLIDA". */
    public function vistaPrevia(Receta $receta): View|RedirectResponse
    {
        if (! $receta->esBorrador()) {
            return redirect()->route('admin.recetas.imprimir', $receta);
        }

        $hoja = HojaReceta::datos($receta);

        return view('recetas.hoja', [
            'receta' => $receta,
            'hoja' => $hoja,
            'marca' => 'VISTA PREVIA - NO VÁLIDA',
            'excede' => HojaReceta::excede($hoja),
            'puedeEditar' => Gate::allows('update', $receta),
            'puedeEmitir' => Gate::allows('emitir', $receta),
            'puedeAnular' => Gate::allows('anular', $receta),
            'puedeCorregir' => false,
        ]);
    }

    /**
     * Emite el borrador: en una transacción, con la receta bloqueada, revalida todo y le asigna número,
     * fecha y snapshot. Un segundo envío (doble clic, otra pestaña) encuentra la receta ya emitida y va
     * a su hoja sin emitir de nuevo.
     */
    public function emitir(Request $request, Receta $receta): RedirectResponse
    {
        $resultado = DB::transaction(function () use ($request, $receta) {
            $actual = Receta::whereKey($receta->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('emitir', $actual);

            if ($actual->estaEmitida()) {
                return ['aviso', 'La receta ya estaba emitida.'];
            }
            if (! $actual->esBorrador()) {
                return ['error', 'La receta está anulada: no se puede emitir.'];
            }
            if ((string) $request->input('version') !== $actual->version()) {
                return ['error', self::VISTA_PREVIA_VIEJA];
            }
            if (! $actual->detalles()->exists()) {
                return ['error', 'La receta no tiene medicamentos. Agregue al menos uno.'];
            }

            $hoja = HojaReceta::datos($actual);
            if (HojaReceta::excede($hoja)) {
                return ['error', MediaHoja::EXCEDE];
            }

            $this->asignarNumeroYEmitir($actual, $hoja);

            return ['status', "Receta {$actual->numero} emitida."];
        });

        [$tipo, $mensaje] = $resultado;

        return $tipo === 'error'
            ? redirect()->route($receta->fresh()->esBorrador() ? 'admin.recetas.vista-previa' : 'admin.recetas.imprimir', $receta)->with('error', $mensaje)
            // Recién emitida: la hoja abre el diálogo de impresión ("Emitir e imprimir").
            : redirect()->route('admin.recetas.imprimir', $receta)->with($tipo, $mensaje)->with('imprimir', $tipo === 'status');
    }

    /** La hoja de una receta emitida o anulada, siempre desde el snapshot. Un borrador va a la vista previa. */
    public function imprimir(Receta $receta): View|RedirectResponse
    {
        if ($receta->esBorrador()) {
            return redirect()->route('admin.recetas.vista-previa', $receta);
        }

        return view('recetas.hoja', [
            'receta' => $receta,
            'hoja' => $receta->snapshot,
            'marca' => $receta->estaAnulada() ? 'ANULADA' : null,
            'excede' => HojaReceta::excede($receta->snapshot),
            'puedeEditar' => false,
            'puedeEmitir' => false,
            'puedeAnular' => Gate::allows('anular', $receta),
            'puedeCorregir' => Gate::allows('corregir', $receta),
        ]);
    }

    public function anular(Request $request, Receta $receta): RedirectResponse
    {
        $motivo = $this->motivo($request);

        DB::transaction(function () use ($receta, $motivo) {
            $actual = Receta::whereKey($receta->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('anular', $actual);
            $this->anularReceta($actual, $motivo);
        });

        return redirect()->route('admin.consultas.show', $receta->consulta_id)->with('status', 'Receta anulada.');
    }

    /** Anula una receta emitida y, en la misma transacción, crea el borrador que la reemplaza. */
    public function corregir(Request $request, Receta $receta): RedirectResponse
    {
        $motivo = $this->motivo($request);

        $nueva = DB::transaction(function () use ($receta, $motivo) {
            $actual = Receta::whereKey($receta->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('corregir', $actual);
            $this->anularReceta($actual, $motivo);

            $nueva = Receta::create(['consulta_id' => $actual->consulta_id, 'observaciones' => $actual->observaciones, 'reemplaza_a_id' => $actual->id]);
            $this->guardarRenglones($nueva, $actual->detalles()->orderBy('orden')->orderBy('id')->get()
                ->map(fn (DetalleReceta $detalle) => $detalle->only(array_keys(DetalleReceta::CAMPOS)))->all());

            return $nueva;
        });

        return redirect()->route('admin.recetas.edit', $nueva)->with('status', "Receta {$receta->numero} anulada. Corrija el borrador que la reemplaza y emítalo.");
    }

    private function formulario(Receta $receta, Consulta $consulta): View
    {
        $consulta->loadMissing(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona']);
        if ($receta->exists) {
            $receta->load(['detalles', 'reemplazaA']);
        }

        return view('recetas.form', ['receta' => $receta, 'consulta' => $consulta]);
    }

    /**
     * Valida el borrador. Sin la marca con_detalles (sin JavaScript) los renglones guardados no se tocan;
     * una receta nueva necesita renglones, así que sin JavaScript no se puede crear.
     */
    private function validar(Request $request, ?Receta $receta, Consulta $consulta): array
    {
        $recortar = fn ($valor) => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $request->merge([
            'observaciones' => $recortar($request->input('observaciones')),
            ...(is_array($request->input('detalles')) ? ['detalles' => array_values(array_map(fn ($fila) => is_array($fila) ? array_map($recortar, $fila) : $fila, $request->input('detalles')))] : []),
        ]);

        $conDetalles = $receta === null || $request->boolean('con_detalles');

        $datos = $request->validate([
            'con_detalles' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:300'],
            'detalles' => [$conDetalles ? 'required' : 'nullable', 'array', 'min:'.self::MINIMO_RENGLONES, 'max:'.self::MAXIMO_RENGLONES],
            'detalles.*.id' => ['nullable', 'integer', Rule::exists('detalles_receta', 'id')->where('receta_id', $receta?->id ?? 0)],
            'detalles.*.medicamento' => ['required', 'string', 'max:150'],
            'detalles.*.cantidad' => ['nullable', 'string', 'max:100'],
            'detalles.*.dosis' => ['required', 'string', 'max:100'],
            'detalles.*.via' => ['nullable', 'string', 'max:50'],
            'detalles.*.frecuencia' => ['required', 'string', 'max:100'],
            'detalles.*.duracion' => ['nullable', 'string', 'max:100'],
            'detalles.*.observaciones' => ['nullable', 'string', 'max:200'],
        ], [
            'detalles.required' => 'Agregue al menos un medicamento.',
            'detalles.min' => 'Agregue al menos un medicamento.',
            'detalles.max' => 'Una receta puede tener hasta '.self::MAXIMO_RENGLONES.' medicamentos. Divida la receta en dos.',
            'detalles.*.medicamento.required' => 'Escriba el nombre del medicamento.',
            'detalles.*.dosis.required' => 'Indique la dosis del medicamento.',
            'detalles.*.frecuencia.required' => 'Indique la frecuencia del medicamento.',
            'detalles.*.id.exists' => 'Uno de los medicamentos no pertenece a esta receta.',
        ], [
            'observaciones' => 'observaciones de la receta',
            'detalles.*.medicamento' => 'medicamento',
            'detalles.*.cantidad' => 'cantidad',
            'detalles.*.dosis' => 'dosis',
            'detalles.*.via' => 'vía',
            'detalles.*.frecuencia' => 'frecuencia',
            'detalles.*.duracion' => 'duración',
            'detalles.*.observaciones' => 'observaciones del medicamento',
        ]);

        // Media hoja: con los renglones nuevos (o los guardados, si no vienen) y las indicaciones de hoy.
        $renglones = $conDetalles ? $datos['detalles'] : $receta->detalles()->get()->map->only(array_keys(DetalleReceta::CAMPOS))->all();
        $consulta->loadMissing('indicaciones.tipoIndicacion');
        $indicaciones = $consulta->indicaciones->where('activo', true)->map(fn ($i) => ['tipo' => $i->tipoIndicacion?->nombre, 'texto' => $i->descripcion])->values()->all();
        if (MediaHoja::excede($renglones, $datos['observaciones'] ?? null, $indicaciones, (bool) $receta?->reemplaza_a_id)) {
            throw ValidationException::withMessages(['detalles' => MediaHoja::EXCEDE]);
        }

        return [...$datos, 'detalles' => $datos['detalles'] ?? [], 'con_detalles' => $conDetalles];
    }

    /**
     * Los renglones del borrador en el orden del formulario: actualiza los que vienen con id, crea los
     * nuevos y quita los que ya no están (solo en un borrador; el modelo lo impide en los demás). Queda
     * en la auditoría como un EDITAR de la receta con la lista de antes y la de después.
     */
    private function guardarRenglones(Receta $receta, array $filas): void
    {
        $receta->auditarRelacion('detalles', function () use ($receta, $filas) {
            $guardados = $receta->detalles()->get()->keyBy('id');
            $vigentes = [];

            foreach (array_values($filas) as $posicion => $fila) {
                $valores = [...collect(array_keys(DetalleReceta::CAMPOS))->mapWithKeys(fn ($campo) => [$campo => $fila[$campo] ?? null])->all(), 'orden' => $posicion + 1];
                if (filled($fila['id'] ?? null) && $guardados->has((int) $fila['id'])) {
                    $guardados[(int) $fila['id']]->update($valores);
                    $vigentes[] = (int) $fila['id'];
                } else {
                    $receta->detalles()->create($valores);
                }
            }

            $guardados->except($vigentes)->each->delete();
        });
    }

    /**
     * Número correlativo (RE-0000001), fecha de hoy en hora de Paraguay, estado EMITIDO y snapshot. Si otra
     * emisión simultánea tomó el mismo número, el unique de la base lo rechaza y se reintenta con el
     * siguiente (cada intento en su propio savepoint, para que el rechazo no invalide la transacción).
     */
    private function asignarNumeroYEmitir(Receta $receta, array $hoja): void
    {
        for ($intento = 1; ; $intento++) {
            $numero = Receta::siguienteNumero();
            try {
                DB::transaction(function () use ($receta, $hoja, $numero) {
                    $receta->forceFill([
                        'numero' => $numero,
                        'fecha' => Fecha::hoy()->format('Y-m-d'),
                        'emitida_en' => now(),
                        'estado_id' => Estado::idDe(Estado::EMITIDO),
                        'snapshot' => [...$hoja, 'numero' => $numero],
                    ])->save();
                });

                break;
            } catch (QueryException $e) {
                if ($intento >= self::INTENTOS_NUMERO || ! in_array((string) $e->getCode(), self::CLAVE_REPETIDA, true)) {
                    throw $e;
                }
                $receta->numero = null;
            }
        }

        $receta->unsetRelation('estado');
    }

    private function anularReceta(Receta $receta, string $motivo): void
    {
        $receta->forceFill([
            'estado_id' => Estado::idDe(Estado::ANULADO),
            'anulada_en' => now(),
            'motivo_anulacion' => $motivo,
        ])->save();
        $receta->unsetRelation('estado');
    }

    private function motivo(Request $request): string
    {
        $request->merge(['motivo' => is_string($request->input('motivo')) ? trim($request->input('motivo')) : $request->input('motivo')]);

        return $request->validate(
            ['motivo' => ['required', 'string', 'min:5', 'max:300']],
            ['motivo.required' => 'Indique el motivo de la anulación.', 'motivo.min' => 'El motivo de la anulación debe tener al menos 5 caracteres.'],
            ['motivo' => 'motivo de la anulación'],
        )['motivo'];
    }
}
