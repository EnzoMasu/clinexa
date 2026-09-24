@php($mensaje = $exception->getMessage() ?: 'No tiene permiso para acceder a esta sección.')

@auth
    <x-app-layout>
        <div class="py-12">
            <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-8 text-center space-y-4">
                    <p class="text-5xl font-bold text-gray-300 dark:text-gray-600">403</p>
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $mensaje }}</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Si necesita acceso, solicite a un administrador que revise los permisos de su perfil.
                    </p>
                    <a href="{{ route('dashboard') }}" class="inline-block text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                        Volver al inicio
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
