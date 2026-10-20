@use('App\Support\Fecha')
@use('App\Support\Permisos')

{{-- Historial de una tarifa (TarifaController::show): todas las versiones de ese procedimiento y profesional. --}}
<x-admin.page title="Historial de la tarifa">
    <div class="flex flex-wrap items-start justify-between gap-4 p-6 pb-2">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Procedimiento</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $tarifa->procedimiento->nombre }} ({{ $tarifa->procedimiento->codigo }})</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Profesional</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $tarifa->profesionalTexto() }}</dd></div>
        </dl>
        <div class="space-x-3 text-sm">
            @php($vigente = $versiones->first(fn ($version) => $version->vigente()))
            @if ($vigente && ($cambiar = Permisos::url('admin.tarifas.cambiar', $vigente)))
                <a href="{{ $cambiar }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Cambiar tarifa</a>
            @endif
            <a href="{{ route('admin.tarifas.index') }}" class="text-gray-600 hover:underline dark:text-gray-400">Volver a Tarifas</a>
        </div>
    </div>

    <x-admin.table :headers="['Monto', 'Vigencia', 'Estado', 'Cargada por', 'Cargada el']">
        @foreach ($versiones as $version)
            <tr>
                <td class="px-6 py-4 whitespace-nowrap tabular-nums font-medium">{{ $version->montoTexto() }}</td>
                <td class="px-6 py-4">{{ $version->vigenciaTexto() }}</td>
                <td class="px-6 py-4"><x-admin.estado-badge :estado="$version->estado" /></td>
                <td class="px-6 py-4">{{ $version->usuario?->persona?->nombre_completo }}</td>
                <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($version->created_at, conHora: true) }}</td>
                <td></td>
            </tr>
        @endforeach
    </x-admin.table>
</x-admin.page>
