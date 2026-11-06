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
                    @if ($puedeSalida && ! $miCajaDeOtroDia)
                        <a href="{{ route('admin.cajas.show', $miCaja) }}#salidas-caja" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Registrar una salida</a>
                    @endif
                </div>
            </section>
        @elseif ($puedeAbrir && $devueltas->isNotEmpty())
            <p role="status" class="max-w-3xl rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ \App\Support\Cobros\AbrirCaja::CON_DEVUELTA }}</p>
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
                    {{-- Con una sola sucursal activa se asigna sola; con más, el cajero la elige (AbrirCaja). --}}
                    @if ($sucursales->count() > 1)
                        <div>
                            <x-input-label for="sucursal_id" value="Sucursal (obligatorio)" />
                            <select id="sucursal_id" name="sucursal_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="">Elija la sucursal</option>
                                @foreach ($sucursales as $sucursal)
                                    <option value="{{ $sucursal->id }}" @selected((int) old('sucursal_id') === $sucursal->id)>{{ $sucursal->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('sucursal_id')" />
                        </div>
                    @endif
                    <x-primary-button>Abrir caja</x-primary-button>
                </form>
            </section>
        @endif

        @foreach ($devueltas as $devuelta)
            <p role="status" class="max-w-3xl rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                Gerencia devolvió por diferencias el arqueo de su caja del {{ Fecha::mostrar($devuelta->fecha) }}{{ $devuelta->ultimoCierre?->nota_revision ? ': '.$devuelta->ultimoCierre->nota_revision : '.' }}
                <a href="{{ route('admin.cajas.show', $devuelta) }}" class="font-medium underline">Corregir y reenviar</a>
            </p>
        @endforeach

        @if ($veTodas && $cantidadPendientes > 0)
            <p role="status" class="max-w-3xl rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                {{ $cantidadPendientes === 1 ? 'Hay 1 caja pendiente de recepción.' : "Hay {$cantidadPendientes} cajas pendientes de recepción." }}
                <a href="{{ route('admin.cajas.index', ['estado' => \App\Models\Estado::CERRADO]) }}" class="font-medium underline">Ver las pendientes</a>
            </p>
        @endif

        <section aria-labelledby="listado-cajas" class="space-y-3">
            <h3 id="listado-cajas" class="font-semibold text-gray-900 dark:text-gray-100">{{ $veTodas ? 'Todas las cajas' : 'Mis cajas' }}</h3>
            {{-- Estado, fechas y demás: en la fila de filtros de la grilla (docs/grillas.md). --}}
            <x-admin.listado :action="route('admin.cajas.index')" :buscador="false">
                @include('admin.cajas._tabla')
            </x-admin.listado>
        </section>
    </div>
</x-admin.page>
