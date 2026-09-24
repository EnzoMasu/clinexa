@props(['name', 'label', 'value' => null, 'type' => 'text'])

<div>
    <x-input-label :for="$name" :value="$label" />
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}"
        {{ $attributes->merge(['class' => 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm read-only:bg-gray-100 dark:read-only:bg-gray-800']) }}>
    <x-input-error class="mt-2" :messages="$errors->get($name)" />
</div>
