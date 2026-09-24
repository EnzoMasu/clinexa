@props(['name', 'label', 'value' => null])

<div>
    <x-input-label :for="$name" :value="$label" />
    <textarea id="{{ $name }}" name="{{ $name }}"
        {{ $attributes->merge(['rows' => 4, 'class' => 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm']) }}>{{ old($name, $value) }}</textarea>
    <x-input-error class="mt-2" :messages="$errors->get($name)" />
</div>
