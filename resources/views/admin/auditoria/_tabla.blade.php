@use('App\Support\Auditoria')
@use('App\Support\Fecha')

<x-admin.table :headers="['Fecha y hora', 'Usuario', 'Acción', 'Módulo', 'Registro', 'IP']" :paginator="$logs">
    @forelse ($logs as $log)
        <tr>
            <td class="px-6 py-4 whitespace-nowrap dato text-sm">{{ Fecha::mostrar($log->fecha_hora, conHora: true) }}</td>
            <td class="px-6 py-4">
                @if ($log->usuario)
                    {{ $log->usuario->name }}
                @else
                    {{-- Sin usuario: intento de inicio de sesión con un correo que no existe. --}}
                    <span class="text-gray-600 dark:text-gray-400">{{ preg_match('/^Correo: (\S+?)\.?(\s|$)/u', (string) $log->detalle, $m) ? $m[1] : '—' }}</span>
                @endif
            </td>
            <td class="px-6 py-4 whitespace-nowrap">{{ $log->accion->etiqueta() }}</td>
            <td class="px-6 py-4">{{ $modulos[Auditoria::modulosPorTabla()[$log->tabla_afectada] ?? ''] ?? $log->tabla_afectada }}</td>
            <td class="px-6 py-4 dato text-sm">{{ $log->registro_afectado_id ?? '—' }}</td>
            <td class="px-6 py-4 font-mono text-sm text-gray-600 dark:text-gray-400">{{ $log->ip_origen ?? '—' }}</td>
            <td class="px-6 py-4 text-right">
                <a href="{{ route('admin.auditoria.show', $log) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ver detalle</a>
            </td>
        </tr>
    @empty
        <x-admin.empty-row colspan="7" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
