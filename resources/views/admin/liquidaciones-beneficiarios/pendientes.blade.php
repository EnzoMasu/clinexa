@use('App\Support\Fecha')
@use('App\Models\LineaReparto')

{{--
    Pendientes de liquidar (LiquidacionBeneficiarioController::pendientes): por beneficiario, lo que ya se puede liquidar (con "Armar") y lo
    que todavía no, con el motivo: sin cobrar a la paciente, la aseguradora sin pagar o pendiente de reparto. Sin nada clínico.
--}}
<x-admin.page title="Liquidaciones a beneficiarios">
    @include('admin.liquidaciones-beneficiarios._pestanas')

    <div class="space-y-8 p-6 text-sm">
        <form method="GET" action="{{ route('admin.liquidaciones-beneficiarios.pendientes') }}" class="flex flex-wrap items-end gap-3">
            <div class="w-96"><x-admin.select name="beneficiario" label="Beneficiario" :options="$opcionesBeneficiario" :value="$filtros['beneficiario']" nullable /></div>
            <x-secondary-button type="submit">Filtrar</x-secondary-button>
        </form>

        <section aria-labelledby="listas" class="space-y-3">
            <h3 id="listas" class="font-semibold text-gray-900 dark:text-gray-100">Listas para liquidar</h3>
            <p class="text-gray-600 dark:text-gray-400">Percibidas (cobradas a la paciente y, si hay aseguradora, con su liquidación pagada) y todavía sin liquidar, de cualquier fecha.</p>
            <x-admin.table :headers="['Beneficiario', 'Tipo', 'Líneas', 'Total']">
                @forelse ($listas as $fila)
                    <tr>
                        <td class="px-6 py-3 font-medium">{{ $fila['beneficiario'] }}</td>
                        <td class="px-6 py-3">{{ $fila['tipo'] }}</td>
                        <td class="px-6 py-3 tabular-nums">{{ $fila['cantidad'] }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $moneda->formatear($fila['total']) }}</td>
                        <td class="px-6 py-3 text-right">
                            @if ($puedeArmar)
                                <a href="{{ route('admin.liquidaciones-beneficiarios.create', ['beneficiario' => $fila['clave']]) }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Armar</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-4 text-gray-500 dark:text-gray-400">Nada listo para liquidar.</td></tr>
                @endforelse
            </x-admin.table>
        </section>

        <section aria-labelledby="todavia-no" class="space-y-3">
            <h3 id="todavia-no" class="font-semibold text-gray-900 dark:text-gray-100">Todavía no se pueden liquidar: {{ $cantidad }}</h3>
            @if ($cantidad > count($filas))
                <p class="text-gray-600 dark:text-gray-400">Se muestran las {{ count($filas) }} más antiguas.</p>
            @endif
            <x-admin.table :headers="['Prestación', 'Fecha', 'Procedimiento', 'Beneficiario', 'Monto', 'Motivo']">
                @forelse ($filas as $fila)
                    <tr>
                        <td class="px-6 py-3 whitespace-nowrap">
                            @if ($vePrestaciones)
                                <a href="{{ route('admin.prestaciones.show', $fila['prestacion']) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">N.º {{ $fila['prestacion']->id }}</a>
                            @else
                                N.º {{ $fila['prestacion']->id }}
                            @endif
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($fila['prestacion']->fecha_servicio) }}</td>
                        <td class="px-6 py-3">{{ $fila['prestacion']->procedimiento?->nombre ?? 'Sin procedimiento' }}</td>
                        <td class="px-6 py-3">{{ LineaReparto::ETIQUETAS[$fila['linea']->beneficiario] }} · {{ $fila['linea']->beneficiarioTexto() }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $moneda->formatear($fila['linea']->monto) }}</td>
                        <td class="px-6 py-3">{{ $fila['motivo'] }}</td>
                        <td></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-6 py-4 text-gray-500 dark:text-gray-400">Nada pendiente.</td></tr>
                @endforelse
            </x-admin.table>
        </section>

        <section aria-labelledby="sin-reparto" class="space-y-3">
            <h3 id="sin-reparto" class="font-semibold text-gray-900 dark:text-gray-100">Pendientes de reparto: {{ $cantidadSinReparto }}</h3>
            <p class="text-gray-600 dark:text-gray-400">Sin líneas todavía: falta un acuerdo (a un funcionario se le carga un acuerdo general de 0 %). Se reparten desde la prestación.</p>
            <x-admin.table :headers="['Prestación', 'Fecha', 'Profesional', 'Procedimiento', 'Motivo']">
                @forelse ($sinReparto as $reparto)
                    <tr>
                        <td class="px-6 py-3 whitespace-nowrap">
                            @if ($vePrestaciones)
                                <a href="{{ route('admin.prestaciones.show', $reparto->prestacion_id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">N.º {{ $reparto->prestacion_id }}</a>
                            @else
                                N.º {{ $reparto->prestacion_id }}
                            @endif
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($reparto->prestacion->fecha_servicio) }}</td>
                        <td class="px-6 py-3">{{ $reparto->prestacion->profesional?->persona?->nombre_completo }}</td>
                        <td class="px-6 py-3">{{ $reparto->prestacion->procedimiento?->nombre ?? 'Sin procedimiento' }}</td>
                        <td class="px-6 py-3">{{ $reparto->motivo_pendiente }}</td>
                        <td></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-4 text-gray-500 dark:text-gray-400">Ninguna.</td></tr>
                @endforelse
            </x-admin.table>
        </section>
    </div>
</x-admin.page>
