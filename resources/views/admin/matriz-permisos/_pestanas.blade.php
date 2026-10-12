{{-- Pestañas de la Matriz de permisos: editar un perfil, o comparar todos (solo lectura). --}}
<nav class="flex gap-4 border-b border-gray-200 dark:border-gray-700 text-sm" aria-label="Vistas de la matriz">
    @foreach (['editar' => ['Editar un perfil', route('admin.matriz-permisos.index')], 'comparar' => ['Comparar', route('admin.matriz-permisos.index', ['vista' => 'comparar'])]] as $clave => [$texto, $url])
        <a href="{{ $url }}" @if ($activa === $clave) aria-current="page" @endif
            @class([
                '-mb-px border-b-2 px-1 pb-2 font-medium',
                'border-indigo-500 text-indigo-700 dark:text-indigo-300' => $activa === $clave,
                'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200' => $activa !== $clave,
            ])>{{ $texto }}</a>
    @endforeach
</nav>
