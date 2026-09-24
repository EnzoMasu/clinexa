{{--
    Campo de contraseña con botón para mostrarla/ocultarla (Alpine). Se usa igual que x-text-input,
    sin type: <x-password-input id="password" name="password" class="mt-1 block w-full" required />
    - "class" (ancho, márgenes) se aplica al contenedor; el resto de atributos, al <input>.
    - Sin JavaScript el campo sigue siendo type="password".
--}}
@props(['disabled' => false])

<div x-data="{ visible: false }" {{ $attributes->only('class')->merge(['class' => 'relative']) }}>
    <input type="password" x-bind:type="visible ? 'text' : 'password'" @disabled($disabled)
        {{ $attributes->except('class')->merge(['class' => 'block w-full pe-10 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm']) }}>

    <button type="button" x-on:click="visible = ! visible"
        x-bind:aria-label="visible ? 'Ocultar contraseña' : 'Mostrar contraseña'"
        x-bind:title="visible ? 'Ocultar contraseña' : 'Mostrar contraseña'"
        x-bind:aria-pressed="visible.toString()"
        aria-label="Mostrar contraseña" title="Mostrar contraseña" aria-pressed="false"
        @if ($attributes->has('id')) aria-controls="{{ $attributes->get('id') }}" @endif
        class="absolute inset-y-0 end-0 flex items-center px-3 rounded-e-md text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
        {{-- Ojo: contraseña oculta, click para mostrar --}}
        <svg x-show="! visible" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
        {{-- Ojo tachado: contraseña visible, click para ocultar --}}
        <svg x-show="visible" style="display: none" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
        </svg>
    </button>
</div>
