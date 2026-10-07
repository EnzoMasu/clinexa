<x-admin.page title="Auditoría">
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Registro de solo lectura de los cambios de datos, las lecturas de los módulos sensibles y los eventos de inicio de sesión.
        No se puede modificar ni borrar.
    </p>

    <x-admin.listado :action="route('admin.auditoria.index')" :busqueda="$busqueda" placeholder="Buscar por registro o detalle…">
        <x-slot name="filtros">
            <div>
                <x-input-label for="filtro_usuario" value="Usuario" />
                <select id="filtro_usuario" name="usuario" class="mt-1 block w-56 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Todos</option>
                    @foreach ($usuarios as $valor => $nombre)
                        <option value="{{ $valor }}" @selected($filtros['usuario'] === (string) $valor)>{{ $nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="filtro_accion" value="Acción" />
                <select id="filtro_accion" name="accion" class="mt-1 block w-48 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Todas</option>
                    @foreach ($acciones as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected($filtros['accion'] === $valor)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="filtro_modulo" value="Módulo" />
                <select id="filtro_modulo" name="modulo" class="mt-1 block w-48 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Todos</option>
                    @foreach ($modulos as $codigo => $nombre)
                        <option value="{{ $codigo }}" @selected($filtros['modulo'] === $codigo)>{{ $nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-40">
                <x-admin.fecha name="desde" label="Desde" :value="$filtros['desde']" />
            </div>
            <div class="w-40">
                <x-admin.fecha name="hasta" label="Hasta" :value="$filtros['hasta']" />
            </div>
        </x-slot>

        @include('admin.auditoria._tabla')
    </x-admin.listado>
</x-admin.page>
