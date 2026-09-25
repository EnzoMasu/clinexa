{{-- Datos del usuario logueado: son de su Persona y los edita un administrador, no el usuario. --}}
@php($persona = $user->persona)

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Estos datos los administra su institución. Si necesita corregir algo, contacte a un administrador.
        </p>
    </header>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 text-sm">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Nombre completo</dt>
            <dd class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $persona?->nombre_completo ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Documento</dt>
            <dd class="mt-1 font-medium text-gray-900 dark:text-gray-100">
                {{ $persona ? "{$persona->tipoDocumento->codigo} {$persona->nro_documento}" : '—' }}
            </dd>
        </div>
        <div class="sm:col-span-2">
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Email') }}</dt>
            <dd class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $user->email }}</dd>
        </div>
    </dl>
</section>
