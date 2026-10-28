@use('App\Support\Permisos')

<x-admin.table :headers="['Documento', 'Nombre / Razón social', 'Tipo', 'Contacto', 'Estado']" :paginator="$personas">
    @forelse ($personas as $persona)
        <tr>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="dato font-medium">{{ $persona->documentoPrincipal() ?: '—' }}</span>
                {{-- Lo ya guardado no se rechaza: se marca y se corrige a mano. --}}
                @if ($persona->faltaRuc())
                    <span class="ml-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">falta RUC</span>
                @elseif ($persona->faltaCi())
                    <span class="ml-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">falta CI</span>
                @endif
            </td>
            <td class="px-6 py-4">
                <div class="font-medium">{{ $persona->nombre_completo }}</div>
                @if ($persona->nombre_fantasia)
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $persona->nombre_fantasia }}</div>
                @endif
            </td>
            <td class="px-6 py-4">{{ $persona->tipo_persona }}</td>
            <td class="px-6 py-4">
                <div>{{ $persona->email }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $persona->telefono }}</div>
            </td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$persona->estado" /></td>
            {{-- La persona del administrador principal: solo él la edita y nadie la desactiva. --}}
            <x-admin.actions
                :edit="$persona->esDelAdministradorPrincipalAjeno() ? null : Permisos::url('admin.personas.edit', $persona)"
                :desactivar="$persona->estaActivo() && ! $persona->esDelAdministradorPrincipal() ? Permisos::url('admin.personas.desactivar', $persona) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="6" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
