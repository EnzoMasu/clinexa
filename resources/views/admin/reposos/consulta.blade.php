{{--
    Certificados de reposo médico de una consulta (ReposoMedicoController::index; docs/reposo-medico.md): la lista y, solo
    para el profesional de la consulta, el formulario para emitir uno nuevo. Funciona sin JavaScript. Los diagnósticos salen
    impresos solo si se marcan (por defecto, ninguno).
--}}
@use('App\Support\Fecha')
@use('App\Http\Controllers\Admin\ReposoMedicoController')

@php
    $persona = $consulta->historiaClinica->paciente->persona;
    $elegidos = array_map('intval', (array) old('diagnosticos', []));
@endphp

<x-admin.page title="Certificados de reposo médico">
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

        <section aria-labelledby="reposos-emitidos">
            <h3 id="reposos-emitidos" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Certificados de esta consulta</h3>
            @if ($reposos->isEmpty())
                <p class="mt-2 text-gray-600 dark:text-gray-400">Todavía no hay certificados de reposo en esta consulta.</p>
            @else
                <ul class="mt-2 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($reposos as $reposo)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" data-reposo="{{ $reposo->numero }}">
                            <span>
                                <span class="dato font-semibold">{{ $reposo->numero }}</span>
                                · {{ $reposo->dias }} {{ $reposo->dias === 1 ? 'día' : 'días' }}, del {{ Fecha::mostrar($reposo->desde) }} al {{ Fecha::mostrar($reposo->hasta) }}
                                · <span class="text-gray-600 dark:text-gray-400">{{ $reposo->etiquetaEstado() }}</span>
                            </span>
                            <a href="{{ route('admin.reposos.show', $reposo) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ver</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($puedeEmitir)
            <form method="POST" action="{{ route('admin.reposos.emitir', $consulta) }}" novalidate class="max-w-3xl space-y-5 border-t border-gray-200 pt-6 dark:border-gray-700"
                onsubmit="this.querySelector('[data-emitir]').disabled = true">
                @csrf
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Emitir un certificado nuevo</h3>
                <p class="text-gray-600 dark:text-gray-400">Una vez emitido no se modifica: si hay un error, anúlelo con su motivo y emita otro.</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.input name="dias" label="Días de reposo" type="number" min="1" max="{{ ReposoMedicoController::DIAS_MAXIMO }}" step="1" required />
                    <x-admin.fecha name="desde" label="Desde (hoy o posterior)" :value="Fecha::hoy()" required />
                </div>
                <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">"Hasta" se calcula: desde más los días, contando ambos extremos.</p>

                <fieldset>
                    <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">Modalidad</legend>
                    <div class="mt-2 space-y-2">
                        @foreach (config('reposo.modalidades') as $codigo => $texto)
                            <label class="flex items-center gap-2">
                                <input type="radio" name="modalidad" value="{{ $codigo }}" @checked(old('modalidad') === $codigo) required
                                    class="border-gray-300 text-indigo-600 dark:border-gray-700 dark:bg-gray-900">
                                <span>{{ $texto }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error class="mt-2" :messages="$errors->get('modalidad')" />
                </fieldset>
                <x-admin.input name="modalidad_otra" label='Otra indicación (solo si eligió "Otra indicación")' maxlength="300" />

                <x-admin.input name="empleador" label="Empleador o institución (opcional)" maxlength="200" />
                <x-admin.textarea name="observaciones" label="Observaciones y recomendaciones (opcional)" rows="3" maxlength="2000" />
                <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">No escriba el diagnóstico aquí si no quiere que figure.</p>

                <div>
                    <x-input-label for="prorroga_de" value="Prórroga de un certificado anterior (opcional)" />
                    <select id="prorroga_de" name="prorroga_de" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        <option value="">No es una prórroga</option>
                        @foreach ($anteriores as $anterior)
                            <option value="{{ $anterior->numero }}" @selected(old('prorroga_de') === $anterior->numero)>
                                {{ $anterior->numero }} · del {{ Fecha::mostrar($anterior->desde) }} al {{ Fecha::mostrar($anterior->hasta) }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Solo certificados emitidos del mismo paciente. La prórroga empieza después del último día del anterior.</p>
                    <x-input-error class="mt-2" :messages="$errors->get('prorroga_de')" />
                </div>

                <fieldset>
                    <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">Diagnóstico en el certificado (opcional)</legend>
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
                    <x-primary-button data-emitir>Emitir certificado</x-primary-button>
                </div>
            </form>
        @endif
    </div>
</x-admin.page>
