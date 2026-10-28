@use('App\Support\Fecha')
@use('App\Support\Liquidaciones\ArmarLiquidacion')

{{--
    Armar una liquidación (LiquidacionController::create y store), en una sola pantalla: la aseguradora y la fecha de corte; con las dos,
    la vista previa de las líneas, las que quedan afuera por falta de firma y "Confirmar". Sin JavaScript: "Ver las prestaciones" es un
    formulario GET y "Confirmar" lleva la cantidad y el total de la vista previa (si la lista cambió, el servidor pide revisarla).
--}}
<x-admin.page title="Armar liquidación">
    <div class="space-y-6 p-6 text-sm">
        <p class="text-gray-600 dark:text-gray-400">
            Entran todas las prestaciones de la aseguradora con la firma de la paciente recibida, no anuladas, sin liquidar y con fecha de
            servicio hasta el corte. Las que no tienen la firma no entran: quedan para la liquidación siguiente.
        </p>

        <form method="GET" action="{{ route('admin.liquidaciones.create') }}" novalidate class="flex flex-wrap items-end gap-3">
            <div class="w-72"><x-admin.select name="aseguradora" label="Aseguradora" :options="$aseguradoras" :value="$responsableId" required /></div>
            <div class="w-44"><x-admin.fecha name="corte" label="Fecha de corte" :value="$corteTexto" hasta-hoy /></div>
            <x-secondary-button type="submit">Ver las prestaciones</x-secondary-button>
            <a href="{{ route('admin.liquidaciones.index') }}" class="pb-2 text-gray-600 hover:underline dark:text-gray-400">Volver a Liquidaciones</a>
        </form>

        @if ($error)
            <p role="alert" class="rounded-md bg-red-50 p-3 text-red-800 dark:bg-red-900/30 dark:text-red-200">{{ $error }}</p>
        @endif

        @if ($prestaciones !== null)
            @if ($abierta)
                <p role="alert" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {{ ArmarLiquidacion::YA_ABIERTA }}
                    <a href="{{ route('admin.liquidaciones.show', $abierta) }}" class="font-medium underline">Ver la liquidación N.º {{ $abierta->id }} ({{ $abierta->etiquetaEstado() }})</a>
                </p>
            @endif

            <section aria-labelledby="vista-previa" class="space-y-3">
                <h3 id="vista-previa" class="font-semibold text-gray-900 dark:text-gray-100">
                    Vista previa: {{ $prestaciones->count() }} {{ $prestaciones->count() === 1 ? 'prestación' : 'prestaciones' }}, total {{ $moneda->formatear($total) }}
                </h3>
                <x-admin.table :headers="['Fecha', 'Paciente', 'Documento', 'N.º de asegurado', 'Procedimiento', 'Visación', 'Monto']">
                    @forelse ($prestaciones as $prestacion)
                        @php($fila = ArmarLiquidacion::snapshot($prestacion))
                        <tr>
                            <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($prestacion->fecha_servicio) }}</td>
                            <td class="px-6 py-3">{{ $fila['paciente'] }}</td>
                            <td class="px-6 py-3 whitespace-nowrap">{{ $fila['documento'] }}</td>
                            <td class="px-6 py-3">{{ $fila['numero_asegurado'] }}</td>
                            <td class="px-6 py-3">{{ $fila['procedimiento'] }}</td>
                            <td class="px-6 py-3">N.º {{ $fila['numero_visacion'] }}</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $moneda->formatear($fila['monto']) }}</td>
                            <td></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-4 text-gray-500 dark:text-gray-400">{{ ArmarLiquidacion::SIN_PRESTACIONES }}</td></tr>
                    @endforelse
                </x-admin.table>

                @if ($prestaciones->isNotEmpty() && ! $abierta)
                    <form method="POST" action="{{ route('admin.liquidaciones.store') }}" novalidate
                        x-data x-on:submit="if (! confirm('¿Armar la liquidación con estas {{ $prestaciones->count() }} prestaciones?')) $event.preventDefault()">
                        @csrf
                        <input type="hidden" name="responsable_pago_id" value="{{ $responsableId }}">
                        <input type="hidden" name="fecha_corte" value="{{ $corteTexto }}">
                        <input type="hidden" name="cantidad" value="{{ $prestaciones->count() }}">
                        <input type="hidden" name="total" value="{{ $total }}">
                        <x-primary-button>Confirmar y armar la liquidación</x-primary-button>
                    </form>
                @endif
            </section>

            <section aria-labelledby="sin-firma" class="space-y-3">
                <h3 id="sin-firma" class="font-semibold text-gray-900 dark:text-gray-100">
                    Quedan afuera por falta de firma: {{ $cantidadSinFirma }}
                </h3>
                @if ($cantidadSinFirma > 0)
                    <p class="text-gray-600 dark:text-gray-400">No entran en esta liquidación: marque la firma cuando la reciba y entrarán en la siguiente.</p>
                    @include('admin.liquidaciones._sin-firma', ['prestaciones' => $sinFirma])
                @endif
            </section>
        @endif
    </div>
</x-admin.page>
