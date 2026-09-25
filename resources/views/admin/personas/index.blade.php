@use('App\Support\Permisos')

<x-admin.page title="Personas" :create-route="Permisos::url('admin.personas.create')" create-label="Nueva persona">
    <x-admin.listado :action="route('admin.personas.index')" :busqueda="$busqueda" placeholder="Buscar por documento, nombre o razón social…">
        @include('admin.personas._tabla')
    </x-admin.listado>
</x-admin.page>
