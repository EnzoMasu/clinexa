{{-- Datos de la persona de un rol ya creado: no se cambia la persona; sus datos se editan en Personas. --}}
@props(['persona'])

@php($url = \App\Support\Permisos::url('admin.personas.edit', $persona))

<div class="rounded-md border border-gray-200 dark:border-gray-700 p-4 space-y-3">
    <dl class="grid gap-3 sm:grid-cols-2 text-sm">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">{{ $persona->tipo_persona === 'JURIDICA' ? 'Razón social' : 'Nombre' }}</dt>
            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $persona->nombre_completo }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Documento</dt>
            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $persona->tipoDocumento->codigo }} {{ $persona->nro_documento }}</dd>
        </div>
    </dl>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        La persona no se cambia una vez creado el rol.
        @if ($url)
            <a href="{{ $url }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar los datos de la persona</a>
        @endif
    </p>
</div>
