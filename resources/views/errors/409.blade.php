{{-- Conflicto: la acción ya no corresponde al estado actual (p. ej. finalizar una consulta que se deshizo en otra pestaña). --}}
@php($mensaje = $exception->getMessage() ?: 'La acción no se pudo completar porque los datos cambiaron. Recargue la página.')

@auth
    <x-app-layout>
        <div class="py-12">
            <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-8 text-center space-y-4">
                    <p class="text-5xl font-bold text-gray-300 dark:text-gray-600">409</p>
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $mensaje }}</h1>
                    <a href="{{ route('admin.atencion.index') }}" class="inline-block text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                        Volver a la lista
                    </a>
                </div>
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout>
        <div class="text-center space-y-4">
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $mensaje }}</h1>
            <a href="{{ route('login') }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">Ir al login</a>
        </div>
    </x-guest-layout>
@endauth
