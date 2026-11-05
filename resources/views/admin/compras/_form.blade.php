{{--
    Campos de la compra (create y edit; docs/compras-gastos.md). Solo proveedores, categorías y tipos activos. Lo que se muestra del
    comprobante y del importe lo decide compra-formulario.js según las casillas del tipo elegido (el servidor valida igual).
--}}
@php
    $campoValor = fn (string $campo, $porDefecto = null) => old($campo, $compra->{$campo} ?? $porDefecto);
@endphp

@if (session('compra_existente'))
    <div role="alert" class="rounded-md bg-red-50 p-4 text-sm text-red-800 dark:bg-red-900/40 dark:text-red-200">
        Ese comprobante ya está cargado en la
        <a href="{{ route('admin.compras.show', session('compra_existente')) }}" class="font-medium underline">compra N.º {{ session('compra_existente') }}</a>.
        Revísela antes de cargarlo otra vez.
    </div>
@endif

<div x-data="compraFormulario({
        casillas: @js($casillasPorTipo),
        tipo: @js((string) $campoValor('tipo_comprobante_id', '')),
        condicion: @js($campoValor('condicion') instanceof \App\Enums\CondicionCompra ? $campoValor('condicion')->value : (string) $campoValor('condicion', 'CONTADO')),
        monto10: @js((string) $campoValor('monto_10', '')),
        monto5: @js((string) $campoValor('monto_5', '')),
        montoExento: @js((string) $campoValor('monto_exento', '')),
    })" class="space-y-6">

    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <x-admin.select name="proveedor_id" label="Proveedor" :options="$proveedores" :value="$compra->proveedor_id" required />
        @if (count($categorias) === 0)
            <div id="sin-categorias-gasto" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">
                <p class="font-medium">Categoría de gasto</p>
                <p>No hay ninguna categoría de gasto activa: hay que cargar una antes de registrar la compra.
                    @if ($urlAltaCategoria = \App\Support\Permisos::url('admin.categorias-gasto.create'))
                        <a href="{{ $urlAltaCategoria }}" class="font-medium underline">Cargar una categoría de gasto</a>.
                    @else
                        Pídaselo a quien administra las categorías de gasto.
                    @endif
                </p>
            </div>
        @else
            <x-admin.select name="categoria_gasto_id" label="Categoría de gasto" :options="$categorias" :value="$compra->categoria_gasto_id" required />
        @endif
        <x-admin.fecha name="fecha" label="Fecha de la compra" :value="$compra->fecha" hasta-hoy required />
    </div>

    <fieldset class="space-y-4 rounded-md border border-gray-200 p-4 dark:border-gray-700">
        <legend class="px-1 text-sm font-medium text-gray-700 dark:text-gray-300">Comprobante</legend>
        <x-admin.select name="tipo_comprobante_id" label="Tipo de comprobante" :options="$tipos" :value="$compra->tipo_comprobante_id" x-model="tipo" required />

        <fieldset x-show="reglas.requiere_numero" x-bind:disabled="!reglas.requiere_numero" class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <x-admin.input name="numero_comprobante" label="Número del comprobante" :value="$compra->numero_comprobante" maxlength="50"
                x-bind:placeholder="reglas.numero_formato_legal ? '001-001-0000001' : ''" />
        </fieldset>
        <fieldset x-show="reglas.requiere_timbrado" x-bind:disabled="!reglas.requiere_timbrado">
            <x-admin.input name="timbrado" label="Número de timbrado" :value="$compra->timbrado" maxlength="20" />
        </fieldset>
        <fieldset x-show="reglas.requiere_fecha_comprobante" x-bind:disabled="!reglas.requiere_fecha_comprobante">
            <x-admin.fecha name="fecha_comprobante" label="Fecha del comprobante" :value="$compra->fecha_comprobante" hasta-hoy />
        </fieldset>
    </fieldset>

    <fieldset class="space-y-4 rounded-md border border-gray-200 p-4 dark:border-gray-700">
        <legend class="px-1 text-sm font-medium text-gray-700 dark:text-gray-300">Importe en guaraníes (₲)</legend>

        <fieldset x-show="reglas.discrimina_iva" x-bind:disabled="!reglas.discrimina_iva" class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-400">Escriba los montos con el IVA incluido, según la tasa. El IVA se calcula solo.</p>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
                <x-admin.input name="monto_10" label="Monto con IVA 10 %" :value="$compra->monto_10" inputmode="numeric" x-model="monto10" />
                <x-admin.input name="monto_5" label="Monto con IVA 5 %" :value="$compra->monto_5" inputmode="numeric" x-model="monto5" />
                <x-admin.input name="monto_exento" label="Monto exento" :value="$compra->monto_exento" inputmode="numeric" x-model="montoExento" />
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300">Total: <span class="font-medium tabular-nums">₲ <span x-text="totalDesglose"></span></span></p>
        </fieldset>

        <fieldset x-show="!reglas.discrimina_iva" x-bind:disabled="reglas.discrimina_iva">
            <x-admin.input name="total" label="Total" :value="$compra->total" inputmode="numeric" />
        </fieldset>
    </fieldset>

    <fieldset class="space-y-4 rounded-md border border-gray-200 p-4 dark:border-gray-700">
        <legend class="px-1 text-sm font-medium text-gray-700 dark:text-gray-300">Condición</legend>
        <x-admin.select name="condicion" label="Condición de la compra" :options="$condiciones" :value="$compra->condicion?->value" x-model="condicion" required />

        <fieldset x-show="esCredito" x-bind:disabled="!esCredito" class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <x-admin.fecha name="vencimiento" label="Vencimiento" :value="$compra->vencimiento" />
            <x-admin.input name="anticipo" label="Anticipo entregado (opcional)" :value="$compra->anticipo ?: null" inputmode="numeric" />
        </fieldset>
    </fieldset>

    <x-admin.textarea name="observaciones" label="Observaciones (opcional)" :value="$compra->observaciones" rows="3" maxlength="1000" />
</div>
