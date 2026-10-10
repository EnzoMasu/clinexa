@use('App\Support\Fecha')

{{--
    Pantalla de atención (AtencionController::pantalla y ::editar). Una sola pantalla y un solo formulario,
    con un panel por sección: con la consulta EN_CURSO se autoguarda (resources/js/consulta-autoguardado.js)
    y se cierra con "Finalizar consulta"; FINALIZADA (Editar) se guarda solo con "Guardar cambios".
    Todo lo que se muestra pasa por Blade (escapado); el contenido clínico no se guarda en el navegador.
--}}
@php
    $clases = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm';
    $boton = 'inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700';
    $botonPrincipal = 'inline-flex items-center rounded-md bg-gray-800 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white';
    $secciones = ['anamnesis' => 'Anamnesis', 'examen' => 'Examen físico', 'motivo' => 'Motivo y diagnósticos', 'indicaciones' => 'Indicaciones'];
    // Historial (panel lateral): para el popup, los ids en el orden mostrado y las URL con marcador.
    $idsHistorial = $historial->pluck('id')->values()->all();
@endphp

<x-admin.page :title="$enCurso ? 'Atención' : 'Editar consulta'" sin-recorte>
    <div x-data="consultaAutoguardado({
            url: {{ Js::from($enCurso ? route('admin.consultas.autoguardado', $consulta) : null) }},
            autoguardado: {{ Js::from($enCurso) }},
            panel: {{ Js::from($panelInicial) }},
            panelesConError: {{ Js::from($seccionesConError) }},
        })" x-on:keydown.escape.window="historialAbierto = false">
        {{-- Barra fija: paciente, estado del guardado y acciones. --}}
        <div class="sticky top-0 z-30 space-y-3 rounded-t-lg border-b border-gray-200 bg-white px-6 py-3 dark:border-gray-700 dark:bg-gray-800">
            @include('admin.consultas._barra-paciente')

            @unless ($enCurso)
                <p role="status" class="rounded-md bg-amber-50 p-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    Consulta finalizada: los cambios quedan registrados en el historial.
                </p>
            @endunless

            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="flex flex-wrap items-center gap-3 text-xs text-gray-600 dark:text-gray-400">
                    @if ($enCurso)
                        <span>En consulta desde {{ substr(Fecha::mostrar($consulta->iniciada_en, conHora: true), -5) }}</span>
                        @include('admin.consultas._guardado')
                    @else
                        <span>{{ $consulta->fechaHoraTexto() }}</span>
                    @endif
                </span>
                <span class="flex flex-wrap items-center gap-2">
                    @if ($enCurso)
                        <button type="submit" form="form-consulta" class="{{ $botonPrincipal }}">Finalizar consulta</button>
                        <button type="submit" form="form-deshacer" class="{{ $boton }}">Deshacer atención</button>
                        <a href="{{ route('admin.atencion.index') }}" class="{{ $boton }}">Volver a la lista</a>
                    @else
                        <button type="submit" form="form-consulta" class="{{ $botonPrincipal }}">Guardar cambios</button>
                        <a href="{{ route('admin.consultas.show', $consulta) }}" class="{{ $boton }}">Cancelar</a>
                    @endif
                </span>
            </div>

            {{-- Secciones: un punto si tienen algo cargado; en rojo, las que tienen errores. --}}
            <nav class="flex flex-wrap gap-2" aria-label="Secciones de la consulta">
                @foreach ($secciones as $clave => $nombre)
                    <button type="button" x-on:click="ver('{{ $clave }}')" x-bind:aria-pressed="(panel === '{{ $clave }}').toString()"
                        x-bind:class="{
                            'bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-800 border-transparent': panel === '{{ $clave }}',
                            'border-red-500 text-red-700 dark:text-red-300': panelesConError.includes('{{ $clave }}') && panel !== '{{ $clave }}',
                        }"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-300">
                        {{ $nombre }}
                        <span x-show="contenido['{{ $clave }}']" class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                        <span x-show="panelesConError.includes('{{ $clave }}')" class="sr-only">(con errores)</span>
                    </button>
                @endforeach
                @if ($verRecetas)
                    <button type="button" x-on:click="ver('recetas')" x-bind:aria-pressed="(panel === 'recetas').toString()"
                        x-bind:class="{ 'bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-800 border-transparent': panel === 'recetas' }"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-300">
                        Recetas
                        @if ($consulta->recetas->isNotEmpty())
                            <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                        @endif
                    </button>
                @endif
                <button type="button" disabled title="Próximamente" class="inline-flex cursor-not-allowed items-center gap-1.5 rounded-md border border-dashed border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-400 dark:border-gray-600 dark:text-gray-500">
                    Estudios <span class="font-normal">(Próximamente)</span>
                </button>
                <button type="button" x-on:click="historialAbierto = ! historialAbierto" x-bind:aria-expanded="(!! historialAbierto).toString()" aria-controls="historial-paciente"
                    class="{{ $boton }} lg:hidden">Historial</button>
            </nav>
        </div>

        <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <form id="form-consulta" x-ref="formulario" method="POST" novalidate
                action="{{ $enCurso ? route('admin.consultas.finalizar', $consulta) : route('admin.consultas.update', $consulta) }}"
                x-on:input="cambio()" x-on:change="cambio()" x-on:click="$event.target.closest('button[type=button]') && cambio()"
                x-on:submit="enviar($event)" class="min-w-0 space-y-6">
                @csrf
                @unless ($enCurso)
                    @method('PUT')
                @endunless
                {{-- Control de concurrencia: el autoguardado la actualiza en cada guardado. --}}
                <input type="hidden" name="version" value="{{ $consulta->version() }}">

                @foreach (['anamnesis' => ['secciones._anamnesis'], 'examen' => ['secciones._signos', 'secciones._hallazgos'], 'motivo' => ['secciones._diagnosticos'], 'indicaciones' => ['secciones._indicaciones']] as $clave => $vistas)
                    <section data-panel="{{ $clave }}" x-show="panel === '{{ $clave }}'" class="space-y-6" aria-label="{{ $secciones[$clave] }}">
                        @foreach ($vistas as $vista)
                            @include('admin.consultas.'.$vista)
                        @endforeach
                        {{-- Errores del autoguardado (422) de esta sección. --}}
                        <template x-if="errores['{{ $clave }}']">
                            <ul class="space-y-1 text-sm text-red-600 dark:text-red-400" role="alert">
                                <template x-for="mensaje in errores['{{ $clave }}']" x-bind:key="mensaje">
                                    <li x-text="mensaje"></li>
                                </template>
                            </ul>
                        </template>
                    </section>
                @endforeach

                @if ($verRecetas)
                    {{-- Recetas: se cargan en sus pantallas, que se abren en otra pestaña (esta queda como está). --}}
                    <section x-show="panel === 'recetas'" style="display: none" class="space-y-3" aria-label="Recetas">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-medium text-gray-900 dark:text-gray-100">Recetas</h3>
                            @if ($puedeCrearReceta)
                                <a href="{{ route('admin.recetas.create', $consulta) }}" target="_blank" rel="noopener noreferrer" class="{{ $botonPrincipal }}">Nueva receta</a>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Las recetas se abren en otra pestaña. Para ver aquí una receta nueva o un cambio, recargue esta página: lo cargado no se pierde.</p>
                        @if ($consulta->recetas->isEmpty())
                            <p class="text-sm text-gray-500 dark:text-gray-400">Sin recetas en esta consulta.</p>
                        @else
                            <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
                                @foreach ($consulta->recetas->sortBy('id') as $receta)
                                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                        <span>
                                            <span class="dato text-xs font-semibold">{{ $receta->numero ?? 'Borrador' }}</span>
                                            <span class="text-xs text-gray-600 dark:text-gray-400">· {{ $receta->etiquetaEstado() }} · {{ $receta->detalles->count() }} {{ $receta->detalles->count() === 1 ? 'medicamento' : 'medicamentos' }}</span>
                                        </span>
                                        <span class="flex flex-wrap gap-3 text-xs">
                                            @if ($receta->esBorrador())
                                                @can('update', $receta)
                                                    <a href="{{ route('admin.recetas.edit', $receta) }}" target="_blank" rel="noopener noreferrer" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</a>
                                                @endcan
                                                <a href="{{ route('admin.recetas.vista-previa', $receta) }}" target="_blank" rel="noopener noreferrer" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Vista previa</a>
                                            @else
                                                <a href="{{ route('admin.recetas.imprimir', $receta) }}" target="_blank" rel="noopener noreferrer" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">{{ $receta->estaEmitida() ? 'Imprimir' : 'Ver hoja' }}</a>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif

                <noscript><p class="text-sm text-red-600">Para cargar la consulta se necesita JavaScript habilitado.</p></noscript>
            </form>

            {{--
                Historial del paciente: sus consultas FINALIZADAS anteriores (hasta 20), solo lo de la fila; el
                contenido se pide al abrir el popup (sin "Editar"). En pantallas chicas, detrás de "Historial".
            --}}
            <aside id="historial-paciente" x-bind:class="historialAbierto ? '' : 'hidden lg:block'" class="hidden space-y-3 lg:block"
                x-data="popupConsultas({ ids: {{ Js::from($idsHistorial) }}, urlDetalle: {{ Js::from(route('admin.consultas.detalle', ['consulta' => '__ID__'])) }}, urlPagina: {{ Js::from(route('admin.consultas.show', ['consulta' => '__ID__'])) }}, sinEditar: true })"
                x-on:keydown.window="teclado($event)" aria-labelledby="titulo-historial">
                <h3 id="titulo-historial" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Historial del paciente</h3>
                @if ($historial->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">No hay consultas anteriores.</p>
                @else
                    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($historial as $anterior)
                            @php($codigos = $anterior->diagnosticos->pluck('codigo_cie10'))
                            <li>
                                <a href="{{ route('admin.consultas.show', $anterior) }}" target="_blank" rel="noopener noreferrer" x-on:click="abrir({{ $anterior->id }}, $event)"
                                    class="block space-y-0.5 px-3 py-2 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:hover:bg-gray-700/50 dark:focus:bg-gray-700/50">
                                    <span class="block font-mono text-xs text-gray-900 dark:text-gray-100">{{ $anterior->fechaHoraTexto() }}</span>
                                    <span class="block truncate text-xs text-gray-600 dark:text-gray-400">{{ $anterior->profesional->persona->nombre_completo }}</span>
                                    <span class="block truncate text-gray-700 dark:text-gray-300">{{ Str::limit($anterior->motivo_consulta, 60) }}</span>
                                    @if ($codigos->isNotEmpty())
                                        <span class="block font-mono text-xs text-gray-600 dark:text-gray-400">{{ $codigos->take(3)->implode(', ') }}@if ($codigos->count() > 3) +{{ $codigos->count() - 3 }}@endif</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <a href="{{ route('admin.historias-clinicas.show', $consulta->historia_clinica_id) }}" target="_blank" rel="noopener noreferrer"
                    class="inline-block text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ver historia completa</a>

                @include('admin.consultas._popup', ['boton' => $boton])
            </aside>
        </div>

        @if ($enCurso)
            {{-- Deshacer: solo si todavía no hay contenido clínico (lo comprueba el servidor). --}}
            <form id="form-deshacer" method="POST" action="{{ route('admin.consultas.deshacer', $consulta) }}"
                x-on:submit="if (! confirm({{ Js::from($consulta->turno_id
                    ? '¿Deshacer la atención? El paciente vuelve a la agenda y se conserva lo cargado en la preparación.'
                    : '¿Deshacer la atención? Esta consulta sin turno se anula.') }})) { $event.preventDefault() } else { enviandoFormulario = true }">
                @csrf
            </form>
        @endif

        @include('admin.consultas._cartel-guardado')
    </div>
</x-admin.page>
