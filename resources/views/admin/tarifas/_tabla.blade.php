@use('App\Support\Permisos')

<x-admin.table :headers="['Procedimiento', 'Profesional', 'Monto', 'Vigencia', 'Estado']" :paginator="$tarifas">
    @forelse ($tarifas as $tarifa)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $tarifa->procedimiento->nombre }} <span class="text-xs text-gray-500 dark:text-gray-400">({{ $tarifa->procedimiento->codigo }})</span></td>
            <td class="px-6 py-4">{{ $tarifa->profesionalTexto() }}</td>
            <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $tarifa->montoTexto() }}</td>
            <td class="px-6 py-4">{{ $tarifa->vigenciaTexto() }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$tarifa->estado" /></td>
            <x-admin.actions>
                @if ($tarifa->vigente() && ($cambiar = Permisos::url('admin.tarifas.cambiar', $tarifa)))
                    <a href="{{ $cambiar }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Cambiar tarifa</a>
                @endif
                <a href="{{ route('admin.tarifas.show', $tarifa) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Historial</a>
            </x-admin.actions>
        </tr>
    @empty
        <tr>
            <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                @if ($busqueda !== '')
                    No hay resultados para «{{ $busqueda }}».
                @else
                    Todavía no hay tarifas cargadas.
                @endif
            </td>
        </tr>
    @endforelse
</x-admin.table>
