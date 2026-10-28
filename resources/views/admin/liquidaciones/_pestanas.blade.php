{{-- Pestañas del módulo Liquidaciones (docs/liquidaciones-aseguradoras.md): liquidaciones, por cobrar por aseguradora y prestaciones sin firma. --}}
<nav class="flex flex-wrap gap-4 border-b border-gray-200 px-4 pt-4 text-sm dark:border-gray-700" aria-label="Vistas de Liquidaciones">
    @foreach ([
        'liquidaciones' => ['Liquidaciones', route('admin.liquidaciones.index')],
        'por-cobrar' => ['Por cobrar por aseguradora', route('admin.liquidaciones.por-cobrar')],
        'sin-firma' => ['Prestaciones sin firma', route('admin.liquidaciones.sin-firma')],
    ] as $clave => [$texto, $url])
        <a href="{{ $url }}" @if ($activa === $clave) aria-current="page" @endif
            @class([
                '-mb-px border-b-2 px-1 pb-2 font-medium',
                'border-indigo-500 text-indigo-700 dark:text-indigo-300' => $activa === $clave,
                'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200' => $activa !== $clave,
            ])>{{ $texto }}</a>
    @endforeach
</nav>
