{{--
    Etiqueta del nivel de un signo vital guardado (signos_alertas), con TEXTO y color (no solo color): "Fuera de lo
    normal" (ámbar) o "Muy alterado" (rojo), y si un valor muy alterado fue confirmado. Requiere $alerta (o null).
--}}
@if ($alerta)
    @php($muy = ($alerta['nivel'] ?? null) === \App\Support\Atencion\EvaluadorSignos::MUY_ALTERADO)
    <span data-nivel-signo="{{ $alerta['nivel'] }}" @class([
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
        'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' => $muy,
        'bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-200' => ! $muy,
    ])>
        {{ \App\Support\Atencion\EvaluadorSignos::ETIQUETAS[$alerta['nivel']] ?? '' }}
        @if (($alerta['direccion'] ?? null) !== null) ({{ $alerta['direccion'] }}) @endif
        @if ($muy && ($alerta['confirmado_valor'] ?? null) !== null) · confirmado @endif
    </span>
@endif
