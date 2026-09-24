{{--
    options: lista de valores (['ACTIVO', 'INACTIVO']) o mapa valor => etiqueta (['M' => 'Masculino']).
    nullable: agrega una opción vacía seleccionable para campos opcionales.
    Si se pasa contenido en el slot, se usa en lugar de options (para opciones con atributos propios).
--}}
@props(['name', 'label', 'options' => [], 'value' => null, 'nullable' => false])

@php($actual = old($name, $value))

<div>
    <x-input-label :for="$name" :value="$label" />
    <select id="{{ $name }}" name="{{ $name }}"
        {{ $attributes->merge(['class' => 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm']) }}>
        @if ($nullable)
            <option value="">—</option>
        @elseif ($actual === null || $actual === '')
            <option value="" disabled selected>Seleccionar…</option>
        @endif

        @if ($slot->isEmpty())
            @foreach ($options as $clave => $etiqueta)
                @php($valor = array_is_list($options) ? $etiqueta : $clave)
                <option value="{{ $valor }}" @selected((string) $actual === (string) $valor)>{{ $etiqueta }}</option>
            @endforeach
        @else
            {{ $slot }}
        @endif
    </select>
    <x-input-error class="mt-2" :messages="$errors->get($name)" />
</div>
