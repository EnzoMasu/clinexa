@use('App\Support\Permisos')

{{-- Liquidaciones a beneficiarios (LiquidacionBeneficiarioController::index, docs/liquidacion-beneficiarios.md). Sin contenido clínico. --}}
<x-admin.page title="Liquidaciones a beneficiarios" :create-route="Permisos::url('admin.liquidaciones-beneficiarios.create')" create-label="Armar liquidación">
    @include('admin.liquidaciones-beneficiarios._pestanas')

    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Lo que le corresponde a cada profesional y a cada dueño de equipo según el reparto de las prestaciones ya percibidas (cobradas a la
        paciente y, si hay aseguradora, con su liquidación pagada). Facturación y convenios la arma y la envía; Gerencia la aprueba o la
        devuelve; Tesorería registra los pagos. La clínica no se liquida.
    </p>
    @if ($pendientesDeAprobacion > 0)
        <p role="status" class="mx-4 mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            Hay {{ $pendientesDeAprobacion }} {{ $pendientesDeAprobacion === 1 ? 'liquidación pendiente' : 'liquidaciones pendientes' }} de aprobación.
            <a href="{{ route('admin.liquidaciones-beneficiarios.index', ['estado' => 'PENDIENTE_APROBACION']) }}" class="font-medium underline">Verlas</a>
        </p>
    @endif

    <form method="GET" action="{{ route('admin.liquidaciones-beneficiarios.index') }}" class="flex flex-wrap items-end gap-3 px-4 pt-4 text-sm">
        <div class="w-80"><x-admin.select name="beneficiario" label="Beneficiario" :options="$opcionesBeneficiario" :value="$filtros['beneficiario']" nullable /></div>
        <x-secondary-button type="submit">Filtrar</x-secondary-button>
    </form>

    <x-admin.listado :action="route('admin.liquidaciones-beneficiarios.index')" :busqueda="$busqueda" placeholder="Buscar por N.º de liquidación…">
        @include('admin.liquidaciones-beneficiarios._tabla')
    </x-admin.listado>
</x-admin.page>
