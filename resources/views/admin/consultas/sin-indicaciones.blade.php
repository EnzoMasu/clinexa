{{-- Aviso del PDF de indicaciones generales cuando la consulta no tiene ninguna vigente: no se genera un PDF vacío. --}}
<x-admin.page title="Indicaciones generales">
    <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200" data-sin-indicaciones>
        {{ $mensaje }}
    </div>
    <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
        Cargue las indicaciones en la consulta, espere a que se guarden y vuelva a abrir el PDF.
    </p>
</x-admin.page>
