@use('App\Support\Fecha')

{{-- Solicitudes de reversión pendientes (CobroController::reversiones, docs/cobros-caja.md): Gerencia aprueba en la misma fila o
     abre el cobro para rechazar con nota. Sin contenido clínico. --}}
<x-admin.page title="Cobros">
    @include('admin.cobros._pestanas', ['activa' => 'reversiones'])

    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Al aprobar, el cobro queda revertido y el dinero se devuelve desde la caja abierta de hoy de quien lo pidió, con los mismos medios
        y montos. Para rechazar, abra el cobro.
    </p>

    <div class="p-4">
        <x-admin.table :headers="['Pedida', 'Cobro', 'Paciente', 'Total', 'Medios', 'Solicitante', 'Motivo']">
            @forelse ($solicitudes as $solicitud)
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($solicitud->solicitada_en, conHora: true) }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">N.º {{ $solicitud->cobro->id }} · {{ Fecha::mostrar($solicitud->cobro->cobrado_en) }}</td>
                    <td class="px-6 py-4">{{ $solicitud->cobro->paciente->persona->nombre_completo }}</td>
                    <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $solicitud->cobro->totalTexto() }}</td>
                    <td class="px-6 py-4">{{ $solicitud->cobro->lineas->map(fn ($linea) => $linea->medioPago->nombre)->unique()->join(', ') }}</td>
                    <td class="px-6 py-4">{{ $solicitud->solicitante->persona?->nombre_completo ?? $solicitud->solicitante->email }}</td>
                    <td class="px-6 py-4">{{ $solicitud->motivo }}</td>
                    <x-admin.actions>
                        @if ($puedeRevisar && $solicitud->usuario_id !== auth()->id())
                            <form method="POST" action="{{ route('admin.cobros.reversiones.aprobar', $solicitud) }}" class="inline">
                                @csrf
                                <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Aprobar</button>
                            </form>
                            ·
                        @endif
                        <a href="{{ route('admin.cobros.show', $solicitud->cobro) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver el cobro</a>
                    </x-admin.actions>
                </tr>
            @empty
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">No hay solicitudes de reversión pendientes.</td></tr>
            @endforelse
        </x-admin.table>
    </div>
</x-admin.page>
