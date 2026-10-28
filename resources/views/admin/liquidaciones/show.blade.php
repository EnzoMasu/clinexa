@use('App\Support\Fecha')

{{--
    Detalle de una liquidación (LiquidacionController::show, docs/liquidaciones-aseguradoras.md): sus líneas (con "Quitar" en preparación),
    el flujo (enviar, aprobar o devolver, anular), la planilla y los pagos de la aseguradora con el saldo. Sin nada clínico. Nada se borra.
--}}
<x-admin.page :title="'Liquidación N.º '.$liquidacion->id">
    <div class="space-y-8 p-6 text-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-3">
                <div><dt class="text-gray-500 dark:text-gray-400">Aseguradora</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $liquidacion->aseguradoraTexto() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Convenio</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $liquidacion->convenio?->nombre ?? 'Más de un convenio' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Fecha de corte</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($liquidacion->fecha_corte) }}</dd></div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Estado</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">
                        {{ $liquidacion->etiquetaEstado() }}
                        @if ($liquidacion->facturacion === App\Models\Liquidacion::PENDIENTE_DE_FACTURAR)
                            <div class="font-normal text-gray-600 dark:text-gray-400">Pendiente de facturar</div>
                        @endif
                    </dd>
                </div>
                <div><dt class="text-gray-500 dark:text-gray-400">Total</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $liquidacion->formatear($liquidacion->totalEntero()) }}</dd></div>
                @if ($liquidacion->aprobada())
                    <div><dt class="text-gray-500 dark:text-gray-400">Pagado / saldo</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $liquidacion->formatear($liquidacion->pagado()) }} / {{ $liquidacion->formatear($liquidacion->saldo()) }}</dd></div>
                @endif
                <div><dt class="text-gray-500 dark:text-gray-400">Armada</dt><dd>{{ Fecha::mostrar($liquidacion->armada_en, conHora: true) }} por {{ $liquidacion->armadaPor?->persona?->nombre_completo }}</dd></div>
                @if ($liquidacion->enviada_en)
                    <div><dt class="text-gray-500 dark:text-gray-400">Enviada a aprobación</dt><dd>{{ Fecha::mostrar($liquidacion->enviada_en, conHora: true) }} por {{ $liquidacion->enviadaPor?->persona?->nombre_completo }}</dd></div>
                @endif
                @if ($liquidacion->aprobada_en)
                    <div><dt class="text-gray-500 dark:text-gray-400">Aprobada</dt><dd>{{ Fecha::mostrar($liquidacion->aprobada_en, conHora: true) }} por {{ $liquidacion->aprobadaPor?->persona?->nombre_completo }}</dd></div>
                @endif
                @if ($liquidacion->devuelta_en)
                    <div class="sm:col-span-3"><dt class="text-gray-500 dark:text-gray-400">Última devolución</dt><dd>{{ Fecha::mostrar($liquidacion->devuelta_en, conHora: true) }} por {{ $liquidacion->devueltaPor?->persona?->nombre_completo }}: {{ $liquidacion->nota_devolucion }}</dd></div>
                @endif
                @if ($liquidacion->anulada())
                    <div class="sm:col-span-3"><dt class="text-gray-500 dark:text-gray-400">Anulada</dt><dd>{{ Fecha::mostrar($liquidacion->anulada_en, conHora: true) }} por {{ $liquidacion->anuladaPor?->persona?->nombre_completo }}: {{ $liquidacion->motivo_anulacion }}</dd></div>
                @endif
            </dl>
            <div class="flex flex-col items-end gap-2">
                @if ($puedePlanilla)
                    <a href="{{ route('admin.liquidaciones.planilla', $liquidacion) }}" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Planilla (PDF)</a>
                @endif
                <a href="{{ route('admin.liquidaciones.index') }}" class="text-gray-600 hover:underline dark:text-gray-400">Volver a Liquidaciones</a>
            </div>
        </div>

        @if ($puedeEnviar || $puedeRevisar || $esPropia)
            <section class="flex flex-wrap items-start gap-6 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                @if ($puedeEnviar)
                    <form method="POST" action="{{ route('admin.liquidaciones.enviar', $liquidacion) }}"
                        x-data x-on:submit="if (! confirm('¿Enviar la liquidación a aprobación? Después ya no se pueden quitar prestaciones, salvo que la devuelvan.')) $event.preventDefault()">
                        @csrf
                        <x-primary-button>Enviar a aprobación</x-primary-button>
                    </form>
                @endif
                @if ($puedeRevisar)
                    <form method="POST" action="{{ route('admin.liquidaciones.aprobar', $liquidacion) }}"
                        x-data x-on:submit="if (! confirm('¿Aprobar la liquidación? Queda bloqueada: sus prestaciones ya no cambian.')) $event.preventDefault()">
                        @csrf
                        <x-primary-button>Aprobar la liquidación</x-primary-button>
                    </form>
                    <form method="POST" action="{{ route('admin.liquidaciones.devolver', $liquidacion) }}" novalidate class="w-80 space-y-2">
                        @csrf
                        <x-admin.textarea name="nota_devolucion" label="Qué hay que corregir" rows="2" />
                        <x-secondary-button type="submit">Devolver</x-secondary-button>
                    </form>
                @endif
                @if ($esPropia)
                    <p class="text-gray-600 dark:text-gray-400">Usted armó esta liquidación: la aprueba o la devuelve otra persona.</p>
                @endif
            </section>
        @endif

        <section aria-labelledby="lineas" class="space-y-3">
            <h3 id="lineas" class="font-semibold text-gray-900 dark:text-gray-100">Prestaciones</h3>
            <x-admin.table :headers="['Fecha', 'Paciente', 'Documento', 'N.º de asegurado', 'Procedimiento', 'Visación', 'Monto']">
                @forelse ($lineas as $linea)
                    <tr @class(['text-gray-400 dark:text-gray-500' => $linea->quitada()])>
                        <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($linea->fecha_servicio) }}</td>
                        <td class="px-6 py-3">{{ $linea->paciente }}</td>
                        <td class="px-6 py-3 whitespace-nowrap">{{ $linea->documento }}</td>
                        <td class="px-6 py-3">{{ $linea->numero_asegurado }}</td>
                        <td class="px-6 py-3">{{ $linea->procedimiento }}</td>
                        <td class="px-6 py-3">N.º {{ $linea->numero_visacion }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($linea->montoEntero()) }}</td>
                        <td class="px-6 py-3 text-right">
                            <div class="flex flex-col items-end gap-1">
                                @if ($linea->quitada())
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Quitada el {{ Fecha::mostrar($linea->liberada_en, conHora: true) }}: {{ $linea->motivo_liberacion }}</span>
                                @elseif ($puedeQuitar)
                                    <details class="text-left">
                                        <summary class="cursor-pointer font-medium text-red-600 dark:text-red-400">Quitar</summary>
                                        <form method="POST" action="{{ route('admin.liquidaciones.lineas.quitar', $linea) }}" novalidate class="mt-2 w-56 space-y-2">
                                            @csrf
                                            <x-admin.textarea name="motivo_quitar" label="Motivo" rows="2" />
                                            <x-secondary-button type="submit">Quitar de la liquidación</x-secondary-button>
                                        </form>
                                    </details>
                                @endif
                                @if ($vePrestaciones)
                                    <a href="{{ route('admin.prestaciones.show', $linea->prestacion_id) }}" class="text-gray-600 hover:underline dark:text-gray-400">Ver la prestación</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-6 py-4 text-gray-500 dark:text-gray-400">Sin prestaciones.</td></tr>
                @endforelse
            </x-admin.table>
        </section>

        @if ($vePagos && ($liquidacion->aprobada() || $liquidacion->pagos->isNotEmpty()))
            <section aria-labelledby="pagos" class="space-y-3">
                <h3 id="pagos" class="font-semibold text-gray-900 dark:text-gray-100">Pagos de la aseguradora</h3>
                <p class="text-gray-600 dark:text-gray-400">Una cuenta por cobrar aparte: los pagos de la aseguradora no pasan por la Caja.</p>
                <x-admin.table :headers="['Fecha', 'Monto', 'Medio', 'Referencia', 'Cargado por', 'Estado']">
                    @forelse ($liquidacion->pagos->sortBy('id') as $pago)
                        <tr @class(['text-gray-400 dark:text-gray-500' => $pago->anulado()])>
                            <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($pago->fecha) }}</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($pago->montoEntero()) }}</td>
                            <td class="px-6 py-3">{{ $pago->medioPago->nombre }}</td>
                            <td class="px-6 py-3">{{ $pago->referencia ?? '—' }}</td>
                            <td class="px-6 py-3">{{ $pago->usuario?->persona?->nombre_completo }}</td>
                            <td class="px-6 py-3">
                                {{ $pago->etiquetaEstado() }}
                                @if ($pago->anulado())
                                    <div class="text-xs">{{ Fecha::mostrar($pago->anulado_en, conHora: true) }} por {{ $pago->anuladoPor?->persona?->nombre_completo }}: {{ $pago->motivo_anulacion }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-right">
                                @if (! $pago->anulado() && $puedeAnularPago)
                                    <details class="text-left">
                                        <summary class="cursor-pointer font-medium text-red-600 dark:text-red-400">Anular</summary>
                                        <form method="POST" action="{{ route('admin.liquidaciones.pagos.anular', $pago) }}" novalidate class="mt-2 w-56 space-y-2">
                                            @csrf
                                            <x-admin.textarea name="motivo_pago" label="Motivo" rows="2" />
                                            <x-secondary-button type="submit">Anular el pago</x-secondary-button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-4 text-gray-500 dark:text-gray-400">Todavía no hay pagos.</td></tr>
                    @endforelse
                </x-admin.table>

                @if ($puedePagar)
                    <form method="POST" action="{{ route('admin.liquidaciones.pagos.store', $liquidacion) }}" novalidate class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="w-40"><x-admin.input name="monto" label="Monto (₲)" inputmode="numeric" :value="(string) $liquidacion->saldo()" required /></div>
                        <div class="w-40"><x-admin.fecha name="fecha" label="Fecha" :value="Fecha::hoy()" hasta-hoy /></div>
                        <div class="w-48"><x-admin.select name="medio_pago_id" label="Medio" :options="$medios" :value="$medioSugerido" required /></div>
                        <div class="w-48"><x-admin.input name="referencia" label="Referencia (opcional)" maxlength="100" /></div>
                        <x-primary-button>Registrar el pago</x-primary-button>
                    </form>
                @endif
            </section>
        @endif

        @if ($puedeAnular)
            <section class="space-y-3 border-t border-gray-200 pt-6 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">Anular la liquidación</h3>
                <p class="text-gray-600 dark:text-gray-400">No se borra nada: queda anulada con el motivo y sus prestaciones quedan libres para la siguiente.</p>
                <form method="POST" action="{{ route('admin.liquidaciones.anular', $liquidacion) }}" novalidate class="w-96 space-y-2"
                    x-data x-on:submit="if (! confirm('¿Anular la liquidación?')) $event.preventDefault()">
                    @csrf
                    <x-admin.textarea name="motivo_anulacion" label="Motivo" rows="2" />
                    <x-danger-button>Anular la liquidación</x-danger-button>
                </form>
            </section>
        @endif
    </div>
</x-admin.page>
