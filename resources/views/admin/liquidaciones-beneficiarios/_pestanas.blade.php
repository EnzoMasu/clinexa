{{-- Pestañas del módulo Liquidaciones a beneficiarios (docs/liquidacion-beneficiarios.md). --}}
<nav class="flex flex-wrap gap-4 border-b border-gray-200 px-4 pt-4 text-sm dark:border-gray-700" aria-label="Vistas de Liquidaciones a beneficiarios">
    @foreach (array_filter([
        'liquidaciones' => ['Liquidaciones', route('admin.liquidaciones-beneficiarios.index')],
        'por-pagar' => ['Por pagar por beneficiario', route('admin.liquidaciones-beneficiarios.por-pagar')],
        'pendientes' => ['Pendientes de liquidar', route('admin.liquidaciones-beneficiarios.pendientes')],
        'resumen' => Route::has('admin.liquidaciones-beneficiarios.resumen') ? ['Resumen por período', route('admin.liquidaciones-beneficiarios.resumen')] : null,
    ]) as $clave => [$texto, $url])
        <a href="{{ $url }}" @if ($activa === $clave) aria-current="page" @endif
            @class([
                '-mb-px border-b-2 px-1 pb-2 font-medium',
                'border-indigo-500 text-indigo-700 dark:text-indigo-300' => $activa === $clave,
                'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200' => $activa !== $clave,
            ])>{{ $texto }}</a>
    @endforeach
</nav>
