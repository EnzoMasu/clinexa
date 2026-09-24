@props(['action', 'value' => '', 'placeholder' => 'Buscar…'])

<form method="GET" action="{{ $action }}" class="flex gap-3 p-4 border-b border-gray-200 dark:border-gray-700">
    <x-text-input name="q" type="search" :value="$value" :placeholder="$placeholder" class="block w-full max-w-md" />
    <x-secondary-button type="submit">Buscar</x-secondary-button>
</form>
