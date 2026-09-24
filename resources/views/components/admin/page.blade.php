@props(['title', 'createRoute' => null, 'createLabel' => 'Nuevo'])

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ $title }}
            </h2>

            @if ($createRoute)
                <a href="{{ $createRoute }}" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                    {{ $createLabel }}
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 dark:bg-green-900/40 p-4 text-sm text-green-800 dark:text-green-200">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="rounded-md bg-red-50 dark:bg-red-900/40 p-4 text-sm text-red-800 dark:text-red-200">
                    {{ session('error') }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </div>
</x-app-layout>
