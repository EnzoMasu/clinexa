<x-admin.page title="Nueva compra o gasto">
    <x-admin.form :action="route('admin.compras.store')" :cancel="route('dashboard')" width="max-w-3xl" submit-label="Guardar como borrador">
        @include('admin.compras._form')
    </x-admin.form>
</x-admin.page>
