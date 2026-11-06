{{--
    Botón "Planilla de aceptación" y su aviso (docs/convenios-seguros.md). Requiere $planilla (['vencida', 'visada']) y $url. Abre el PDF en otra
    pestaña; no frena nada: el aviso solo informa. Sin montos ni nada clínico.
--}}
<span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1" data-planilla-aceptacion>
    <a href="{{ $url }}" target="_blank" rel="noopener"
        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-500 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Planilla de aceptación</a>
    @if ($planilla['vencida'])
        <span class="text-xs font-medium text-red-700 dark:text-red-400" data-planilla-aviso>Cobertura vencida el {{ $planilla['vencida'] }}</span>
    @endif
    @unless ($planilla['visada'])
        <span class="text-xs font-medium text-amber-700 dark:text-amber-300" data-planilla-aviso>Sin visación</span>
    @endunless
</span>
