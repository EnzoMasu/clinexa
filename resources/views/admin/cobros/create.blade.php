@use('App\Support\Fecha')
@use('App\Http\Controllers\Admin\CobroController')
@use('App\Support\Cobros\Cobrar')
@use('App\Models\Prestacion')

{{-- Pantalla de cobro (CobroController::create, docs/cobros-caja.md), en un solo flujo: buscar la paciente, tildar sus prestaciones
     sin cobrar, cargar los medios y montos y confirmar. El total es la suma de las tildadas; los pagos deben sumar exactamente eso.
     Desde la facturación (docs/facturacion.md), confirmar emite la factura contado y registra el cobro contra ella, en una sola acción. --}}
<x-admin.page title="Cobrar">
    @if ($facturaActiva)
        @include('admin.facturas._simulacion')
    @endif
    <div class="space-y-6 p-6 text-sm">
        @if ($sinFactura)
            <p role="alert" class="max-w-3xl rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ $sinFactura }}</p>
        @endif
        @if ($reemplazaA)
            <p role="status" class="max-w-3xl rounded-md bg-indigo-50 p-3 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-200">
                La factura nueva reemplaza a la {{ $reemplazaA->numero_completo }} (anulada).
            </p>
        @endif
        @if ($sinCaja)
            <p role="status" class="max-w-3xl rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                {{ $sinCaja }}
                @if (\App\Support\Permisos::puedeRuta('admin.cajas.index'))
                    <a href="{{ route('admin.cajas.index') }}" class="font-medium underline">Ir a Cajas</a>
                @endif
            </p>
        @endif

        @if (! $paciente)
            <form method="GET" action="{{ route('admin.cobros.create') }}" role="search" class="flex max-w-xl flex-wrap items-end gap-3">
                <div class="grow">
                    <x-input-label for="q" value="Paciente" />
                    <x-text-input id="q" name="q" type="search" :value="$busqueda" placeholder="Apellido, nombre, documento o ficha…" autocomplete="off" autofocus class="mt-1 block w-full" />
                </div>
                <x-secondary-button type="submit">Buscar</x-secondary-button>
            </form>

            <section aria-labelledby="con-pendientes" class="max-w-4xl space-y-3">
                <h3 id="con-pendientes" class="font-semibold text-gray-900 dark:text-gray-100">Pacientes con prestaciones sin cobrar</h3>
                <x-admin.table :headers="['Paciente', 'Prestaciones', 'Total']">
                    @forelse ($pacientes as ['paciente' => $conPendientes, 'cantidad' => $cantidad, 'total' => $total])
                        <tr>
                            <td class="px-6 py-3">
                                <span class="font-medium">{{ $conPendientes->persona->nombre_completo }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $conPendientes->nro_ficha }} · {{ $conPendientes->persona->nro_documento }}</span>
                            </td>
                            <td class="px-6 py-3 tabular-nums">{{ $cantidad }}</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $moneda->formatear($total) }}</td>
                            <td class="px-6 py-3 text-right">
                                <a href="{{ route('admin.cobros.create', ['paciente' => $conPendientes->id]) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Elegir</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                {{ $busqueda !== '' ? "No hay pacientes con prestaciones sin cobrar para «{$busqueda}»." : 'No hay prestaciones sin cobrar.' }}
                            </td>
                        </tr>
                    @endforelse
                </x-admin.table>
            </section>
        @else
            <p class="text-gray-700 dark:text-gray-300">
                Paciente: <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $paciente->persona->nombre_completo }}</span>
                ({{ $paciente->nro_ficha }} · {{ $paciente->persona->nro_documento }})
                · <a href="{{ route('admin.cobros.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Elegir otra paciente</a>
            </p>

            @if ($aCargoDeAseguradora->isNotEmpty())
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    <p class="font-medium">{{ Prestacion::A_CARGO_DE_ASEGURADORA }} (no se cobran a la paciente):</p>
                    <ul class="list-disc ps-5">
                        @foreach ($aCargoDeAseguradora as $prestacion)
                            <li>{{ Fecha::mostrar($prestacion->fecha_servicio) }} · {{ $prestacion->procedimiento?->nombre ?? 'Sin procedimiento' }} · Visación N.º {{ $prestacion->numero_visacion }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($prestaciones->isEmpty())
                <p class="text-gray-600 dark:text-gray-400">No tiene prestaciones sin cobrar (las pendientes de tarifar se cobran después de tarifarlas).</p>
            @else
                <form method="POST" action="{{ route('admin.cobros.store') }}" novalidate x-data="pantallaCobro" class="max-w-4xl space-y-6">
                    @csrf
                    <input type="hidden" name="paciente_id" value="{{ $paciente->id }}">
                    @if ($reemplazaA)
                        <input type="hidden" name="reemplaza_a_id" value="{{ $reemplazaA->id }}">
                    @endif

                    <fieldset class="space-y-3">
                        <legend class="font-semibold text-gray-900 dark:text-gray-100">Prestaciones sin cobrar</legend>
                        <x-admin.table :headers="['Cobrar', 'Fecha de servicio', 'Procedimiento', 'Profesional', 'A cobrar']">
                            @foreach ($prestaciones as $prestacion)
                                @php($elegida = in_array((string) $prestacion->id, array_map('strval', (array) old('prestaciones', $prestaciones->modelKeys())), true))
                                <tr>
                                    <td class="px-6 py-3">
                                        <input type="checkbox" id="prestacion_{{ $prestacion->id }}" name="prestaciones[]" value="{{ $prestacion->id }}" data-monto="{{ $prestacion->montoPaciente() }}"
                                            @checked($elegida) x-on:change="alTildar()"
                                            class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900">
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap"><label for="prestacion_{{ $prestacion->id }}">{{ Fecha::mostrar($prestacion->fecha_servicio) }}</label></td>
                                    <td class="px-6 py-3">{{ $prestacion->procedimiento?->nombre ?? 'Sin procedimiento' }}</td>
                                    <td class="px-6 py-3">{{ $prestacion->profesional->persona->nombre_completo }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">
                                        {{ $prestacion->montoPacienteTexto() }}
                                        {{-- Con aseguradora (docs/convenios-seguros.md) se cobra solo la parte de la paciente. --}}
                                        @if ($prestacion->deAseguradora())
                                            <div class="text-xs text-gray-500 dark:text-gray-400">Parte de la paciente (total {{ $prestacion->montoTexto() }})</div>
                                        @endif
                                    </td>
                                    <td></td>
                                </tr>
                            @endforeach
                        </x-admin.table>
                        <p class="font-semibold text-gray-900 dark:text-gray-100" aria-live="polite">Total: <span x-text="totalTexto">{{ $moneda->formatear($prestaciones->sum(fn ($p) => $p->montoPaciente())) }}</span></p>
                    </fieldset>

                    <fieldset class="space-y-3">
                        <legend class="font-semibold text-gray-900 dark:text-gray-100">Pagos</legend>
                        <p class="text-gray-600 dark:text-gray-400">Puede combinar medios. La suma debe ser exactamente el total (el vuelto no se registra). Las filas vacías no cuentan.</p>
                        @for ($i = 0; $i < CobroController::LINEAS; $i++)
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <x-input-label for="lineas_{{ $i }}_medio" value="Medio de pago" />
                                    <select id="lineas_{{ $i }}_medio" name="lineas[{{ $i }}][medio_pago_id]"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                        <option value="">—</option>
                                        @foreach ($medios as $medio)
                                            <option value="{{ $medio->id }}" @selected((string) old("lineas.{$i}.medio_pago_id", $i === 0 && $medio->esEfectivo() ? $medio->id : '') === (string) $medio->id)>{{ $medio->nombre }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <x-input-label for="lineas_{{ $i }}_monto" value="Monto (₲)" />
                                    <input id="lineas_{{ $i }}_monto" name="lineas[{{ $i }}][monto]" data-pago inputmode="numeric" maxlength="17" autocomplete="off"
                                        value="{{ old("lineas.{$i}.monto", $i === 0 ? (string) $prestaciones->sum(fn ($p) => $p->montoPaciente()) : '') }}" x-on:input="recalcular()"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <x-input-error class="mt-1" :messages="$errors->get('lineas.'.$i.'.monto')" />
                                </div>
                                <div>
                                    <x-input-label for="lineas_{{ $i }}_referencia" value="Referencia (opcional)" />
                                    <input id="lineas_{{ $i }}_referencia" name="lineas[{{ $i }}][referencia]" value="{{ old("lineas.{$i}.referencia") }}" maxlength="{{ Cobrar::REFERENCIA_MAXIMA }}" autocomplete="off"
                                        placeholder="N.º de transferencia o del POS"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                </div>
                            </div>
                        @endfor
                        <p class="text-gray-700 dark:text-gray-300" aria-live="polite" x-show="total > 0" style="display: none">
                            Pagos: <span class="font-medium" x-text="pagosTexto"></span>.
                            <span x-bind:class="cuadra ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300'" x-text="diferenciaTexto"></span>
                        </p>
                    </fieldset>

                    <x-input-error :messages="$errors->get('prestaciones')" />
                    @if ($facturaActiva)
                        <p class="text-gray-600 dark:text-gray-400">Al confirmar se emite la factura contado a nombre de la paciente ({{ $paciente->persona->documentoPrincipal() ?: 'sin documento' }}) y se registra el cobro contra ella.</p>
                    @endif
                    <x-primary-button :disabled="(bool) $sinCaja || (bool) $sinFactura">{{ $facturaActiva ? 'Facturar y cobrar' : 'Confirmar el cobro' }}</x-primary-button>
                </form>
            @endif
        @endif
    </div>
</x-admin.page>
