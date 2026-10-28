@use('App\Support\Permisos')

{{-- Liquidaciones a aseguradoras (LiquidacionController::index, docs/liquidaciones-aseguradoras.md). Sin contenido clínico. --}}
<x-admin.page title="Liquidaciones" :create-route="Permisos::url('admin.liquidaciones.create')" create-label="Armar liquidación">
    @include('admin.liquidaciones._pestanas')

    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        La liquidación mensual de cada aseguradora: sus prestaciones con la firma recibida hasta la fecha de corte. Facturación y convenios
        la arma y la envía a aprobación; Gerencia la aprueba o la devuelve. Aprobada, queda bloqueada y recibe los pagos de la aseguradora.
    </p>
    @if ($pendientesDeAprobacion > 0)
        <p role="status" class="mx-4 mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            Hay {{ $pendientesDeAprobacion }} {{ $pendientesDeAprobacion === 1 ? 'liquidación pendiente' : 'liquidaciones pendientes' }} de aprobación.
            <a href="{{ route('admin.liquidaciones.index', ['estado' => 'PENDIENTE_APROBACION']) }}" class="font-medium underline">Verlas</a>
        </p>
    @endif
    @if ($cantidadSinFirma > 0)
        <p role="status" class="mx-4 mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            Hay {{ $cantidadSinFirma }} {{ $cantidadSinFirma === 1 ? 'prestación de aseguradora' : 'prestaciones de aseguradora' }} sin la firma recibida: no entran en la liquidación hasta tenerla.
            <a href="{{ route('admin.liquidaciones.sin-firma') }}" class="font-medium underline">Ver la lista</a>
        </p>
    @endif

    <x-admin.listado :action="route('admin.liquidaciones.index')" :busqueda="$busqueda" placeholder="Buscar por N.º de liquidación…">
        <x-slot name="filtros">
            <div class="w-64"><x-admin.select name="aseguradora" label="Aseguradora" :options="$opcionesAseguradora" :value="$filtros['aseguradora']" nullable /></div>
            <div class="w-56"><x-admin.select name="estado" label="Estado" :options="$opcionesEstado" :value="$filtros['estado']" nullable /></div>
        </x-slot>

        @include('admin.liquidaciones._tabla')
    </x-admin.listado>
</x-admin.page>
