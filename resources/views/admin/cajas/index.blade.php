@use('App\Support\Fecha')

{{-- Cajas (CajaController::index, docs/cobros-caja.md): "Mi caja" del cajero y el listado (las suyas, o todas con VER sobre CIERRES_CAJA). --}}
<x-admin.page title="Cajas">
    <div class="space-y-6 p-6 text-sm">
        @if ($miCaja)
            <section aria-labelledby="mi-caja" class="max-w-3xl space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                <h3 id="mi-caja" class="font-semibold text-gray-900 dark:text-gray-100">Mi caja</h3>
                <p class="text-gray-700 dark:text-gray-300">
                    Abierta el {{ Fecha::mostrar($miCaja->abierta_en, conHora: true) }}, con {{ $miCaja->montoInicialTexto() }} en efectivo.
                </p>
                @if ($miCajaDeOtroDia)
                    <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ $miCajaDeOtroDia }}</p>
                @endif
                <div class="flex flex-wrap items-center gap-4">
                    @if ($puedeCobrar && ! $miCajaDeOtroDia)
                        <a href="{{ route('admin.cobros.create') }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white">Cobrar</a>
                    @endif
                    <a href="{{ route('admin.cajas.show', $miCaja) }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Ver la caja y cerrarla</a>
                </div>
            </section>
        @elseif ($puedeAbrir)
            <section aria-labelledby="abrir-caja" class="max-w-xl space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                <h3 id="abrir-caja" class="font-semibold text-gray-900 dark:text-gray-100">Abrir mi caja</h3>
                <p class="text-gray-600 dark:text-gray-400">No tiene una caja abierta. Para cobrar, ábrala con el efectivo con que empieza el día.</p>
                <form method="POST" action="{{ route('admin.cajas.abrir') }}" novalidate class="space-y-3">
                    @csrf
                    <div>
                        <x-admin.input name="monto_inicial" label="Monto inicial en efectivo (₲)" inputmode="numeric" maxlength="17" autocomplete="off" value="0" required />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Sin decimales, por ejemplo 100000 o 100.000. Cero si empieza sin efectivo.</p>
                    </div>
                    <x-primary-button>Abrir caja</x-primary-button>
                </form>
            </section>
        @endif

        @foreach ($devueltas as $devuelta)
            <p role="status" class="max-w-3xl rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                Gerencia devolvió el cierre de su caja del {{ Fecha::mostrar($devuelta->fecha) }}{{ $devuelta->ultimoCierre?->nota_revision ? ': '.$devuelta->ultimoCierre->nota_revision : '.' }}
                <a href="{{ route('admin.cajas.show', $devuelta) }}" class="font-medium underline">Corregir y volver a cerrar</a>
            </p>
        @endforeach

        @if ($veTodas && $cantidadPendientes > 0)
            <p role="status" class="max-w-3xl rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                {{ $cantidadPendientes === 1 ? 'Hay 1 caja cerrada pendiente de aprobación.' : "Hay {$cantidadPendientes} cajas cerradas pendientes de aprobación." }}
                <a href="{{ route('admin.cajas.index', ['estado' => \App\Models\Estado::CERRADO]) }}" class="font-medium underline">Ver las pendientes</a>
            </p>
        @endif

        <section aria-labelledby="listado-cajas" class="space-y-3">
            <h3 id="listado-cajas" class="font-semibold text-gray-900 dark:text-gray-100">{{ $veTodas ? 'Todas las cajas' : 'Mis cajas' }}</h3>
            <form method="GET" action="{{ route('admin.cajas.index') }}" class="flex flex-wrap items-end gap-3">
                <div class="w-64"><x-admin.select name="estado" label="Estado" :options="$opcionesEstado" :value="$filtroEstado" nullable /></div>
                <x-secondary-button type="submit">Filtrar</x-secondary-button>
            </form>
            <x-admin.table :headers="['Fecha', 'Cajero', 'Monto inicial', 'Cobros', 'Total cobrado', 'Estado']" :paginator="$cajas">
                @forelse ($cajas as $caja)
                    <tr>
                        <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($caja->fecha) }}</td>
                        <td class="px-6 py-3">{{ $caja->cajero->persona?->nombre_completo ?? $caja->cajero->email }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->montoInicialTexto() }}</td>
                        <td class="px-6 py-3 tabular-nums">{{ $caja->cobros_count }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear($caja->cobros_sum_total ?? 0) }}</td>
                        <td class="px-6 py-3">{{ $caja->etiquetaEstado() }}</td>
                        <td class="px-6 py-3 text-right">
                            <a href="{{ route('admin.cajas.show', $caja) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">No hay cajas con este filtro.</td></tr>
                @endforelse
            </x-admin.table>
        </section>
    </div>
</x-admin.page>
