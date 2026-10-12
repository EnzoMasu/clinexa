@use('App\Support\Fecha')
@use('App\Support\Atencion\DatosFormulario')

{{--
    Urgente y observación del triaje (grupo PREPARACIÓN, el bloque del comienzo del triaje). con_triaje avisa que el
    formulario trae el bloque (sin él, un guardado no lo toca). Todo escapado; la observación completa solo se ve acá
    (formulario de triaje y pantalla de atención), nunca en las listas. Requiere $consulta y $clases.
--}}
@php
    $urgente = (bool) old('triaje_urgente', $consulta->triaje_urgente);
    $marcadoPor = $consulta->triaje_urgente && $consulta->urgentePor ? DatosFormulario::nombre($consulta->urgentePor) : null;
@endphp

<fieldset class="space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700" data-bloque="triaje">
    <legend class="px-1 font-medium text-gray-900 dark:text-gray-100">Triaje</legend>
    <input type="hidden" name="con_triaje" value="1">
    <input type="hidden" name="triaje_urgente" value="0">
    <label class="flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-gray-100">
        <input type="checkbox" name="triaje_urgente" value="1" @checked($urgente)
            class="rounded border-gray-300 text-red-600 shadow-sm focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900">
        Urgente
    </label>
    @if ($marcadoPor)
        <p class="text-xs text-gray-600 dark:text-gray-400" data-urgente-por>
            Marcado como urgente por {{ $marcadoPor }} el {{ Fecha::mostrar($consulta->triaje_urgente_en, conHora: true) }}.
        </p>
    @endif
    <div>
        <label for="triaje_observacion" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
            Observación del triaje <span class="text-xs text-gray-500 dark:text-gray-400">(opcional, sirve con o sin urgente)</span>
        </label>
        <textarea id="triaje_observacion" name="triaje_observacion" rows="2" maxlength="1000" class="{{ $clases }}">{{ old('triaje_observacion', $consulta->triaje_observacion) }}</textarea>
        <x-input-error class="mt-2" :messages="$errors->get('triaje_observacion')" />
    </div>
</fieldset>
