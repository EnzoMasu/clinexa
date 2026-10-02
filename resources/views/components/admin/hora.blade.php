{{--
    Campo de hora HH:MM (24 horas), igual en todos los navegadores (el <input> de hora nativo puede
    mostrar AM/PM según el idioma). Los dos puntos se ponen solos al escribir.
--}}
@props(['name', 'label', 'value' => null])

@php($texto = old($name, $value !== null ? substr((string) $value, 0, 5) : ''))

<div>
    <x-input-label :for="$name" :value="$label" />
    <input id="{{ $name }}" name="{{ $name }}" type="text" value="{{ $texto }}"
        placeholder="HH:MM" inputmode="numeric" maxlength="5" autocomplete="off"
        pattern="([01]\d|2[0-3]):[0-5]\d" title="Hora en formato HH:MM (24 horas)"
        x-data x-on:input="$el.value = $el.value.replace(/\D/g, '').slice(0, 4).replace(/^(\d{2})(\d)/, '$1:$2')"
        {{ $attributes->merge(['class' => 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm']) }}>
    <x-input-error class="mt-2" :messages="$errors->get($name)" />
</div>
