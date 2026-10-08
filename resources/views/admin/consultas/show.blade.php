@use('App\Support\Fecha')

<x-admin.page title="Consulta">
    <div class="mx-auto max-w-4xl space-y-4 p-4 text-sm sm:p-6">
        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($puedeModificar)
                <a href="{{ route('admin.consultas.edit', $consulta) }}"
                    class="inline-flex items-center rounded-md bg-gray-800 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white">Editar</a>
            @endif
            <a href="{{ route('admin.historias-clinicas.show', $consulta->historiaClinica) }}"
                class="text-sm text-gray-600 hover:underline dark:text-gray-400">Volver a la historia</a>
        </div>

        @include('admin.consultas._contenido', ['consulta' => $consulta, 'puedeModificar' => $puedeModificar])

        {{-- Solo con VER sobre Auditoría (su lectura se registra en el controlador). Plegado: es solo visual. --}}
        @if ($historial !== null)
            <details class="rounded-md border border-gray-200 dark:border-gray-700">
                <summary class="cursor-pointer select-none px-4 py-2 font-medium text-gray-900 dark:text-gray-100">Historial de cambios</summary>
                <x-admin.table :headers="['Fecha y hora', 'Usuario', 'Acción', 'Qué cambió']">
                    @forelse ($historial as $evento)
                        <tr>
                            <td class="px-6 py-3 whitespace-nowrap font-mono text-sm">{{ Fecha::mostrar($evento->fecha_hora, conHora: true) }}</td>
                            <td class="px-6 py-3">{{ $evento->usuario?->name ?? 'Sin usuario' }}</td>
                            <td class="px-6 py-3">{{ $evento->accion->etiqueta() }}</td>
                            <td class="px-6 py-3 text-gray-600 dark:text-gray-400">
                                {{ collect(array_keys($evento->valor_nuevo ?? []))->map(fn ($campo) => ['bloquesAnamnesis' => 'anamnesis', 'examenFisico' => 'examen físico', 'diagnosticos' => 'diagnósticos', 'motivo_consulta' => 'motivo'][$campo] ?? $campo)->join(', ') }}
                            </td>
                            <td class="px-6 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('admin.auditoria.show', $evento) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ver detalle</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-3 text-sm text-gray-500 dark:text-gray-400">Sin cambios registrados.</td></tr>
                    @endforelse
                </x-admin.table>
            </details>
        @endif
    </div>
</x-admin.page>
