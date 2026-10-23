@use('App\Support\Fecha')
@use('App\Support\Permisos')

{{-- Historial de la tarifa de un procedimiento en un convenio (ConvenioController::tarifa), con la baja de la vigente. --}}
<x-admin.page title="Historial de la tarifa del convenio">
    <div class="flex flex-wrap items-start justify-between gap-4 p-6 pb-2 text-sm">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Convenio</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $tarifa->convenio->nombreCompleto() }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Procedimiento</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $tarifa->procedimiento->nombre }} ({{ $tarifa->procedimiento->codigo }})</dd></div>
        </dl>
        <div class="space-x-3">
            @php($vigente = $versiones->first(fn ($version) => $version->vigente()))
            @if ($vigente && ($cambiar = Permisos::url('admin.convenios.tarifas.create', ['convenio' => $vigente->convenio_id, 'procedimiento' => $vigente->procedimiento_id])))
                <a href="{{ $cambiar }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Cambiar tarifa</a>
            @endif
            <a href="{{ route('admin.convenios.tarifas') }}" class="text-gray-600 hover:underline dark:text-gray-400">Volver a Tarifas del convenio</a>
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

    @if ($vigente && Permisos::puedeRuta('admin.convenios.tarifas.baja'))
        <form method="POST" action="{{ route('admin.convenios.tarifas.baja', $vigente) }}" novalidate class="flex flex-wrap items-end gap-3 p-6 text-sm"
            x-data x-on:submit="if (! confirm('¿Dar de baja la tarifa? Desde el día siguiente se usa la tarifa general.')) $event.preventDefault()">
            @csrf
            <div class="w-48"><x-admin.fecha name="ultimo_dia" label="Último día de vigencia" :value="Fecha::hoy()" /></div>
            <x-danger-button>Dar de baja</x-danger-button>
        </form>
    @endif
</x-admin.page>
