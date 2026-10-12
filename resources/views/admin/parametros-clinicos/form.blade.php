{{-- Editar un parámetro clínico: tres bandas (pares mínimo y máximo, en orden). Si estaba aprobado, vuelve a PENDIENTE. --}}
@php($numero = fn (?float $valor) => $valor === null ? null : rtrim(rtrim(number_format($valor, 2, ',', ''), '0'), ','))
<x-admin.page :title="'Editar parámetro: '.$parametro->nombre">
    <x-admin.form :action="route('admin.parametros-clinicos.update', $parametro)" method="PUT" :cancel="route('admin.parametros-clinicos.index')">
        <p class="text-sm text-gray-700 dark:text-gray-300">Estos valores los define y aprueba un médico. Los rangos son para adultos.</p>
        @if ($parametro->aprobado())
            <p role="status" class="rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                Este parámetro está aprobado: si lo cambia, vuelve a PENDIENTE y deja de marcar alertas hasta que un médico lo vuelva a aprobar.
            </p>
        @endif
        <x-input-error :messages="$errors->get('bandas')" />
        <x-admin.input name="unidad" label="Unidad" :value="$parametro->unidad" maxlength="20" required />
        @foreach (['imposible' => 'Imposible (fuera se rechaza)', 'muy_alterado' => 'Muy alterado (fuera pide confirmación)', 'normal' => 'Normal (fuera se marca)'] as $banda => $titulo)
            <fieldset class="grid gap-4 sm:grid-cols-2" data-banda="{{ $banda }}">
                <legend class="mb-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $titulo }}</legend>
                <x-admin.input :name="$banda.'_min'" label="Mínimo" :value="$numero($parametro->{$banda.'_min'})" inputmode="decimal" />
                <x-admin.input :name="$banda.'_max'" label="Máximo" :value="$numero($parametro->{$banda.'_max'})" inputmode="decimal" />
            </fieldset>
        @endforeach
        <x-admin.textarea name="notas" label="Notas (opcional)" :value="$parametro->notas" maxlength="1000" rows="3" />
    </x-admin.form>
</x-admin.page>
