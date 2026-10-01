{{--
    unico: clave de App\Support\Unicidad (p. ej. "profesional.matricula") para verificar al salir del
    campo si el valor ya existe (resources/js/verificar-unico.js). unicoIgnorar: id del registro que
    se está editando. unicoCon: otros campos del formulario que forman parte de la clave.
--}}
@props(['name', 'label', 'value' => null, 'type' => 'text', 'unico' => null, 'unicoIgnorar' => null, 'unicoCon' => []])

@php($idAviso = $name.'_unico')

<div @if ($unico) x-data="verificarUnico({ url: @js(route('verificar-unico')), campo: @js($unico), ignorar: @js($unicoIgnorar), con: @js($unicoCon), minimo: @js(App\Support\Unicidad::MINIMO) })" @endif>
    <x-input-label :for="$name" :value="$label" />
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}"
        @if ($unico)
            x-ref="entrada" x-on:blur="verificar()" x-on:input="limpiar()"
            x-bind:aria-invalid="mensaje ? 'true' : null" aria-describedby="{{ trim($attributes->get('aria-describedby').' '.$idAviso) }}"
        @endif
        {{ $attributes->except($unico ? ['aria-describedby'] : [])->merge(['class' => 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm read-only:bg-gray-100 dark:read-only:bg-gray-800']) }}
        @if ($unico) x-bind:class="mensaje ? '!border-red-500 dark:!border-red-500' : ''" @endif>
    @if ($unico)
        <p id="{{ $idAviso }}" x-show="mensaje" x-text="mensaje" role="alert" style="display: none" class="mt-2 text-sm text-red-600 dark:text-red-400"></p>
    @endif
    <x-input-error class="mt-2" :messages="$errors->get($name)" />
</div>
