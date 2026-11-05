<x-admin.page :title="'Editar compra N.º '.$compra->id">
    <x-admin.form :action="route('admin.compras.update', $compra)" method="PUT" :cancel="route('admin.compras.show', $compra)" width="max-w-3xl">
        @include('admin.compras._form')
    </x-admin.form>
</x-admin.page>
