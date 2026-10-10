@use('App\Support\Fecha')
@use('App\Support\Permisos')

<x-admin.table :headers="['Nro. ficha', 'Nombre', 'Documento', 'Fecha de alta', 'Estado']" :paginator="$registros">
    @forelse ($registros as $registro)
        <tr>
            <td class="px-6 py-4 dato font-medium">{{ $registro->nro_ficha }}</td>
            <td class="px-6 py-4 font-medium">{{ $registro->persona->nombre_completo }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $registro->persona->tipoDocumento->codigo }}</span>
                <span class="dato">{{ $registro->persona->nro_documento }}</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($registro->fecha_alta) }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$registro->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.pacientes.edit', $registro)"
                :desactivar="$registro->estaActivo() ? Permisos::url('admin.pacientes.desactivar', $registro) : null">
                {{-- Con VER sobre la historia clínica. --}}
                @if ($registro->historiaClinica && ($urlHistoria = Permisos::url('admin.historias-clinicas.show', $registro->historiaClinica)))
                    <a href="{{ $urlHistoria }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Historia clínica</a>
                @endif
            </x-admin.actions>
        </tr>
    @empty
        <x-admin.empty-row colspan="6" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
