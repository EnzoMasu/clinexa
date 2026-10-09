@use('App\Models\Persona')
@use('App\Support\Fecha')

@php
    $paciente = $historia->paciente;
    $persona = $paciente->persona;
    // Edad a hoy (hora de Paraguay), en años cumplidos.
    $edad = $persona->fecha_nacimiento ? (int) $persona->fecha_nacimiento->diffInYears(Fecha::hoy()) : null;
    // Para el popup: los ids de las consultas de ESTA página, en el orden mostrado (anterior/siguiente
    // no cruzan de página), y las URL con un marcador que el JS reemplaza por el id. Sin contenido clínico.
    $idsPagina = $consultas->getCollection()->pluck('id')->values()->all();
    $urlDetalle = route('admin.consultas.detalle', ['consulta' => '__ID__']);
    $urlPagina = route('admin.consultas.show', ['consulta' => '__ID__']);
    $boton = 'inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700';
@endphp

<x-admin.page title="Historia clínica">
    <div class="p-6 space-y-6">
        @if ($puedeAtender)
            {{-- POST: crea la consulta EN_CURSO (sin turno) y abre la pantalla de atención. --}}
            <form method="POST" action="{{ route('admin.atencion.atender-sin-turno', $historia) }}" class="flex justify-end">
                @csrf
                <x-primary-button>Atender sin turno</x-primary-button>
            </form>
        @endif

        <dl class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6 text-sm">
            <div class="sm:col-span-2">
                <dt class="text-gray-500 dark:text-gray-400">Paciente</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $persona->nombre_completo }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Documento</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $persona->tipoDocumento->codigo }}</span>
                    <span class="font-mono">{{ $persona->nro_documento }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Nro. ficha</dt>
                <dd class="font-medium font-mono text-gray-900 dark:text-gray-100">{{ $paciente->nro_ficha }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Edad</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $edad === null ? '—' : $edad.' '.($edad === 1 ? 'año' : 'años') }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Sexo</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ Persona::SEXOS[$persona->sexo] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Apertura de la historia</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($historia->fecha_apertura) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Estado del paciente</dt>
                <dd><x-admin.estado-badge :estado="$paciente->estado" /></dd>
            </div>
        </dl>

        <div class="space-y-3"
            x-data="popupConsultas({ ids: {{ Js::from($idsPagina) }}, urlDetalle: {{ Js::from($urlDetalle) }}, urlPagina: {{ Js::from($urlPagina) }} })"
            x-on:keydown.window="teclado($event)">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Consultas</h3>

            {{--
                Cada fila es un enlace real a la página completa de la consulta: sin JavaScript, con
                Ctrl/Cmd+clic, clic con la rueda o "abrir en pestaña nueva" va ahí. El clic normal abre el
                popup. Solo lo mínimo de la fila: el contenido completo se pide al abrirla.
            --}}
            @if ($consultas->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Todavía no hay consultas en esta historia.</p>
            @else
                <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700" data-ids-consultas="{{ implode(',', $idsPagina) }}">
                    @foreach ($consultas as $consulta)
                        @php
                            $codigos = $consulta->diagnosticos->pluck('codigo_cie10');
                        @endphp
                        <li>
                            <a href="{{ route('admin.consultas.show', $consulta) }}" data-consulta-id="{{ $consulta->id }}"
                                x-on:click="abrir({{ $consulta->id }}, $event)"
                                class="grid gap-x-4 gap-y-1 px-4 py-2.5 text-gray-900 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:text-gray-100 dark:hover:bg-gray-700/50 dark:focus:bg-gray-700/50 sm:grid-cols-[9.5rem_12rem_1fr_auto] sm:items-center">
                                <span class="font-mono text-xs whitespace-nowrap">{{ $consulta->fechaHoraTexto() }}</span>
                                <span class="truncate">{{ $consulta->profesional->persona->nombre_completo }}</span>
                                {{-- Motivo cortado en el servidor (el completo no viaja en esta página) y en una línea. --}}
                                <span class="truncate text-gray-600 dark:text-gray-400">
                                    @if ($consulta->enCurso())
                                        <span class="me-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800 dark:bg-blue-900/40 dark:text-blue-200">En curso</span>
                                    @elseif ($consulta->enPreparacion())
                                        <span class="me-1 rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800 dark:bg-violet-900/40 dark:text-violet-200">En preparación</span>
                                    @endif
                                    @if (! $consulta->turno_id)
                                        <span class="me-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Urgencia</span>
                                    @endif
                                    {{ Str::limit($consulta->motivo_consulta, 90) }}
                                </span>
                                <span class="font-mono text-xs whitespace-nowrap">
                                    @forelse ($codigos->take(3) as $codigo)
                                        <span @class(['font-semibold' => $loop->first && $consulta->diagnosticos->first()->principal])>{{ $codigo }}</span>@unless ($loop->last), @endunless
                                    @empty
                                        <span class="text-gray-500 dark:text-gray-400">—</span>
                                    @endforelse
                                    @if ($codigos->count() > 3)
                                        <span class="text-gray-500 dark:text-gray-400" title="{{ $codigos->count() - 3 }} diagnósticos más">+{{ $codigos->count() - 3 }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($consultas->hasPages())
                    <div>{{ $consultas->links() }}</div>
                @endif
            @endif

            @include('admin.consultas._popup', ['boton' => $boton])
        </div>

        <a href="{{ route('admin.historias-clinicas.index') }}" class="inline-block text-sm text-gray-600 dark:text-gray-400 hover:underline">Volver a las historias clínicas</a>
    </div>
</x-admin.page>
