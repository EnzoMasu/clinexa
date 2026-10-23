{{-- Pestañas del módulo Convenios (docs/convenios-seguros.md): convenios y planes, y tarifas del convenio. --}}
<nav class="flex gap-4 border-b border-gray-200 px-4 pt-4 text-sm dark:border-gray-700" aria-label="Vistas de Convenios">
    @foreach (['convenios' => ['Convenios y planes', route('admin.convenios.index')], 'tarifas' => ['Tarifas del convenio', route('admin.convenios.tarifas')]] as $clave => [$texto, $url])
        <a href="{{ $url }}" @if ($activa === $clave) aria-current="page" @endif
            @class([
                '-mb-px border-b-2 px-1 pb-2 font-medium',
                'border-indigo-500 text-indigo-700 dark:text-indigo-300' => $activa === $clave,
                'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200' => $activa !== $clave,
            ])>{{ $texto }}</a>
    @endforeach
</nav>
