@props(['name', 'label', 'options', 'value' => null])

<div>
    <x-input-label :for="$name" :value="$label" />
    <select id="{{ $name }}" name="{{ $name }}"
        {{ $attributes->merge(['class' => 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm']) }}>
        @if (old($name, $value) === null)
            <option value="" disabled selected>Seleccionar…</option>
        @endif
        @foreach ($options as $option)
            <option value="{{ $option }}" @selected(old($name, $value) === $option)>{{ $option }}</option>
        @endforeach
    </select>
    <x-input-error class="mt-2" :messages="$errors->get($name)" />
</div>
