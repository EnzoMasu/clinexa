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

    {{-- Anulaciones de salidas de caja pasado el plazo (Cobros\AnularSalida): la misma solicitud y la misma revisión. Al aprobar, la
         salida queda anulada y su monto vuelve al efectivo de su caja, que tiene que seguir abierta. --}}
    <section aria-labelledby="anulaciones-salida" class="space-y-3 p-4">
        <h3 id="anulaciones-salida" class="font-semibold text-gray-900 dark:text-gray-100">Anulaciones de salidas de caja ({{ $solicitudesSalida->count() }})</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Al aprobar, la salida queda anulada y su monto vuelve al efectivo de la misma caja. Solo mientras esa caja siga abierta; si ya se
            cerró, rechace la solicitud.
        </p>
        <x-admin.table :headers="['Pedida', 'Salida', 'Categoría', 'Beneficiario', 'Monto', 'Caja', 'Solicitante', 'Motivo']">
            @forelse ($solicitudesSalida as $solicitud)
                @php($salida = $solicitud->salida)
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($solicitud->solicitada_en, conHora: true) }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">N.º {{ $salida->id }} · {{ Fecha::mostrar($salida->registrada_en, conHora: true) }}</td>
                    <td class="px-6 py-4">{{ $salida->categoria->nombre }}</td>
                    <td class="px-6 py-4">{{ $salida->beneficiario }}</td>
                    <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $salida->caja->moneda->formatear($salida->monto) }}</td>
                    <td class="px-6 py-4">{{ $salida->caja->etiquetaEstado() }}</td>
                    <td class="px-6 py-4">{{ $solicitud->solicitante->persona?->nombre_completo ?? $solicitud->solicitante->email }}</td>
                    <td class="px-6 py-4">{{ $solicitud->motivo }}</td>
                    <x-admin.actions>
                        @if ($puedeRevisar && $solicitud->usuario_id !== auth()->id())
                            <form method="POST" action="{{ route('admin.cobros.reversiones.aprobar', $solicitud) }}" class="inline">
                                @csrf
                                <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Aprobar</button>
                            </form>
                            <details class="mt-2 text-left">
                                <summary class="cursor-pointer font-medium text-amber-700 dark:text-amber-300">Rechazar</summary>
                                <form method="POST" action="{{ route('admin.cobros.reversiones.rechazar', $solicitud) }}" novalidate class="mt-2 w-56 space-y-2">
                                    @csrf
                                    <label for="nota_rechazo_{{ $solicitud->id }}" class="block text-xs text-gray-600 dark:text-gray-400">Por qué se rechaza (obligatorio)</label>
                                    <textarea id="nota_rechazo_{{ $solicitud->id }}" name="nota_rechazo" rows="2" required minlength="{{ \App\Support\Cobros\AnularSalida::MOTIVO_MINIMO }}" maxlength="{{ \App\Support\Cobros\AnularSalida::MOTIVO_MAXIMO }}"
                                        class="block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"></textarea>
                                    <x-input-error :messages="$errors->get('nota_rechazo')" />
                                    <x-secondary-button type="submit">Rechazar</x-secondary-button>
                                </form>
                            </details>
                        @endif
                        @if (auth()->user()->tienePermiso('CIERRES_CAJA', 'VER') || $salida->caja->usuario_id === auth()->id())
                            <a href="{{ route('admin.cajas.show', $salida->caja_id) }}#salidas-caja" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver la caja</a>
                        @endif
                    </x-admin.actions>
                </tr>
            @empty
                <tr><td colspan="9" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">No hay solicitudes de anulación de salidas pendientes.</td></tr>
            @endforelse
        </x-admin.table>
    </section>
</x-admin.page>
