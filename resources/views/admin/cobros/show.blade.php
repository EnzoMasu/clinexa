@use('App\Support\Fecha')
@use('App\Support\Cobros\RevertirCobro')

{{-- Detalle de un cobro (CobroController::show, docs/cobros-caja.md): las prestaciones que cubre y los medios. No se edita: se
     revierte completo ("Revertir" en la caja abierta propia; si no, "Solicitar la reversión", que Gerencia aprueba o rechaza). --}}
<x-admin.page title="Cobro N.º {{ $cobro->id }}">
    <div class="space-y-6 p-6 text-sm">
        @if ($cobro->revertido())
            <div role="status" class="max-w-3xl space-y-1 rounded-md bg-red-50 p-4 text-red-800 dark:bg-red-900/30 dark:text-red-200">
                <p class="font-semibold">Revertido el {{ Fecha::mostrar($cobro->revertido_en, conHora: true) }} por {{ $cobro->revertidoPor->persona?->nombre_completo ?? $cobro->revertidoPor->email }}</p>
                <p>Motivo: {{ $cobro->motivo_reversion }}</p>
                <p>
                    El dinero se devolvió desde la caja del {{ Fecha::mostrar($cobro->cajaReversion->fecha) }}@if ($veCajaReversion)
                        (<a href="{{ route('admin.cajas.show', $cobro->cajaReversion) }}" class="underline">ver la caja</a>)@endif.
                    Las prestaciones volvieron a quedar sin cobrar.
                </p>
            </div>
        @endif

        <dl class="grid max-w-3xl grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Fecha</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($cobro->cobrado_en, conHora: true) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Paciente</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $cobro->paciente->persona->nombre_completo }} ({{ $cobro->paciente->nro_ficha }})</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Total</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $cobro->totalTexto() }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Estado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $cobro->etiquetaEstado() }}</dd></div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Cajero</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    {{ $cobro->cajero->persona?->nombre_completo ?? $cobro->cajero->email }}
                    @if ($veCaja)
                        · <a href="{{ route('admin.cajas.show', $cobro->caja) }}" class="font-normal text-indigo-600 hover:underline dark:text-indigo-400">Caja del {{ Fecha::mostrar($cobro->caja->fecha) }}</a>
                    @endif
                </dd>
            </div>
        </dl>

        <section aria-labelledby="prestaciones-cobradas" class="max-w-4xl space-y-3">
            <h3 id="prestaciones-cobradas" class="font-semibold text-gray-900 dark:text-gray-100">Prestaciones</h3>
            <x-admin.table :headers="['Fecha de servicio', 'Procedimiento', 'Profesional', 'Monto']">
                @foreach ($cobro->prestaciones as $cubierta)
                    <tr>
                        <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($cubierta->prestacion->fecha_servicio) }}</td>
                        <td class="px-6 py-3">{{ $cubierta->prestacion->procedimiento?->nombre ?? 'Sin procedimiento' }}</td>
                        <td class="px-6 py-3">{{ $cubierta->prestacion->profesional->persona->nombre_completo }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $cobro->moneda->formatear($cubierta->monto) }}</td>
                        <td class="px-6 py-3 text-right">
                            <a href="{{ route('admin.prestaciones.show', $cubierta->prestacion) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        </section>

        <section aria-labelledby="pagos" class="max-w-3xl space-y-3">
            <h3 id="pagos" class="font-semibold text-gray-900 dark:text-gray-100">Pagos</h3>
            <x-admin.table :headers="['Medio de pago', 'Monto', 'Referencia']">
                @foreach ($cobro->lineas as $linea)
                    <tr>
                        <td class="px-6 py-3">{{ $linea->medioPago->nombre }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $cobro->moneda->formatear($linea->monto) }}</td>
                        <td class="px-6 py-3">{{ $linea->referencia ?? '—' }}</td>
                        <td></td>
                    </tr>
                @endforeach
            </x-admin.table>
        </section>

        @php($devolucion = $cobro->lineas->map(fn ($linea) => $linea->medioPago->nombre.' '.$cobro->moneda->formatear($linea->monto))->join(' · '))

        @if ($puedeRevertir)
            <details class="max-w-3xl rounded-md border border-red-200 p-4 dark:border-red-900" @if ($errors->has('motivo')) open @endif>
                <summary class="cursor-pointer font-medium text-red-700 dark:text-red-300">Revertir el cobro</summary>
                <form method="POST" action="{{ route('admin.cobros.revertir', $cobro) }}" novalidate class="mt-3 space-y-2">
                    @csrf
                    <p class="text-gray-600 dark:text-gray-400">Se revierte el cobro completo: devuelve desde su caja {{ $devolucion }} y las prestaciones vuelven a quedar sin cobrar. No se puede deshacer.</p>
                    <x-admin.textarea name="motivo" label="Motivo (obligatorio)" rows="2" minlength="{{ RevertirCobro::MOTIVO_MINIMO }}" maxlength="{{ RevertirCobro::MOTIVO_MAXIMO }}" required />
                    <x-danger-button>Revertir</x-danger-button>
                </form>
            </details>
        @endif

        @if ($puedeSolicitar)
            <details class="max-w-3xl rounded-md border border-amber-200 p-4 dark:border-amber-900" @if ($errors->has('motivo')) open @endif>
                <summary class="cursor-pointer font-medium text-amber-700 dark:text-amber-300">Solicitar la reversión</summary>
                <form method="POST" action="{{ route('admin.cobros.solicitar-reversion', $cobro) }}" novalidate class="mt-3 space-y-2">
                    @csrf
                    <p class="text-gray-600 dark:text-gray-400">El cobro es de una caja cerrada o de otro cajero: la reversión la aprueba Gerencia. Al aprobarse, usted devuelve desde su caja abierta de hoy {{ $devolucion }}.</p>
                    <x-admin.textarea name="motivo" label="Motivo (obligatorio)" rows="2" minlength="{{ RevertirCobro::MOTIVO_MINIMO }}" maxlength="{{ RevertirCobro::MOTIVO_MAXIMO }}" required />
                    <x-primary-button>Solicitar la reversión</x-primary-button>
                </form>
            </details>
        @endif

        @if ($pendiente)
            <section aria-labelledby="solicitud-pendiente" class="max-w-3xl space-y-3 rounded-md border border-amber-200 p-4 dark:border-amber-900">
                <h3 id="solicitud-pendiente" class="font-semibold text-gray-900 dark:text-gray-100">Reversión pendiente de aprobación</h3>
                <p class="text-gray-700 dark:text-gray-300">
                    Pedida el {{ Fecha::mostrar($pendiente->solicitada_en, conHora: true) }} por {{ $pendiente->solicitante->persona?->nombre_completo ?? $pendiente->solicitante->email }}.
                    Motivo: {{ $pendiente->motivo }}
                </p>
                @if ($sinCajaSolicitante)
                    <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ RevertirCobro::sinCajaDelSolicitante($pendiente->solicitante->persona?->nombre_completo ?? $pendiente->solicitante->email) }}</p>
                @endif
                @if ($puedeRevisar)
                    <form method="POST" action="{{ route('admin.cobros.reversiones.aprobar', $pendiente) }}" class="space-y-2">
                        @csrf
                        <x-admin.textarea name="nota" label="Nota (opcional)" rows="2" maxlength="{{ RevertirCobro::MOTIVO_MAXIMO }}" />
                        <x-primary-button>Aprobar la reversión</x-primary-button>
                    </form>
                    <details class="rounded-md border border-red-200 p-3 dark:border-red-900" @if ($errors->has('nota_rechazo')) open @endif>
                        <summary class="cursor-pointer font-medium text-red-700 dark:text-red-300">Rechazar</summary>
                        <form method="POST" action="{{ route('admin.cobros.reversiones.rechazar', $pendiente) }}" novalidate class="mt-3 space-y-2">
                            @csrf
                            <x-admin.textarea name="nota_rechazo" label="Por qué se rechaza (obligatorio)" rows="2" minlength="{{ RevertirCobro::MOTIVO_MINIMO }}" maxlength="{{ RevertirCobro::MOTIVO_MAXIMO }}" required />
                            <x-secondary-button type="submit">Rechazar</x-secondary-button>
                        </form>
                    </details>
                @endif
            </section>
        @endif

        @php($revisadas = $cobro->solicitudesReversion->reject(fn ($solicitud) => $solicitud->pendiente()))
        @if ($revisadas->isNotEmpty())
            <section aria-labelledby="solicitudes" class="max-w-3xl space-y-2">
                <h3 id="solicitudes" class="font-semibold text-gray-900 dark:text-gray-100">Solicitudes de reversión</h3>
                @foreach ($revisadas as $solicitud)
                    <p class="text-gray-700 dark:text-gray-300">
                        <span class="font-medium">{{ $solicitud->etiquetaEstado() }}</span> · pedida el {{ Fecha::mostrar($solicitud->solicitada_en, conHora: true) }}
                        por {{ $solicitud->solicitante->persona?->nombre_completo ?? $solicitud->solicitante->email }} ({{ $solicitud->motivo }})
                        · revisada el {{ Fecha::mostrar($solicitud->revisado_en, conHora: true) }} por {{ $solicitud->revisadoPor->persona?->nombre_completo ?? $solicitud->revisadoPor->email }}{{ $solicitud->nota_revision ? ': '.$solicitud->nota_revision : '' }}
                    </p>
                @endforeach
            </section>
        @endif

        <a href="{{ route('admin.cobros.index') }}" class="inline-block text-gray-600 hover:underline dark:text-gray-400">Volver a Cobros</a>
    </div>
</x-admin.page>
