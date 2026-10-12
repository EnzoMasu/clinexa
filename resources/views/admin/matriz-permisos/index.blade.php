@use('App\Models\Permiso')
@use('App\Support\PerfilesPredefinidos')

{{-- Matriz de permisos, pestaña Editar: un perfil a la vez. Funciona sin JavaScript (formulario con casillas que se
     confirma en una segunda pantalla); Alpine solo agrega los atajos por módulo y el buscador. --}}
<x-admin.page title="Matriz de permisos">
    <div class="p-6 space-y-5" x-data="{ q: '' }">
        @include('admin.matriz-permisos._pestanas', ['activa' => 'editar'])

        @if ($perfiles->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">No hay perfiles de acceso cargados.</p>
        @else
            {{-- Selector de perfil: un formulario GET, sin JavaScript. --}}
            <form method="GET" action="{{ route('admin.matriz-permisos.index') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="perfil" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Perfil</label>
                    <select id="perfil" name="perfil" class="mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($perfiles as $opcion)
                            <option value="{{ $opcion->id }}" @selected($opcion->is($perfil))>
                                {{ $opcion->nombre }}{{ $opcion->predefinido ? ' (predefinido)' : '' }}{{ $opcion->estaActivo() ? '' : ' (inactivo)' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <x-secondary-button type="submit">Ver</x-secondary-button>
            </form>

            @if ($perfil->esAdministrador())
                <p class="rounded-md bg-indigo-50 dark:bg-indigo-900/30 p-3 text-sm text-indigo-800 dark:text-indigo-200">
                    El perfil Administrador tiene siempre todos los permisos y no se edita: se muestra solo para consultar.
                </p>
            @elseif (! $perfil->estaActivo())
                <p class="rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-sm text-amber-800 dark:text-amber-200">
                    Este perfil está inactivo: se puede editar, pero no da permisos a nadie mientras siga inactivo.
                </p>
            @endif
            @if ($predefinida)
                <p class="rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-sm text-amber-800 dark:text-amber-200">
                    Se muestran los permisos <strong>predefinidos</strong> de este perfil, todavía sin guardar. Revíselos y use
                    "Guardar cambios": antes verá la lista de lo que cambia.
                </p>
            @endif

            <form method="POST" action="{{ route('admin.matriz-permisos.update', $perfil) }}" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="huella" value="{{ $huella }}">
                <x-input-error :messages="$errors->get('permisos')" />

                <div class="flex flex-wrap items-center gap-3" style="display: none" x-show="true">
                    <label for="buscar-modulo" class="text-sm text-gray-700 dark:text-gray-300">Buscar módulo</label>
                    <input id="buscar-modulo" type="search" x-model="q" autocomplete="off"
                        class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>

                <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Módulo</th>
                                @foreach (Permiso::ACCIONES as $accion)
                                    <th scope="col" class="px-3 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $accion }}</th>
                                @endforeach
                                @if ($puedeEditar)
                                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400" style="display: none" x-show="true">Atajos</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                            @foreach ($grupos as $grupo => $modulos)
                                <tr class="bg-gray-50 dark:bg-gray-900/40" data-grupo="{{ $grupo }}">
                                    <th scope="colgroup" colspan="{{ count(Permiso::ACCIONES) + 2 }}" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">{{ $grupo }}</th>
                                </tr>
                                @foreach ($modulos as $modulo)
                                    <tr data-modulo="{{ $modulo->codigo }}" data-buscar="{{ mb_strtolower($modulo->nombre.' '.$modulo->codigo) }}"
                                        x-show="q === '' || $el.dataset.buscar.includes(q.toLowerCase())">
                                        <th scope="row" class="px-4 py-2 text-left font-medium whitespace-nowrap">
                                            {{ $modulo->nombre }}
                                            @if ($modulo->es_sensible)
                                                <span class="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">Sensible</span>
                                            @endif
                                            @unless ($modulo->estaActivo())
                                                <span class="ms-1 text-xs text-gray-600 dark:text-gray-400">(módulo inactivo)</span>
                                            @endunless
                                        </th>
                                        @foreach (Permiso::ACCIONES as $accion)
                                            <td class="px-3 py-2 text-center">
                                                @if (in_array($accion, Permiso::accionesDe($modulo->codigo), true))
                                                    <input type="checkbox" name="permisos[{{ $modulo->id }}][]" value="{{ $accion }}"
                                                        aria-label="{{ $modulo->nombre }}: {{ $accion }}"
                                                        @checked(in_array("{$modulo->codigo}:{$accion}", $tildados, true))
                                                        @disabled(! $puedeEditar)
                                                        class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800 disabled:opacity-60 disabled:cursor-not-allowed">
                                                @else
                                                    <span class="text-gray-500 dark:text-gray-400" title="No tiene efecto en este módulo" aria-label="{{ $modulo->nombre }}: {{ $accion }} no aplica">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        @if ($puedeEditar)
                                            {{-- Atajos (solo con JavaScript): tildan o destildan las casillas de la fila. --}}
                                            <td class="px-3 py-2 whitespace-nowrap text-xs space-x-2" style="display: none" x-show="true">
                                                <button type="button" class="text-indigo-600 dark:text-indigo-400 hover:underline"
                                                    x-on:click="$el.closest('tr').querySelectorAll('input[type=checkbox]').forEach(c => c.checked = true)">Todo</button>
                                                <button type="button" class="text-indigo-600 dark:text-indigo-400 hover:underline"
                                                    x-on:click="$el.closest('tr').querySelectorAll('input[type=checkbox]').forEach(c => c.checked = false)">Ninguno</button>
                                                <button type="button" class="text-indigo-600 dark:text-indigo-400 hover:underline"
                                                    x-on:click="$el.closest('tr').querySelectorAll('input[type=checkbox]').forEach(c => c.checked = c.value === 'VER')">Solo ver</button>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($puedeEditar)
                    <div class="max-w-xl space-y-1">
                        <x-input-label for="motivo" value="Motivo (opcional)" />
                        <x-text-input id="motivo" name="motivo" type="text" maxlength="300" class="block w-full" :value="old('motivo')" />
                        <p class="text-xs text-gray-600 dark:text-gray-400">Queda en el detalle del evento de auditoría.</p>
                        <x-input-error :messages="$errors->get('motivo')" />
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <x-primary-button>Guardar cambios</x-primary-button>
                        @if (isset(PerfilesPredefinidos::MATRIZ[$perfil->codigo]) && ! $predefinida)
                            <a href="{{ route('admin.matriz-permisos.index', ['perfil' => $perfil->id, 'predefinido' => 1]) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                                Restablecer a lo predefinido
                            </a>
                        @endif
                        <p class="text-xs text-gray-600 dark:text-gray-400">
                            Antes de guardar verá la lista de cambios. Solo puede agregar permisos que usted ya tiene.
                        </p>
                    </div>
                @endif
            </form>
        @endif
    </div>
</x-admin.page>
