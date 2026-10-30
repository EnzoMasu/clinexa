{{-- La marca de la simulación (docs/facturacion.md): la emisión no se envía a ningún lado ni tiene validez fiscal. --}}
@if (config('clinexa.facturacion.simulada') && config('clinexa.facturacion.mostrar_marca'))
    <p role="note" class="mx-4 mt-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm font-semibold text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200">
        {{ App\Support\Facturacion\EmisorSimulado::MARCA }}: las facturas funcionan como las electrónicas, pero no se envían a ningún lado.
    </p>
@endif
