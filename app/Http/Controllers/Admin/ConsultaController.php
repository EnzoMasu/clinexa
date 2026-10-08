<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccionAuditoria;
use App\Enums\TipoDiagnostico;
use App\Http\Controllers\Controller;
use App\Models\CatalogoCIE10;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\ExamenFisico;
use App\Models\HistoriaClinica;
use App\Models\LogAuditoria;
use App\Models\TipoBloqueAnamnesis;
use App\Models\Turno;
use App\Support\Auditoria;
use App\Support\BuscadorPersonas;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Consultas de la historia clínica: alta (desde "Atender" de un turno o "Atender sin turno"),
 * lectura y edición, en un único formulario. Quién crea y quién modifica lo decide ConsultaPolicy.
 *
 * Anamnesis y diagnósticos son listas de filas (Alpine): nunca se borran, se retiran (activo =
 * false) y se reponen. Los retirados no cuentan para los límites, los repetidos ni el principal.
 */
class ConsultaController extends Controller
{
    public const MAXIMO_BLOQUES = 30;

    public const MAXIMO_DIAGNOSTICOS = 10;

    public const MODIFICADA_EN_OTRA_VENTANA = 'La consulta fue modificada desde otra ventana. Recargue la página.';

    /** SQLSTATE de clave única repetida (PostgreSQL 23505; SQLite informa 23000). */
    private const CLAVE_REPETIDA = ['23505', '23000'];

    public function create(Request $request, HistoriaClinica $historiaClinica): View
    {
        $turno = $this->turnoPedido($request->query('turno'));
        Gate::authorize('create', [Consulta::class, $historiaClinica, $turno]);

        return $this->formulario(new Consulta(['historia_clinica_id' => $historiaClinica->id]), $historiaClinica, $turno);
    }

    public function store(Request $request, HistoriaClinica $historiaClinica): RedirectResponse
    {
        $turno = $this->turnoPedido($request->input('turno_id'));
        Gate::authorize('create', [Consulta::class, $historiaClinica, $turno]);

        $datos = $this->validar($request, null);
        $profesionalId = $request->user()->profesional->id;

        try {
            $consulta = DB::transaction(function () use ($historiaClinica, $turno, $datos, $profesionalId) {
                if ($turno) {
                    // Se revalida dentro de la transacción, con el turno bloqueado: puede haberse
                    // cancelado o atendido desde otra ventana mientras se cargaba la consulta.
                    $actual = Turno::whereKey($turno->id)->lockForUpdate()->first();
                    if (! $actual->tieneEstado(Estado::CONFIRMADO) || $actual->consulta()->exists()) {
                        return null;
                    }
                }

                $consulta = Consulta::create([
                    'historia_clinica_id' => $historiaClinica->id,
                    'turno_id' => $turno?->id,
                    'profesional_id' => $profesionalId,
                    'motivo_consulta' => $datos['motivo_consulta'],
                ]);
                $this->guardarSecciones($consulta, $datos);

                // ATENDIDO solo se alcanza así: guardando la consulta del turno.
                $turno?->update(['estado_id' => Estado::idDe(Estado::ATENDIDO)]);

                return $consulta;
            });
        } catch (QueryException $e) {
            // Dos envíos simultáneos del mismo turno: el segundo choca con el unique de turno_id.
            if ($turno && in_array((string) $e->getCode(), self::CLAVE_REPETIDA, true)) {
                $consulta = null;
            } else {
                throw $e;
            }
        }

        if (! $consulta) {
            return back()->withInput()->with('error',
                'No se guardó la consulta: el turno ya no está confirmado o ya tiene una consulta (pudo cambiar desde otra ventana). Revise el turno; lo cargado sigue en el formulario.');
        }

        return redirect()->route('admin.consultas.show', $consulta)->with('status', 'Consulta guardada.');
    }

    public function show(Request $request, Consulta $consulta): View
    {
        $consulta->load([
            'historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona', 'turno',
            'bloquesAnamnesis.tipoBloqueAnamnesis', 'examenFisico', 'diagnosticos.cie10',
        ]);

        // Historial de cambios: solo con VER sobre AUDITORIA (además de HISTORIA_CLINICA, que pide la ruta).
        $historial = null;
        if ($request->user()->tienePermiso('AUDITORIA', 'VER')) {
            $historial = LogAuditoria::query()
                ->with('usuario.persona')
                ->where('tabla_afectada', 'consultas')
                ->where('registro_afectado_id', (string) $consulta->id)
                ->whereIn('accion', [AccionAuditoria::CREAR->value, AccionAuditoria::EDITAR->value])
                ->orderBy('fecha_hora')
                ->orderBy('id')
                ->get();
            // Ver el historial es leer el log de auditoría: queda registrado como un VER de AUDITORIA
            // (con qué consulta), con la misma regla anti-ruido que las demás lecturas.
            Auditoria::registrarLectura('AUDITORIA', null, detalle: "Historial de cambios de la consulta {$consulta->id}");
        }

        return view('admin.consultas.show', [
            'consulta' => $consulta,
            'puedeModificar' => Gate::allows('update', $consulta),
            'historial' => $historial,
        ]);
    }

    public function edit(Consulta $consulta): View
    {
        Gate::authorize('update', $consulta);

        return $this->formulario($consulta, $consulta->historiaClinica, $consulta->turno);
    }

    public function update(Request $request, Consulta $consulta): RedirectResponse
    {
        Gate::authorize('update', $consulta);

        if ((string) $request->input('version') !== $consulta->version()) {
            return back()->withInput()->with('error', self::MODIFICADA_EN_OTRA_VENTANA);
        }

        $datos = $this->validar($request, $consulta);

        $guardada = DB::transaction(function () use ($consulta, $datos) {
            // Mismo control, con la fila bloqueada: otra pestaña pudo guardar entre la comparación y acá.
            if (Consulta::whereKey($consulta->id)->lockForUpdate()->first()->version() !== $consulta->version()) {
                return false;
            }

            $consulta->update(['motivo_consulta' => $datos['motivo_consulta']]);
            $this->guardarSecciones($consulta, $datos);
            $consulta->touch(); // nueva versión aunque solo hayan cambiado las secciones

            return true;
        });

        if (! $guardada) {
            return back()->withInput()->with('error', self::MODIFICADA_EN_OTRA_VENTANA);
        }

        return redirect()->route('admin.consultas.show', $consulta)->with('status', 'Consulta actualizada.');
    }

    /**
     * Buscador de CIE-10 del formulario: solo códigos ACTIVOS, por código o descripción, hasta 15.
     * Exige CREAR o EDITAR sobre HISTORIA_CLINICA (no permisos sobre el catálogo CIE-10).
     */
    public function cie10(Request $request): JsonResponse
    {
        abort_unless($request->user()->tienePermiso('HISTORIA_CLINICA', 'CREAR') || $request->user()->tienePermiso('HISTORIA_CLINICA', 'EDITAR'), 403,
            'No tiene permiso para acceder a esta sección.');

        $busqueda = trim((string) $request->query('q'));
        if (mb_strlen($busqueda) < BuscadorPersonas::MINIMO) {
            return response()->json([]);
        }

        return response()->json(CatalogoCIE10::activos()
            ->where(fn ($query) => $query->whereLike('codigo', "{$busqueda}%")->orWhereLike('descripcion', "%{$busqueda}%"))
            ->orderBy('codigo')
            ->limit(BuscadorPersonas::LIMITE)
            ->get(['codigo', 'descripcion'])
            ->map(fn (CatalogoCIE10 $cie10) => ['codigo' => $cie10->codigo, 'descripcion' => $cie10->descripcion]));
    }

    private function formulario(Consulta $consulta, HistoriaClinica $historia, ?Turno $turno): View
    {
        $historia->loadMissing('paciente.persona.tipoDocumento');
        if ($consulta->exists) {
            $consulta->load(['bloquesAnamnesis.tipoBloqueAnamnesis', 'examenFisico', 'diagnosticos.cie10', 'profesional.persona']);
        }

        return view('admin.consultas.form', [
            'consulta' => $consulta,
            'historia' => $historia,
            'turno' => $turno,
            'profesional' => $consulta->exists ? $consulta->profesional : request()->user()->profesional->load('persona'),
            'tiposBloqueActivos' => TipoBloqueAnamnesis::activos()->orderBy('nombre')->pluck('nombre', 'id'),
        ]);
    }

    /** El turno de "Atender" (de la URL o del campo oculto), o null en una urgencia sin turno. */
    private function turnoPedido(mixed $id): ?Turno
    {
        if ($id === null || $id === '') {
            return null;
        }
        abort_unless(ctype_digit((string) $id), 404);

        return Turno::with('consulta')->findOrFail((int) $id);
    }

    /**
     * Valida el formulario completo. Lo que no se toca: una lista sin su marca (con_anamnesis,
     * con_diagnosticos), que es lo que pasa sin JavaScript.
     */
    private function validar(Request $request, ?Consulta $consulta): array
    {
        $request->merge($this->normalizar($request->all()));

        $guardados = $consulta
            ? ['anamnesis' => $consulta->bloquesAnamnesis()->get()->keyBy('id'), 'diagnosticos' => $consulta->diagnosticos()->get()->keyBy('id')]
            : ['anamnesis' => collect(), 'diagnosticos' => collect()];

        // El tipo (o el código) que la fila ya tenía guardado: vale aunque hoy esté inactivo.
        $original = function (string $atributo, string $lista, string $campo) use ($request, $guardados): mixed {
            $indice = explode('.', $atributo)[1] ?? null;
            $id = $request->input("{$lista}.{$indice}.id");

            return filled($id) ? $guardados[$lista]->get((int) $id)?->{$campo} : null;
        };

        $activas = fn (mixed $filas) => collect(is_array($filas) ? $filas : [])
            ->filter(fn ($fila) => is_array($fila) && filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN));

        return $request->validate([
            'motivo_consulta' => ['required', 'string', 'max:500'],

            'con_anamnesis' => ['nullable', 'boolean'],
            'anamnesis' => ['nullable', 'array', function (string $atributo, mixed $filas, Closure $fail) use ($activas) {
                if ($activas($filas)->count() > self::MAXIMO_BLOQUES) {
                    $fail('La anamnesis puede tener hasta '.self::MAXIMO_BLOQUES.' bloques vigentes. Retire o descarte alguno.');
                }
            }],
            'anamnesis.*.id' => ['nullable', 'integer', Rule::exists('bloques_anamnesis', 'id')->where('consulta_id', $consulta?->id ?? 0)],
            'anamnesis.*.activo' => ['nullable', 'boolean'],
            'anamnesis.*.tipo_bloque_anamnesis_id' => ['required', 'integer', function (string $atributo, mixed $valor, Closure $fail) use ($original) {
                $activo = TipoBloqueAnamnesis::activos()->whereKey((int) $valor)->exists();
                if (! $activo && (int) $original($atributo, 'anamnesis', 'tipo_bloque_anamnesis_id') !== (int) $valor) {
                    $fail('Elija un tipo de bloque activo.');
                }
            }],
            'anamnesis.*.contenido' => ['required', 'string', 'max:5000'],

            'examen' => ['nullable', 'array'],
            'examen.presion_arterial' => ['nullable', 'string', 'max:10', 'regex:#^\d{2,3}/\d{2,3}$#'],
            'examen.frecuencia_cardiaca' => ['nullable', 'integer', 'between:20,300'],
            'examen.frecuencia_respiratoria' => ['nullable', 'integer', 'between:5,80'],
            'examen.temperatura' => ['nullable', 'numeric', 'decimal:0,1', 'between:30,45'],
            'examen.peso' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:500'],
            'examen.talla' => ['nullable', 'numeric', 'decimal:0,1', 'between:20,250'],
            'examen.saturacion_oxigeno' => ['nullable', 'integer', 'between:0,100'],
            'examen.hallazgos' => ['nullable', 'string', 'max:2000'],

            'con_diagnosticos' => ['nullable', 'boolean'],
            'diagnostico_principal' => ['nullable'],
            'diagnosticos' => ['nullable', 'array', function (string $atributo, mixed $filas, Closure $fail) use ($activas, $request) {
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
                if ($vigentes->isNotEmpty() && ! $vigentes->keys()->map(fn ($indice) => (string) $indice)->contains($principal)) {
                    $fail('Marque cuál de los diagnósticos vigentes es el principal.');
                }
            }],
            'diagnosticos.*.id' => ['nullable', 'integer', Rule::exists('diagnosticos', 'id')->where('consulta_id', $consulta?->id ?? 0)],
            'diagnosticos.*.activo' => ['nullable', 'boolean'],
            'diagnosticos.*.codigo_cie10' => ['required', 'string', 'max:10', function (string $atributo, mixed $valor, Closure $fail) use ($original) {
                $activo = CatalogoCIE10::activos()->whereKey((string) $valor)->exists();
                if (! $activo && $original($atributo, 'diagnosticos', 'codigo_cie10') !== (string) $valor) {
                    $fail('El código CIE-10 no existe o está inactivo. Elíjalo con el buscador.');
                }
            }],
            'diagnosticos.*.tipo' => ['required', Rule::enum(TipoDiagnostico::class)],
            'diagnosticos.*.descripcion_adicional' => ['nullable', 'string', 'max:500'],
        ], [
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
        ], [
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
        ]);
    }

    /** Recorta los textos y acepta coma decimal en temperatura, peso y talla (36,5 -> 36.5). */
    private function normalizar(array $entrada): array
    {
        $recortar = fn ($valor) => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;

        $salida = ['motivo_consulta' => $recortar($entrada['motivo_consulta'] ?? null)];

        if (is_array($entrada['examen'] ?? null)) {
            $salida['examen'] = array_map($recortar, $entrada['examen']);
            foreach (['temperatura', 'peso', 'talla'] as $campo) {
                if (is_string($salida['examen'][$campo] ?? null)) {
                    $salida['examen'][$campo] = str_replace(',', '.', $salida['examen'][$campo]);
                }
            }
        }

        foreach (['anamnesis', 'diagnosticos'] as $lista) {
            if (is_array($entrada[$lista] ?? null)) {
                // Una fila sin guardar siempre es vigente (no se retira: se descarta).
                $salida[$lista] = array_map(fn ($fila) => is_array($fila)
                    ? [...array_map($recortar, $fila), ...(filled($fila['id'] ?? null) ? [] : ['activo' => '1'])]
                    : $fila, $entrada[$lista]);
            }
        }

        return $salida;
    }

    /**
     * Anamnesis, examen físico y diagnósticos. Cada uno queda en la auditoría como EDITAR de la
     * consulta, con la lista de antes y la de después (si cambió).
     */
    private function guardarSecciones(Consulta $consulta, array $datos): void
    {
        if (! empty($datos['con_anamnesis'])) {
            $consulta->auditarRelacion('bloquesAnamnesis', fn () => $this->guardarAnamnesis($consulta, $datos['anamnesis'] ?? []));
        }

        $consulta->auditarRelacion('examenFisico', fn () => $this->guardarExamen($consulta, $datos['examen'] ?? []));

        if (! empty($datos['con_diagnosticos'])) {
            $principal = (string) ($datos['diagnostico_principal'] ?? '');
            $consulta->auditarRelacion('diagnosticos', fn () => $this->guardarDiagnosticos($consulta, $datos['diagnosticos'] ?? [], $principal));
        }
    }

    /** Actualiza o crea los bloques en el orden del formulario. Nunca borra: los que no vienen quedan como estaban. */
    private function guardarAnamnesis(Consulta $consulta, array $filas): void
    {
        $guardados = $consulta->bloquesAnamnesis()->get()->keyBy('id');

        foreach (array_values($filas) as $posicion => $fila) {
            $valores = [
                'tipo_bloque_anamnesis_id' => (int) $fila['tipo_bloque_anamnesis_id'],
                'contenido' => $fila['contenido'],
                'orden' => $posicion + 1,
            ];

            if (filled($fila['id'] ?? null)) {
                $guardados[(int) $fila['id']]->update([...$valores, 'activo' => filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN)]);
            } else {
                $consulta->bloquesAnamnesis()->create($valores); // una fila nueva nace activa
            }
        }
    }

    /** El examen se crea solo si se cargó algún campo; si ya existe, se actualiza (también a vacío). */
    private function guardarExamen(Consulta $consulta, array $campos): void
    {
        $valores = collect(array_keys(ExamenFisico::CAMPOS))->mapWithKeys(fn (string $campo) => [$campo => $campos[$campo] ?? null])->all();
        $examen = $consulta->examenFisico()->first();

        if ($examen) {
            $examen->update($valores);
        } elseif (array_filter($valores, fn ($valor) => $valor !== null) !== []) {
            $consulta->examenFisico()->create($valores);
        }
    }

    /** Actualiza o crea los diagnósticos; el principal es la fila marcada, si está vigente. Nunca borra. */
    private function guardarDiagnosticos(Consulta $consulta, array $filas, string $principal): void
    {
        $guardados = $consulta->diagnosticos()->get()->keyBy('id');

        foreach ($filas as $indice => $fila) {
            $nueva = ! filled($fila['id'] ?? null);
            $activo = $nueva || filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $valores = [
                'codigo_cie10' => (string) $fila['codigo_cie10'],
                'tipo' => $fila['tipo'],
                'descripcion_adicional' => $fila['descripcion_adicional'] ?? null,
                'activo' => $activo,
                'principal' => $activo && (string) $indice === $principal,
            ];

            $nueva ? $consulta->diagnosticos()->create($valores) : $guardados[(int) $fila['id']]->update($valores);
        }
    }
}
