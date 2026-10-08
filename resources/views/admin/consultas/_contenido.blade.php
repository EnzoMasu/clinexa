{{--
    Contenido de una consulta, compartido por la página completa (consultas/show) y el popup de la
    historia (fragmento de ConsultaController::detalle): así no se duplica ni se desincroniza.

    Todo el contenido clínico va escapado ({{ }}); los saltos de línea los da whitespace-pre-line.
    Las secciones vacías se omiten. Requiere $consulta con historiaClinica.paciente.persona.tipoDocumento,
    profesional.persona, turno, bloquesAnamnesis.tipoBloqueAnamnesis, examenFisico y diagnosticos.cie10.

    data-url-editar: la URL del formulario, solo si el usuario puede modificarla (el popup la usa
    para su botón "Editar"); no lleva contenido clínico.
--}}
@php
    use App\Models\ExamenFisico;
    use App\Support\Fecha;

    $puedeModificar ??= false;
    $paciente = $consulta->historiaClinica->paciente;
    $examen = $consulta->examenFisico;
    // Signos vitales cargados, en el orden del formulario: sigla => [valor con unidad, nombre completo].
    $signos = collect(ExamenFisico::SIGLAS)
        ->map(fn (string $sigla, string $campo) => [$sigla, $examen?->valorTexto($campo), ExamenFisico::CAMPOS[$campo][0]])
        ->filter(fn (array $signo) => $signo[1] !== null);
    $bloques = $consulta->bloquesAnamnesis->sortBy(['orden', 'id']);
    // Vigentes primero (el principal arriba); los retirados al final, atenuados.
    $diagnosticos = $consulta->diagnosticos->sortBy([['activo', 'desc'], ['principal', 'desc'], ['id', 'asc']]);
    $retirado = 'rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300';
@endphp

<article class="space-y-4 text-sm text-gray-900 dark:text-gray-100" data-consulta-id="{{ $consulta->id }}"
    @if ($puedeModificar) data-url-editar="{{ route('admin.consultas.edit', $consulta) }}" @endif>
    <header>
        <h3 class="text-base font-semibold">{{ $paciente->persona->nombre_completo }}</h3>
        <p class="text-xs text-gray-600 dark:text-gray-400">
            Ficha {{ $paciente->nro_ficha }}
            · {{ $paciente->persona->tipoDocumento->codigo }} {{ $paciente->persona->nro_documento }}
            · <span class="font-mono">{{ $consulta->fechaHoraTexto() }}</span>
            · {{ $consulta->profesional->persona->nombre_completo }}
            · @if ($consulta->turno)
                Turno del {{ Fecha::mostrar($consulta->turno->fecha) }}, {{ substr($consulta->turno->hora_inicio, 0, 5) }}
            @else
                Sin turno (urgencia)
            @endif
        </p>
    </header>

    <section>
        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Motivo de consulta</h4>
        <p class="mt-1 whitespace-pre-line">{{ $consulta->motivo_consulta }}</p>
    </section>

    @if ($signos->isNotEmpty())
        <section>
            <h4 class="sr-only">Signos vitales</h4>
            <ul class="flex flex-wrap gap-2">
                @foreach ($signos as [$sigla, $valor, $nombre])
                    <li class="rounded-full bg-gray-100 px-3 py-1 text-xs dark:bg-gray-700/60">
                        <abbr title="{{ $nombre }}" class="font-semibold no-underline">{{ $sigla }}</abbr> {{ $valor }}
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($examen?->hallazgos)
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Hallazgos</h4>
            <p class="mt-1 whitespace-pre-line">{{ $examen->hallazgos }}</p>
        </section>
    @endif

    @if ($bloques->isNotEmpty())
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Anamnesis</h4>
            <ul class="mt-1 space-y-2">
                @foreach ($bloques as $bloque)
                    <li @class(['opacity-60' => ! $bloque->activo])>
                        <div class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                            {{ $bloque->tipoBloqueAnamnesis->nombre }}
                            @unless ($bloque->activo)
                                <span class="ms-1 {{ $retirado }}">Retirado</span>
                            @endunless
                        </div>
                        <p class="whitespace-pre-line">{{ $bloque->contenido }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($diagnosticos->isNotEmpty())
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Diagnósticos</h4>
            <ul class="mt-1 space-y-2">
                @foreach ($diagnosticos as $diagnostico)
                    <li @class(['opacity-60' => ! $diagnostico->activo])>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $diagnostico->codigoYDescripcion() }}</span>
                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ $diagnostico->tipo->etiqueta() }}</span>
                            @if ($diagnostico->principal)
                                <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200">Principal</span>
                            @endif
                            @unless ($diagnostico->activo)
                                <span class="{{ $retirado }}">Retirado</span>
                            @endunless
                        </div>
                        @if ($diagnostico->descripcion_adicional)
                            <p class="text-gray-700 dark:text-gray-300 whitespace-pre-line">{{ $diagnostico->descripcion_adicional }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</article>
