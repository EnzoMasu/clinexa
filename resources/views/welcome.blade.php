<x-guest-layout>
    <div class="py-4 text-center space-y-4">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ config('app.name') }}</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400">Sistema de gestión de la clínica.</p>

        @auth
            <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                Ir al panel
            </a>
        @else
            <a href="{{ route('login') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                {{ __('Log in') }}
            </a>
        @endauth
    </div>
</x-guest-layout>
