{{--
    Campo de fecha dd/mm/aaaa (resources/js/campo-fecha.js), en lugar del input de fecha nativo,
    que muestra el formato del idioma del navegador. "value": fecha (Carbon o ISO) o texto dd/mm/aaaa;
    "hastaHoy": no deja elegir fechas futuras.
--}}
@props(['name', 'label', 'value' => null, 'hastaHoy' => false])

@php
    use App\Support\Fecha;

    // Tras un error de validación se muestra lo que se había escrito, tal cual.
    $texto = old($name, $value instanceof \Carbon\CarbonInterface || (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value))
        ? Fecha::mostrar($value) : $value);
@endphp

<div>
    <x-input-label :for="$name" :value="$label" />
    <div class="relative mt-1" x-data="campoFecha({ valor: @js((string) $texto), max: @js($hastaHoy ? Fecha::hoy()->format('Y-m-d') : null) })">
        <input id="{{ $name }}" name="{{ $name }}" type="text" value="{{ $texto }}" x-ref="entrada"
            x-bind:value="valor" x-on:input="escribir($event)"
            placeholder="dd/mm/aaaa" inputmode="numeric" maxlength="10" autocomplete="off"
            pattern="\d{2}/\d{2}/\d{4}" title="Fecha en formato dd/mm/aaaa"
            {{ $attributes->merge(['class' => 'block w-full pe-10 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm']) }}>
        <x-admin.calendario />
    </div>
    <x-input-error class="mt-2" :messages="$errors->get($name)" />
</div>
