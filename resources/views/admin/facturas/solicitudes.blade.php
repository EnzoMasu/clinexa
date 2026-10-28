@use('App\Support\Fecha')

{{-- Solicitudes de anulación pendientes (FacturaController::solicitudes, docs/facturacion.md): Gerencia aprueba en la misma fila o abre
     la factura para rechazar con nota. Sin contenido clínico. --}}
<x-admin.page title="Facturas">
    @include('admin.facturas._pestanas', ['activa' => 'solicitudes'])

    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Al aprobar, el cobro de la factura se revierte (el dinero sale de la caja abierta de hoy de quien la pidió) y la factura queda anulada,
        con su número. Nadie aprueba su propia solicitud. Para rechazar, abra la factura.
    </p>

    <div class="p-4">
        <x-admin.table :headers="['Pedida', 'Factura', 'Cliente', 'Total', 'Solicitante', 'Motivo']">
            @forelse ($solicitudes as $solicitud)
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($solicitud->solicitada_en, conHora: true) }}</td>
                    <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $solicitud->factura->numero_completo }} · {{ Fecha::mostrar($solicitud->factura->emitida_en) }}</td>
                    <td class="px-6 py-4">{{ $solicitud->factura->cliente_nombre }}</td>
                    <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $solicitud->factura->totalTexto() }}</td>
                    <td class="px-6 py-4">{{ $solicitud->solicitante->persona?->nombre_completo ?? $solicitud->solicitante->email }}</td>
                    <td class="px-6 py-4">{{ $solicitud->motivoTexto() }}</td>
                    <x-admin.actions>
                        @if ($puedeRevisar && $solicitud->usuario_id !== auth()->id())
                            <form method="POST" action="{{ route('admin.facturas.solicitudes.aprobar', $solicitud) }}" class="inline">
                                @csrf
                                <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Aprobar</button>
                            </form>
                            ·
                        @endif
                        <a href="{{ route('admin.facturas.show', $solicitud->factura) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver la factura</a>
                    </x-admin.actions>
                </tr>
            @empty
                <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">No hay solicitudes de anulación pendientes.</td></tr>
            @endforelse
        </x-admin.table>
    </div>
</x-admin.page>
