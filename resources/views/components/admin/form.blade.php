{{--
    Formulario de administración. novalidate: el navegador no frena el envío con sus globitos
    nativos (que salen en el idioma del navegador, p. ej. "Please select an item in the list"); la
    validación la hace el servidor y el aviso en castellano aparece junto al campo. Los atributos
    required/maxlength/pattern se mantienen como indicación (y para lectores de pantalla).
--}}
@props(['action', 'method' => 'POST', 'cancel', 'width' => 'max-w-xl'])

<form method="POST" action="{{ $action }}" novalidate {{ $attributes->merge(['class' => "p-6 space-y-6 {$width}"]) }}>
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
