@use('App\Support\Fecha')
@use('App\Support\Facturacion\AnularFactura')

{{-- Detalle de una factura (FacturaController::show, docs/facturacion.md): datos, líneas, totales por tasa, su cobro, el PDF y la
     anulación ("Anular la factura" dentro del plazo en la caja abierta propia; si no, "Solicitar la anulación", que Gerencia aprueba o
     rechaza). No se edita. Sin contenido clínico. --}}
<x-admin.page title="Factura {{ $factura->numero_completo }}">
    @include('admin.facturas._simulacion')
    <div class="space-y-6 p-6 text-sm">
        @if ($factura->anulada())
            <div role="status" class="max-w-3xl space-y-1 rounded-md bg-red-50 p-4 text-red-800 dark:bg-red-900/30 dark:text-red-200">
                <p class="font-semibold">Anulada el {{ Fecha::mostrar($factura->anulada_en, conHora: true) }} por {{ $factura->anuladaPor->persona?->nombre_completo ?? $factura->anuladaPor->email }}</p>
                <p>Motivo: {{ $factura->motivoAnulacion->nombre }}@if ($factura->detalle_anulacion) ({{ $factura->detalle_anulacion }})@endif</p>
                <p>Su número no se vuelve a usar. Las prestaciones volvieron a quedar sin facturar ni cobrar.</p>
                @if ($factura->reemplazadaPor)
                    <p>La reemplaza la factura <a href="{{ route('admin.facturas.show', $factura->reemplazadaPor) }}" class="underline">{{ $factura->reemplazadaPor->numero_completo }}</a>.</p>
                @elseif ($puedeReemplazar)
                    <p><a href="{{ route('admin.cobros.create', ['paciente' => $factura->paciente_id, 'reemplaza' => $factura->id]) }}" class="font-medium underline">Cobrar de nuevo con una factura que reemplace a esta</a></p>
                @endif
            </div>
        @endif

        <dl class="grid max-w-4xl grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-3">
            <div><dt class="text-gray-500 dark:text-gray-400">Fecha y hora</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($factura->emitida_en, conHora: true) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Condición</dt><dd class="font-medium text-gray-900 dark:text-gray-100">Contado</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Estado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $factura->etiquetaEstado() }} · {{ $factura->estadoCobroTexto() }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Timbrado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">N.º {{ $factura->numero_timbrado }} (vigencia {{ Fecha::mostrar($factura->timbrado_desde) }} al {{ Fecha::mostrar($factura->timbrado_hasta) }})</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Emisor</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $factura->emisor_razon_social }} · RUC {{ $factura->emisor_ruc }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Cliente</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $factura->cliente_nombre }} · {{ $factura->cliente_documento }}</dd></div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Emitida por</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    {{ $factura->emisor->persona?->nombre_completo ?? $factura->emisor->email }}
                    @if ($veCobro)
                        · <a href="{{ route('admin.cobros.show', $factura->cobro) }}" class="font-normal text-indigo-600 hover:underline dark:text-indigo-400">Cobro N.º {{ $factura->cobro->id }} ({{ $factura->cobro->etiquetaEstado() }})</a>
                    @endif
                </dd>
            </div>
            @if ($factura->reemplazaA)
                <div><dt class="text-gray-500 dark:text-gray-400">Reemplaza a</dt><dd class="font-medium"><a href="{{ route('admin.facturas.show', $factura->reemplazaA) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $factura->reemplazaA->numero_completo }}</a> (anulada)</dd></div>
            @endif
        </dl>

        @if ($puedePdf)
            <a href="{{ route('admin.facturas.imprimir', $factura) }}" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 font-medium text-white hover:bg-indigo-500">Imprimir factura</a>
            <a href="{{ route('admin.facturas.pdf', $factura) }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">Factura (PDF)</a>
        @endif

        <section aria-labelledby="lineas" class="max-w-4xl space-y-3">
            <h3 id="lineas" class="font-semibold text-gray-900 dark:text-gray-100">Líneas</h3>
            <x-admin.table :headers="['Cant.', 'Descripción', 'Precio (IVA incluido)', 'Tasa', 'Gravado', 'IVA']">
                @foreach ($factura->detalles->sortBy('id') as $detalle)
                    <tr>
                        <td class="px-6 py-3 tabular-nums">{{ $detalle->cantidad }}</td>
                        <td class="px-6 py-3">{{ $detalle->descripcion }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $factura->formatear($detalle->precio_unitario) }}</td>
                        <td class="px-6 py-3 whitespace-nowrap">{{ $detalle->tasaTexto() }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $factura->formatear($detalle->gravado) }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $factura->formatear($detalle->iva) }}</td>
                        <td class="px-6 py-3 text-right">
                            <a href="{{ route('admin.prestaciones.show', $detalle->prestacion_id) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Prestación</a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        </section>

        <section aria-labelledby="totales" class="max-w-md space-y-2">
            <h3 id="totales" class="font-semibold text-gray-900 dark:text-gray-100">Totales por tasa</h3>
            <dl class="divide-y divide-gray-200 rounded-md border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                @foreach (App\Models\Factura::TOTALES as $columna => $texto)
                    <div class="flex justify-between px-3 py-1.5"><dt>{{ $texto }}</dt><dd class="tabular-nums">{{ $factura->formatear($factura->{$columna}) }}</dd></div>
                @endforeach
                <div class="flex justify-between px-3 py-1.5 font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ $factura->totalTexto() }}</dd></div>
            </dl>
        </section>

        @foreach ([[$puedeAnular, 'admin.facturas.anular', 'Anular la factura', 'red',
                    'Dentro de los '.AnularFactura::plazoMinutos().' minutos desde la emisión (hasta el '.Fecha::mostrar($limite, conHora: true).'): en una sola acción se revierte el cobro (se devuelven '.$factura->totalTexto().' desde su caja) y se anula la factura. No se puede deshacer.',
                    'Anular'],
                   [$puedeSolicitar, 'admin.facturas.solicitar-anulacion', 'Solicitar la anulación', 'amber',
                    'Pasó el plazo, la caja está cerrada o la factura es de otro cajero: la anulación la aprueba Gerencia. Al aprobarse, usted devuelve '.$factura->totalTexto().' desde su caja abierta de hoy.',
                    'Solicitar la anulación']] as [$corresponde, $ruta, $titulo, $color, $explicacion, $boton])
            @if ($corresponde)
                <details @class(['max-w-3xl rounded-md border p-4', 'border-red-200 dark:border-red-900' => $color === 'red', 'border-amber-200 dark:border-amber-900' => $color === 'amber'])
                    @if ($errors->has('motivo_anulacion_id') || $errors->has('detalle')) open @endif>
                    <summary @class(['cursor-pointer font-medium', 'text-red-700 dark:text-red-300' => $color === 'red', 'text-amber-700 dark:text-amber-300' => $color === 'amber'])>{{ $titulo }}</summary>
                    <form method="POST" action="{{ route($ruta, $factura) }}" novalidate class="mt-3 space-y-2">
                        @csrf
                        <p class="text-gray-600 dark:text-gray-400">{{ $explicacion }}</p>
                        <x-admin.select name="motivo_anulacion_id" label="Motivo" :options="$motivos->pluck('nombre', 'id')->all()" required />
                        <x-admin.textarea name="detalle" label="Detalle (obligatorio en «Otro»)" rows="2" maxlength="{{ AnularFactura::DETALLE_MAXIMO }}" />
                        @if ($color === 'red')
                            <x-danger-button>{{ $boton }}</x-danger-button>
                        @else
                            <x-primary-button>{{ $boton }}</x-primary-button>
                        @endif
                    </form>
                </details>
            @endif
        @endforeach

        @if ($pendiente)
            <section aria-labelledby="solicitud-pendiente" class="max-w-3xl space-y-3 rounded-md border border-amber-200 p-4 dark:border-amber-900">
                <h3 id="solicitud-pendiente" class="font-semibold text-gray-900 dark:text-gray-100">Anulación pendiente de aprobación</h3>
                <p class="text-gray-700 dark:text-gray-300">
                    Pedida el {{ Fecha::mostrar($pendiente->solicitada_en, conHora: true) }} por {{ $pendiente->solicitante->persona?->nombre_completo ?? $pendiente->solicitante->email }}.
                    Motivo: {{ $pendiente->motivoTexto() }}
                </p>
                @if ($sinCajaSolicitante)
                    <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ AnularFactura::sinCajaDelSolicitante($pendiente->solicitante->persona?->nombre_completo ?? $pendiente->solicitante->email) }}</p>
                @endif
                @if ($puedeRevisar)
                    <form method="POST" action="{{ route('admin.facturas.solicitudes.aprobar', $pendiente) }}" class="space-y-2">
                        @csrf
                        <x-admin.textarea name="nota" label="Nota (opcional)" rows="2" maxlength="{{ AnularFactura::DETALLE_MAXIMO }}" />
                        <x-primary-button>Aprobar la anulación</x-primary-button>
                    </form>
                    <details class="rounded-md border border-red-200 p-3 dark:border-red-900" @if ($errors->has('nota_rechazo')) open @endif>
                        <summary class="cursor-pointer font-medium text-red-700 dark:text-red-300">Rechazar</summary>
                        <form method="POST" action="{{ route('admin.facturas.solicitudes.rechazar', $pendiente) }}" novalidate class="mt-3 space-y-2">
                            @csrf
                            <x-admin.textarea name="nota_rechazo" label="Por qué se rechaza (obligatorio)" rows="2" minlength="{{ AnularFactura::DETALLE_MINIMO }}" maxlength="{{ AnularFactura::DETALLE_MAXIMO }}" required />
                            <x-secondary-button type="submit">Rechazar</x-secondary-button>
                        </form>
                    </details>
                @endif
            </section>
        @endif

        @php($revisadas = $factura->solicitudesAnulacion->reject(fn ($solicitud) => $solicitud->pendiente()))
        @if ($revisadas->isNotEmpty())
            <section aria-labelledby="solicitudes" class="max-w-3xl space-y-2">
                <h3 id="solicitudes" class="font-semibold text-gray-900 dark:text-gray-100">Solicitudes de anulación</h3>
                @foreach ($revisadas as $solicitud)
                    <p class="text-gray-700 dark:text-gray-300">
                        <span class="font-medium">{{ $solicitud->etiquetaEstado() }}</span> · pedida el {{ Fecha::mostrar($solicitud->solicitada_en, conHora: true) }}
                        por {{ $solicitud->solicitante->persona?->nombre_completo ?? $solicitud->solicitante->email }} ({{ $solicitud->motivoTexto() }})
                        · revisada el {{ Fecha::mostrar($solicitud->revisado_en, conHora: true) }} por {{ $solicitud->revisadoPor->persona?->nombre_completo ?? $solicitud->revisadoPor->email }}{{ $solicitud->nota_revision ? ': '.$solicitud->nota_revision : '' }}
                    </p>
                @endforeach
            </section>
        @endif

        <a href="{{ route('admin.facturas.index') }}" class="inline-block text-gray-600 hover:underline dark:text-gray-400">Volver a Facturas</a>
    </div>
</x-admin.page>
