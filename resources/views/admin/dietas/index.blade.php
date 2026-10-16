@use('App\Support\Permisos')

<x-admin.page title="Dietas" :create-route="Permisos::url('admin.dietas.create')" create-label="Nueva dieta">
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        El texto de una dieta se copia a la indicación general con "Insertar dieta". Si después la edita o la desactiva,
        las indicaciones ya guardadas no cambian.
    </p>
    <x-admin.listado :action="route('admin.dietas.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.dietas._tabla')
    </x-admin.listado>
</x-admin.page>
