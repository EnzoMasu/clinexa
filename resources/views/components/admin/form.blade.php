@props(['action', 'method' => 'POST', 'cancel'])

<form method="POST" action="{{ $action }}" class="p-6 space-y-6 max-w-xl">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    {{ $slot }}

    <div class="flex items-center gap-4">
        <x-primary-button>Guardar</x-primary-button>
        <a href="{{ $cancel }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">Cancelar</a>
    </div>
</form>
