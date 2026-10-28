{{-- Tasas de IVA de los procedimientos (TasaIvaController::index, docs/facturacion.md). Precios con IVA incluido; la tasa se copia a
     cada línea al facturar. --}}
<x-admin.page title="Facturas">
    @include('admin.facturas._pestanas', ['activa' => 'tasas'])

    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Las tarifas son con IVA incluido. IVA 10 % = monto ÷ 11 y 5 % = monto ÷ 21, redondeado al guaraní; la exenta no lleva IVA. Cambiar
        la tasa vale para las facturas nuevas: las emitidas no cambian.
    </p>

    <form method="GET" action="{{ route('admin.facturas.tasas-iva') }}" role="search" class="flex items-end gap-3 p-4">
        <div class="w-72"><x-admin.input name="q" label="Buscar procedimiento" :value="$busqueda" /></div>
        <x-secondary-button type="submit">Buscar</x-secondary-button>
    </form>

    <div class="p-4 pt-0">
        <x-admin.table :headers="['Código', 'Procedimiento', 'Tasa de IVA']" :paginator="$procedimientos">
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
    </div>
</x-admin.page>
