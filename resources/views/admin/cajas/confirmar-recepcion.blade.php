@use('App\Support\Fecha')

{{-- Confirmación final de la recepción del arqueo (CajaController::confirmarRecepcion): todavía no se recibió nada. --}}
<x-admin.page title="Recibir el arqueo de la caja del {{ Fecha::mostrar($caja->fecha) }}">
    <div class="space-y-4 p-6 text-sm">
        <p role="alert" class="max-w-3xl rounded-md bg-amber-50 p-3 font-medium text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
            Al recibir el arqueo, queda cerrado y la caja se desbloquea. Si necesita observar algo, cancele y use "Rechazar (devolver por diferencias)".
        </p>
        <p class="text-gray-700 dark:text-gray-300">Cajero: {{ $caja->cajero->persona?->nombre_completo ?? $caja->cajero->email }}</p>
        @if ($nota)
            <p class="max-w-3xl text-gray-700 dark:text-gray-300"><span class="font-medium">Nota:</span> {{ $nota }}</p>
        @endif
        <form method="POST" action="{{ route('admin.cajas.aprobar', $caja) }}" class="flex items-center gap-3">
            @csrf
            @if ($nota)
                <input type="hidden" name="nota" value="{{ $nota }}">
            @endif
            <input type="hidden" name="confirmar" value="1">
            <a href="{{ route('admin.cajas.show', $caja) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-500 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancelar</a>
            <x-primary-button>Recibir el arqueo</x-primary-button>
        </form>
    </div>
</x-admin.page>
