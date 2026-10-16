{{--
    Órdenes de estudio de una consulta (OrdenEstudioController::index; docs/ordenes-estudio.md): la lista y, solo para el
    profesional de la consulta, el formulario para emitir una nueva. Funciona sin JavaScript. Los estudios salen del catálogo
    (solo los activos) o se escriben a mano, uno por renglón (no se agregan al catálogo). Los diagnósticos salen impresos solo
    si se marcan (por defecto, ninguno).
--}}
@use('App\Support\OrdenEstudio\EmitirOrden')

@php
    $persona = $consulta->historiaClinica->paciente->persona;
    $elegidos = array_map('intval', (array) old('diagnosticos', []));
    $estudiosElegidos = array_map('intval', (array) old('estudios', []));
@endphp

<x-admin.page title="Órdenes de estudio">
    <div class="space-y-6 p-6 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-gray-600 dark:text-gray-400">
                Consulta de {{ $persona->nombre_completo }} ({{ $persona->tipoDocumento->codigo }} {{ $persona->nro_documento }}) con
                {{ $consulta->profesional->persona->nombre_completo }} · {{ $consulta->fechaHoraTexto() }}
            </p>
            @if ($consulta->finalizada())
                <a href="{{ route('admin.consultas.show', $consulta) }}" class="text-gray-600 hover:underline dark:text-gray-400">Volver a la consulta</a>
            @endif
        </div>

        <section aria-labelledby="ordenes-emitidas">
            <h3 id="ordenes-emitidas" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Órdenes de esta consulta</h3>
            @if ($ordenes->isEmpty())
                <p class="mt-2 text-gray-600 dark:text-gray-400">Todavía no hay órdenes de estudio en esta consulta.</p>
            @else
                <ul class="mt-2 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($ordenes as $orden)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" data-orden="{{ $orden->numero }}">
                            <span>
                                <span class="dato font-semibold">{{ $orden->numero }}</span>
                                · {{ implode(', ', $orden->nombresEstudios()) }}
                                · <span class="text-gray-600 dark:text-gray-400">{{ $orden->etiquetaEstado() }}</span>
                            </span>
                            <a href="{{ route('admin.ordenes-estudio.show', $orden) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ver</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($puedeEmitir)
            <form method="POST" action="{{ route('admin.ordenes-estudio.emitir', $consulta) }}" novalidate class="max-w-3xl space-y-5 border-t border-gray-200 pt-6 dark:border-gray-700"
                onsubmit="this.querySelector('[data-emitir]').disabled = true">
                @csrf
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Emitir una orden nueva</h3>
                <p class="text-gray-600 dark:text-gray-400">Una vez emitida no se modifica: si hay un error, anúlela con su motivo y emita otra.</p>

                <fieldset>
                    <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">Estudios del catálogo</legend>
                    @if ($catalogo->isEmpty())
                        <p class="mt-2 text-gray-600 dark:text-gray-400">El catálogo no tiene estudios activos. Escríbalos abajo.</p>
                    @else
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($catalogo as $estudio)
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="estudios[]" value="{{ $estudio->id }}" @checked(in_array($estudio->id, $estudiosElegidos, true))
                                        class="rounded border-gray-300 text-indigo-600 dark:border-gray-700 dark:bg-gray-900">
                                    <span>{{ $estudio->nombre }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    <x-input-error class="mt-2" :messages="$errors->get('estudios')" />
                </fieldset>

                <div>
                    <x-admin.textarea name="otros" label="Otros estudios, que no están en el catálogo (uno por renglón)" rows="3" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Van solo en esta orden: no se agregan al catálogo. Hasta {{ EmitirOrden::MAXIMO_ESTUDIOS }} estudios por orden.</p>
                </div>

                <x-admin.textarea name="indicacion_clinica" label="Indicación clínica (opcional)" rows="3" maxlength="{{ EmitirOrden::MAXIMO_INDICACION }}" />

                <fieldset>
                    <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">Diagnóstico presuntivo en la orden (opcional)</legend>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Por defecto no se imprime. Marque solo los que quiera que figuren, con su código CIE-10.</p>
                    @if ($diagnosticos->isEmpty())
                        <p class="mt-2 text-gray-600 dark:text-gray-400">La consulta no tiene diagnósticos registrados.</p>
                    @else
                        <div class="mt-2 space-y-2">
                            @foreach ($diagnosticos as $diagnostico)
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="diagnosticos[]" value="{{ $diagnostico->id }}" @checked(in_array($diagnostico->id, $elegidos, true))
                                        class="rounded border-gray-300 text-indigo-600 dark:border-gray-700 dark:bg-gray-900">
                                    <span>{{ $diagnostico->codigoYDescripcion() }}{{ $diagnostico->principal ? ' (principal)' : '' }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    <x-input-error class="mt-2" :messages="$errors->get('diagnosticos')" />
                </fieldset>

                <div class="flex items-center gap-4">
                    <x-primary-button data-emitir>Emitir orden</x-primary-button>
                </div>
            </form>
        @endif
    </div>
</x-admin.page>
