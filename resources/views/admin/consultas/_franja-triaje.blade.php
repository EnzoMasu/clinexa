{{--
    Franja de la pantalla de atención: URGENTE (si el triaje lo marcó) y "Signos alterados: ..." con cada signo marcado
    al guardarlo (signos_alertas), con texto y color. Solo se marca: no cambia nada del flujo. Requiere $consulta.
--}}
@php
    $examen = $consulta->examenFisico;
    $alterados = collect($examen?->signos_alertas ?? [])->map(fn (array $alerta, string $campo) => \App\Models\ExamenFisico::CAMPOS[$campo][0].' '
        .$examen->valorTexto($campo).' ('.mb_strtolower(\App\Support\Atencion\EvaluadorSignos::ETIQUETAS[$alerta['nivel']] ?? '').')');
@endphp
@if ($consulta->triaje_urgente || $alterados->isNotEmpty())
    <div role="status" data-franja-triaje class="flex flex-wrap items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-800 dark:bg-red-900/30 dark:text-red-100">
        @if ($consulta->triaje_urgente)
            <span class="rounded-full bg-red-700 px-2 py-0.5 text-xs font-bold text-white">URGENTE</span>
        @endif
        @if ($alterados->isNotEmpty())
            <span><span class="font-semibold">Signos alterados:</span> {{ $alterados->implode(', ') }}</span>
        @endif
    </div>
@endif
