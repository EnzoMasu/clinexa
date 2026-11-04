{{-- Tabla de tasas de IVA (TasaIvaController::index): también la respuesta de la búsqueda en vivo. --}}
<x-admin.table :grilla="$grilla" :paginator="$procedimientos" :acciones="false">
    @forelse ($procedimientos as $procedimiento)
        <tr>
            <td class="px-6 py-4 tabular-nums">{{ $procedimiento->codigo }}</td>
            <td class="px-6 py-4 font-medium">{{ $procedimiento->nombre }}</td>
            <td class="px-6 py-4">
                @if ($puedeEditar)
                    <form method="POST" action="{{ route('admin.facturas.tasas-iva.update', $procedimiento) }}" class="flex items-center gap-2">
                        @csrf
                        @method('PATCH')
                        <label for="tasa-{{ $procedimiento->id }}" class="sr-only">Tasa de IVA de {{ $procedimiento->nombre }}</label>
                        <select id="tasa-{{ $procedimiento->id }}" name="tasa_iva" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            @foreach (App\Models\Procedimiento::TASAS_IVA as $tasa => $texto)
                                <option value="{{ $tasa }}" @selected($procedimiento->tasa_iva === $tasa)>{{ $texto }}</option>
                            @endforeach
                        </select>
                        <x-secondary-button type="submit">Guardar</x-secondary-button>
                    </form>
                @else
                    {{ App\Models\Procedimiento::TASAS_IVA[$procedimiento->tasa_iva] ?? $procedimiento->tasa_iva }}
                @endif
            </td>
            <td></td>
        </tr>
    @empty
        <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">No hay procedimientos.</td></tr>
    @endforelse
</x-admin.table>
