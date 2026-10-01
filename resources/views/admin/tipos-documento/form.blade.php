@php
    // Módulos tildados y dónde es predeterminado: lo guardado o, tras un error de validación, lo que se había marcado.
    $habilitados = session()->hasOldInput()
        ? array_map('strval', (array) old('modulos', []))
        : $tipoDocumento->modulos->map(fn ($modulo) => (string) $modulo->id)->all();
    $predeterminados = session()->hasOldInput()
        ? array_map('strval', (array) old('predeterminado', []))
        : $tipoDocumento->modulos->filter(fn ($modulo) => $modulo->pivot->es_predeterminado)->map(fn ($modulo) => (string) $modulo->id)->values()->all();
@endphp

<x-admin.page :title="$tipoDocumento->exists ? 'Editar tipo de documento' : 'Nuevo tipo de documento'">
    <x-admin.form
        :action="$tipoDocumento->exists ? route('admin.tipos-documento.update', $tipoDocumento) : route('admin.tipos-documento.store')"
        :method="$tipoDocumento->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.tipos-documento.index')">
        <x-admin.input name="codigo" label="Código" :value="$tipoDocumento->codigo" unico="tipo_documento.codigo" :unico-ignorar="$tipoDocumento->id" maxlength="10" required autofocus />
        <x-admin.input name="nombre" label="Nombre" :value="$tipoDocumento->nombre" maxlength="50" required />

        @if ($tipoDocumento->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$tipoDocumento::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$tipoDocumento->estado_id" required />
        @endif

        {{-- Habilitación por módulo (tipo_documento_modulo). Los módulos salen de modulos_sistema.usa_tipos_documento. --}}
        <fieldset x-data="{ habilitados: @js($habilitados), predeterminados: @js($predeterminados) }" class="space-y-3">
            <legend class="font-medium text-gray-900 dark:text-gray-100">Habilitado en</legend>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Tilde los módulos en los que se puede elegir este tipo de documento. En cada módulo puede marcarlo como
                predeterminado (el que viene elegido al cargar datos); solo hay uno por módulo, así que reemplaza al anterior.
            </p>
            <x-input-error :messages="[...$errors->get('modulos'), ...$errors->get('modulos.*'), ...$errors->get('predeterminado'), ...$errors->get('predeterminado.*')]" />

            @if ($modulos->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Todavía no hay módulos que usen tipos de documento. Ejecute <code>php artisan db:seed --class=ModuloSistemaSeeder</code>.
                </p>
            @else
                <ul class="divide-y divide-gray-200 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-700">
                    @foreach ($modulos as $modulo)
                        @php
                            $id = (string) $modulo->id;
                            // Predeterminado actual del módulo, si es otro tipo.
                            $actual = $modulo->tiposDocumento->first();
                            $otroPredeterminado = $actual && $actual->id !== $tipoDocumento->id ? $actual : null;
                            $eraPredeterminado = $actual && $actual->id === $tipoDocumento->id;
                        @endphp
                        <li class="px-4 py-3 space-y-2 text-sm">
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                <label class="inline-flex items-center gap-2 font-medium text-gray-900 dark:text-gray-100">
                                    <input type="checkbox" name="modulos[]" value="{{ $id }}" x-model="habilitados"
                                        @checked(in_array($id, $habilitados, true))
                                        class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800">
                                    {{ $modulo->nombre }}
                                </label>
                                <label class="inline-flex items-center gap-2 text-gray-700 dark:text-gray-300"
                                    x-bind:class="habilitados.includes('{{ $id }}') ? '' : 'opacity-50'">
                                    <input type="checkbox" name="predeterminado[]" value="{{ $id }}" x-model="predeterminados"
                                        @checked(in_array($id, $predeterminados, true))
                                        @disabled(! in_array($id, $habilitados, true))
                                        x-bind:disabled="! habilitados.includes('{{ $id }}')"
                                        aria-label="Predeterminado en {{ $modulo->nombre }}"
                                        class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800 disabled:cursor-not-allowed">
                                    Predeterminado
                                </label>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($otroPredeterminado)
                                    Predeterminado actual: {{ $otroPredeterminado->nombre }} ({{ $otroPredeterminado->codigo }}).
                                    <span x-show="habilitados.includes('{{ $id }}') && predeterminados.includes('{{ $id }}')" @style(['display: none' => ! (in_array($id, $habilitados, true) && in_array($id, $predeterminados, true))])
                                        class="text-amber-700 dark:text-amber-300">
                                        Al guardar, este tipo lo reemplaza y {{ $otroPredeterminado->codigo }} deja de ser el predeterminado.
                                    </span>
                                @elseif ($eraPredeterminado)
                                    Hoy es el predeterminado de este módulo.
                                    <span x-show="! (habilitados.includes('{{ $id }}') && predeterminados.includes('{{ $id }}'))" style="display: none"
                                        class="text-amber-700 dark:text-amber-300">
                                        Al guardar, {{ $modulo->nombre }} quedará sin tipo de documento predeterminado.
                                    </span>
                                @else
                                    Este módulo no tiene tipo de documento predeterminado.
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>

                {{-- Advertencia antes de guardar: sin módulos se permite, pero el tipo queda sin uso. --}}
                <div x-show="habilitados.length === 0" @style(['display: none' => $habilitados !== []]) role="alert"
                    class="rounded-md bg-amber-50 dark:bg-amber-900/30 p-4 text-sm text-amber-800 dark:text-amber-200">
                    <p class="font-medium">No está habilitado en ningún módulo.</p>
                    <p>Puede guardarlo así, pero el tipo de documento quedará sin uso: no se ofrecerá al cargar datos hasta que lo habilite en algún módulo. Los registros que ya lo usan no se modifican.</p>
                </div>
            @endif
        </fieldset>
    </x-admin.form>
</x-admin.page>
