@use('App\Models\AcuerdoReparto')
@use('App\Support\Fecha')
@use('App\Support\Permisos')

{{-- Historial de un acuerdo (AcuerdoRepartoController::show): todas las versiones de esa clave. --}}
<x-admin.page title="Historial del acuerdo">
    <div class="flex flex-wrap items-start justify-between gap-4 p-6 pb-2">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
            <div><dt class="text-gray-500 dark:text-gray-400">Tipo</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ AcuerdoReparto::TIPOS[$acuerdo->tipo] }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Beneficiario</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $acuerdo->beneficiarioTexto() }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Alcance</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $acuerdo->alcanceTexto() }}</dd></div>
        </dl>
        <div class="space-x-3 text-sm">
            @php($vigente = $versiones->first(fn ($version) => $version->vigente()))
            @if ($vigente && ($cambiar = Permisos::url('admin.acuerdos-reparto.cambiar', $vigente)))
                <a href="{{ $cambiar }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Cambiar porcentaje</a>
            @endif
            <a href="{{ route('admin.acuerdos-reparto.index') }}" class="text-gray-600 hover:underline dark:text-gray-400">Volver a Acuerdos de reparto</a>
        </div>
    </div>

    <x-admin.table :headers="['Porcentaje', 'Vigencia', 'Estado', 'Cargado por', 'Cargado el']">
        @foreach ($versiones as $version)
            <tr>
                <td class="px-6 py-4 whitespace-nowrap tabular-nums font-medium">{{ $version->porcentajeTexto() }}</td>
                <td class="px-6 py-4">{{ $version->vigenciaTexto() }}</td>
                <td class="px-6 py-4"><x-admin.estado-badge :estado="$version->estado" /></td>
                <td class="px-6 py-4">{{ $version->usuario?->persona?->nombre_completo }}</td>
                <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($version->created_at, conHora: true) }}</td>
                <td></td>
            </tr>
        @endforeach
    </x-admin.table>
</x-admin.page>
