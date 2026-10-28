{{-- Cargar un timbrado (TimbradoController::create, docs/facturacion.md). Si ya hay uno vigente para ese punto, pasa a histórico. --}}
<x-admin.page title="Cargar timbrado">
    @include('admin.facturas._simulacion')
    @if ($clinica === null)
        <p role="alert" class="m-6 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            Cargue primero los <a href="{{ route('admin.clinica.edit') }}" class="font-medium underline">datos de la clínica</a>: el timbrado es de la clínica y de una sucursal suya.
        </p>
    @else
        <x-admin.form :action="route('admin.facturas.timbrados.store')" :cancel="route('admin.facturas.timbrados')" submit-label="Cargar timbrado">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Timbrado de {{ $clinica->razon_social }} (RUC {{ $clinica->ruc }}). Si ya hay uno vigente para la misma sucursal, establecimiento y
                punto de expedición, pasa a histórico.
            </p>
            <x-admin.select name="sucursal_id" label="Sucursal" :options="$sucursales" :value="$principal" required />
            <x-admin.input name="numero" label="Número de timbrado (8 dígitos)" inputmode="numeric" maxlength="8" required autofocus />
            <div class="grid grid-cols-2 gap-4">
                <x-admin.fecha name="vigente_desde" label="Vigente desde" />
                <x-admin.fecha name="vigente_hasta" label="Vigente hasta" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <x-admin.input name="establecimiento" label="Establecimiento" value="001" inputmode="numeric" maxlength="3" required />
                <x-admin.input name="punto_expedicion" label="Punto de expedición" value="001" inputmode="numeric" maxlength="3" required />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <x-admin.input name="numero_inicial" label="Número inicial" value="1" inputmode="numeric" required />
                <x-admin.input name="numero_final" label="Número final" inputmode="numeric" required />
            </div>
        </x-admin.form>
    @endif
</x-admin.page>
