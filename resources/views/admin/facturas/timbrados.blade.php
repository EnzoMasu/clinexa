@use('App\Support\Fecha')

{{-- Timbrados (TimbradoController::index, docs/facturacion.md): el vigente de cada punto de expedición y los históricos. Nada se borra
     ni se edita: cargar otro para el mismo punto pasa el anterior a histórico. --}}
<x-admin.page title="Facturas" :create-route="$puedeCargar ? route('admin.facturas.timbrados.create') : null" create-label="Cargar timbrado">
    @include('admin.facturas._pestanas', ['activa' => 'timbrados'])

    @if ($aviso)
        <p role="alert" class="mx-4 mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ $aviso }}</p>
    @endif
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Se emite con el timbrado vigente de la sucursal principal (un solo punto de expedición). La numeración la asigna el sistema, de a
        uno, sin saltos; un número anulado no se vuelve a usar. Los datos fiscales del emisor salen de los
        <a href="{{ route('admin.clinica.edit') }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">datos de la clínica</a>.
    </p>

    <div class="p-4">
        <x-admin.table :headers="['Timbrado', 'Sucursal', 'Punto', 'Vigencia', 'Numeración', 'Último usado', 'Estado']" :paginator="$timbrados">
            @forelse ($timbrados as $timbrado)
                <tr>
                    <td class="px-6 py-4 font-medium tabular-nums">{{ $timbrado->numero }}</td>
                    <td class="px-6 py-4">{{ $timbrado->sucursal->nombre }}</td>
                    <td class="px-6 py-4 tabular-nums">{{ $timbrado->punto() }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">{{ $timbrado->vigenciaTexto() }}</td>
                    <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $timbrado->numero_inicial }} a {{ $timbrado->numero_final }}</td>
                    <td class="px-6 py-4 whitespace-nowrap tabular-nums">
                        {{ $timbrado->numero_actual >= $timbrado->numero_inicial ? $timbrado->numero_actual : 'Ninguno' }}
                        <span class="block text-xs text-gray-500 dark:text-gray-400">Quedan {{ $timbrado->disponibles() }}</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        {{ $timbrado->etiquetaEstado() }}
                        @if ($timbrado->historico())
                            <span class="block text-xs text-gray-500 dark:text-gray-400">desde el {{ Fecha::mostrar($timbrado->historico_en) }}</span>
                        @endif
                    </td>
                    <x-admin.actions>
                        @if ($puedeHistorico && ! $timbrado->historico())
                            <form method="POST" action="{{ route('admin.facturas.timbrados.historico', $timbrado) }}" class="inline" onsubmit="return confirm('¿Pasar este timbrado a histórico? Ya no va a emitir facturas.')">
                                @csrf
                                <button type="submit" class="font-medium text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Pasar a histórico</button>
                            </form>
                        @endif
                    </x-admin.actions>
                </tr>
            @empty
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Todavía no hay timbrados.</td></tr>
            @endforelse
        </x-admin.table>
    </div>
</x-admin.page>
