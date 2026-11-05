@use('App\Support\Fecha')
@use('App\Support\Compras\FlujoCompra')

{{-- Detalle de una compra o gasto (CompraController::show, docs/compras-gastos.md). Sin nada clínico. Nada se borra. --}}
<x-admin.page :title="'Compra N.º '.$compra->id">
    @php($dinero = fn (?int $monto) => $monto === null ? '—' : $compra->moneda->formatear($monto))
    <div class="space-y-6 p-6 text-sm">
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-3 text-red-800 dark:bg-red-900/30 dark:text-red-200">{{ session('error') }}</div>
        @endif
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-3">
                <div><dt class="text-gray-500 dark:text-gray-400">Estado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $compra->etiquetaEstado() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Proveedor</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $compra->proveedor->persona->nombre_completo }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Categoría de gasto</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $compra->categoriaGasto->nombre }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Fecha de la compra</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($compra->fecha) }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Tipo de comprobante</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $compra->tipoComprobante->nombre }}</dd></div>
                @if ($compra->numero_comprobante)
                    <div><dt class="text-gray-500 dark:text-gray-400">Número</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $compra->numero_comprobante }}</dd></div>
                @endif
                @if ($compra->timbrado)
                    <div><dt class="text-gray-500 dark:text-gray-400">Timbrado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $compra->timbrado }}</dd></div>
                @endif
                @if ($compra->fecha_comprobante)
                    <div><dt class="text-gray-500 dark:text-gray-400">Fecha del comprobante</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($compra->fecha_comprobante) }}</dd></div>
                @endif
                <div><dt class="text-gray-500 dark:text-gray-400">Condición</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $compra->condicion->etiqueta() }}</dd></div>
                @if ($compra->condicion->esCredito())
                    <div><dt class="text-gray-500 dark:text-gray-400">Vencimiento</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($compra->vencimiento) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Anticipo / adeudado</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $dinero($compra->anticipo) }} / {{ $dinero($compra->total - $compra->anticipo) }}</dd></div>
                @endif
                <div><dt class="text-gray-500 dark:text-gray-400">Total</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $dinero($compra->total) }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Cargada</dt><dd>{{ Fecha::mostrar($compra->created_at, conHora: true) }} por {{ $compra->registradaPor?->persona?->nombre_completo }}</dd></div>
                @if ($compra->confirmada_en)
                    <div><dt class="text-gray-500 dark:text-gray-400">Confirmada</dt><dd>{{ Fecha::mostrar($compra->confirmada_en, conHora: true) }} por {{ $compra->confirmadaPor?->persona?->nombre_completo }}</dd></div>
                @endif
                @if ($compra->anulada_en)
                    <div class="sm:col-span-3"><dt class="text-gray-500 dark:text-gray-400">Anulada</dt><dd>{{ Fecha::mostrar($compra->anulada_en, conHora: true) }} por {{ $compra->anuladaPor?->persona?->nombre_completo }}: {{ $compra->motivo_anulacion }}</dd></div>
                @endif
                @if ($compra->observaciones)
                    <div class="sm:col-span-3"><dt class="text-gray-500 dark:text-gray-400">Observaciones</dt><dd class="whitespace-pre-line">{{ $compra->observaciones }}</dd></div>
                @endif
            </dl>
            <div class="flex flex-wrap items-center gap-4">
                @if ($puedeEditar)
                    <a href="{{ route('admin.compras.edit', $compra) }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Editar borrador</a>
                @endif
                @if ($puedeConfirmar)
                    <form method="POST" action="{{ route('admin.compras.confirmar', $compra) }}" onsubmit="return confirm('¿Confirmar esta compra? Después no se podrá editar.')">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-white hover:bg-indigo-500">Confirmar compra</button>
                    </form>
                @endif
            </div>
        </div>

        @if ($compra->iva_10 !== null)
            <section>
                <h3 class="mb-2 font-semibold text-gray-900 dark:text-gray-100">Desglose del IVA</h3>
                <table class="text-sm tabular-nums">
                    <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th class="pe-8 font-normal">Tasa</th><th class="pe-8 font-normal">Monto (IVA incluido)</th><th class="font-normal">IVA</th></tr></thead>
                    <tbody class="text-gray-900 dark:text-gray-100">
                        <tr><td class="pe-8">10 %</td><td class="pe-8">{{ $dinero($compra->monto_10) }}</td><td>{{ $dinero($compra->iva_10) }}</td></tr>
                        <tr><td class="pe-8">5 %</td><td class="pe-8">{{ $dinero($compra->monto_5) }}</td><td>{{ $dinero($compra->iva_5) }}</td></tr>
                        <tr><td class="pe-8">Exento</td><td class="pe-8">{{ $dinero($compra->monto_exento) }}</td><td>—</td></tr>
                    </tbody>
                </table>
            </section>
        @endif

        @if ($puedeAnular)
            <details class="max-w-xl rounded-md border border-red-200 p-3 dark:border-red-900" @if ($errors->has('motivo')) open @endif>
                <summary class="cursor-pointer font-medium text-red-600 dark:text-red-400">{{ $compra->esBorrador() ? 'Descartar el borrador' : 'Anular la compra' }}</summary>
                <form method="POST" action="{{ route('admin.compras.anular', $compra) }}" class="mt-3 space-y-2">
                    @csrf
                    @method('PATCH')
                    <label for="motivo" class="block font-medium text-gray-700 dark:text-gray-300">Motivo (obligatorio)</label>
                    <textarea id="motivo" name="motivo" rows="3" minlength="{{ FlujoCompra::MOTIVO_MINIMO }}" maxlength="{{ FlujoCompra::MOTIVO_MAXIMO }}" required
                        class="block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ old('motivo') }}</textarea>
                    <x-input-error :messages="$errors->get('motivo')" />
                    <p class="text-gray-600 dark:text-gray-400">La compra no se borra: queda como anulada, con su motivo, y no se puede deshacer.</p>
                    <button type="submit" class="rounded-md border border-red-600 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/30">{{ $compra->esBorrador() ? 'Descartar' : 'Anular' }}</button>
                </form>
            </details>
        @endif
    </div>
</x-admin.page>
