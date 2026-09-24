@php
    use App\Models\Permiso;

    // Si hubo un error de validación, se respeta lo que el usuario había tildado.
    $tildados = old('permisos') !== null
        ? collect(old('permisos'))->flatMap(fn ($acciones, $moduloId) => collect($acciones)->map(fn ($accion) => "{$moduloId}:{$accion}"))->all()
        : $asignados;
@endphp

<x-admin.page :title="$perfil->exists ? 'Editar perfil de acceso' : 'Nuevo perfil de acceso'">
    <x-admin.form
        :action="$perfil->exists ? route('admin.perfiles-acceso.update', $perfil) : route('admin.perfiles-acceso.store')"
        :method="$perfil->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.perfiles-acceso.index')"
        width="max-w-4xl">
        <div class="max-w-xl space-y-6">
            @if ($matrizFija)
                <x-admin.input name="nombre" label="Nombre" :value="$perfil->nombre" readonly />
            @else
                <x-admin.input name="nombre" label="Nombre" :value="$perfil->nombre" maxlength="50" required autofocus />
            @endif
            <x-admin.textarea name="descripcion" label="Descripción" :value="$perfil->descripcion" maxlength="200" rows="2" />
        </div>

        <div class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Permisos</h3>
            <x-input-error :messages="$errors->get('permisos')" />

            @if ($matrizFija)
                <p class="rounded-md bg-indigo-50 dark:bg-indigo-900/30 p-3 text-sm text-indigo-800 dark:text-indigo-200">
                    El perfil Administrador tiene siempre todos los permisos y no se pueden modificar,
                    para que el sistema nunca se quede sin un administrador con acceso completo.
                    Su nombre tampoco se puede cambiar; la descripción sí.
                </p>
            @endif

            @if ($modulos->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    No hay módulos del sistema cargados. Ejecute <code>php artisan db:seed --class=ModuloSistemaSeeder</code>.
                </p>
            @else
                <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Módulo</th>
                                @foreach (Permiso::ACCIONES as $accion)
                                    <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $accion }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                            @foreach ($modulos as $modulo)
                                <tr @class(['opacity-60' => $modulo->estado !== 'ACTIVO'])>
                                    <td class="px-4 py-2">
                                        <span class="font-medium">{{ $modulo->nombre }}</span>
                                        @if ($modulo->es_sensible)
                                            <span class="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">Sensible</span>
                                        @endif
                                        @if ($modulo->estado !== 'ACTIVO')
                                            <x-admin.estado-badge :estado="$modulo->estado" />
                                        @endif
                                    </td>
                                    @foreach (Permiso::ACCIONES as $accion)
                                        <td class="px-4 py-2 text-center">
                                            <input type="checkbox" name="permisos[{{ $modulo->id }}][]" value="{{ $accion }}"
                                                aria-label="{{ $modulo->nombre }}: {{ $accion }}"
                                                @checked($matrizFija || in_array("{$modulo->id}:{$accion}", $tildados))
                                                @disabled($matrizFija)
                                                class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800 disabled:opacity-60 disabled:cursor-not-allowed">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-admin.form>
</x-admin.page>
