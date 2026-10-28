{{-- Prestaciones sin firma (LiquidacionController::sinFirma): las de aseguradora que no entran en la liquidación hasta recibir la firma. --}}
<x-admin.page title="Liquidaciones">
    @include('admin.liquidaciones._pestanas')

    <div class="space-y-4 p-6 text-sm">
        <p class="text-gray-600 dark:text-gray-400">
            Prestaciones de aseguradora, sin liquidar, que todavía no tienen la firma de la paciente: no entran en la liquidación y quedan
            para la siguiente. Marque la firma en la página de la paciente cuando la reciba.
        </p>
        <form method="GET" action="{{ route('admin.liquidaciones.sin-firma') }}" class="flex flex-wrap items-end gap-3">
            <div class="w-64"><x-admin.select name="aseguradora" label="Aseguradora" :options="$opcionesAseguradora" :value="$filtros['aseguradora']" nullable /></div>
            <x-secondary-button type="submit">Filtrar</x-secondary-button>
        </form>
        <p class="font-medium text-gray-900 dark:text-gray-100">
            {{ $cantidad }} {{ $cantidad === 1 ? 'prestación sin firma' : 'prestaciones sin firma' }}{{ $cantidad > $prestaciones->count() ? ' (se muestran las '.$prestaciones->count().' más antiguas)' : '' }}.
        </p>
        @include('admin.liquidaciones._sin-firma', ['veCobertura' => App\Http\Controllers\Admin\CoberturaController::puedeVer()])
    </div>
</x-admin.page>
