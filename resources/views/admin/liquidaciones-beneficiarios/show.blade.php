@use('App\Support\Fecha')
@use('App\Support\Cobros\AnularSalida')

{{--
    Detalle de una liquidación a beneficiario (LiquidacionBeneficiarioController::show, docs/liquidacion-beneficiarios.md): sus líneas
    (ordinarias, ajustes y saldo anterior, con "Quitar" en preparación y la marca de las que dejaron de corresponder), el flujo (enviar,
    aprobar o devolver, anular), la planilla y los pagos con el saldo (sin caja, o en efectivo desde la caja abierta de hoy de quien paga).
    Sin nada clínico. Nada se borra.
--}}
<x-admin.page :title="'Liquidación a beneficiario N.º '.$liquidacion->id">
    <div class="space-y-8 p-6 text-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-3">
                <div><dt class="text-gray-500 dark:text-gray-400">Beneficiario</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $liquidacion->beneficiarioTexto() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Tipo</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $liquidacion->tipoTexto() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Fecha de corte</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($liquidacion->fecha_corte) }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Estado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $liquidacion->etiquetaEstado() }}</dd></div>
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
                    <a href="{{ route('admin.liquidaciones-beneficiarios.planilla', $liquidacion) }}" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Planilla (PDF)</a>
                @endif
                <a href="{{ route('admin.liquidaciones-beneficiarios.index') }}" class="text-gray-600 hover:underline dark:text-gray-400">Volver a Liquidaciones a beneficiarios</a>
            </div>
        </div>

        @if ($liquidacion->saldoEnContra() > 0)
            <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                Saldo en contra del beneficiario: {{ $liquidacion->formatear($liquidacion->saldoEnContra()) }}. No se paga: pasa a su liquidación siguiente.
            </p>
        @endif
        @if ($marcadas->isNotEmpty())
            <p role="alert" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                {{ \App\Support\LiquidacionesBeneficiarios\FlujoLiquidacionBeneficiario::conMarcadas($marcadas->count(), $liquidacion->pendienteDeAprobacion()) }}
            </p>
        @endif

        @if ($puedeEnviar || $puedeRevisar || $esPropia)
            <section class="flex flex-wrap items-start gap-6 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                @if ($puedeEnviar)
                    <form method="POST" action="{{ route('admin.liquidaciones-beneficiarios.enviar', $liquidacion) }}"
                        x-data x-on:submit="if (! confirm('¿Enviar la liquidación a aprobación? Después ya no se pueden quitar líneas, salvo que la devuelvan.')) $event.preventDefault()">
                        @csrf
                        <x-primary-button>Enviar a aprobación</x-primary-button>
                    </form>
                @endif
                @if ($puedeRevisar)
                    <form method="POST" action="{{ route('admin.liquidaciones-beneficiarios.aprobar', $liquidacion) }}"
                        x-data x-on:submit="if (! confirm('¿Aprobar la liquidación? Queda bloqueada y lista para pagar.')) $event.preventDefault()">
                        @csrf
                        <x-primary-button>Aprobar la liquidación</x-primary-button>
                    </form>
                    <form method="POST" action="{{ route('admin.liquidaciones-beneficiarios.devolver', $liquidacion) }}" novalidate class="w-80 space-y-2">
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
            <h3 id="lineas" class="font-semibold text-gray-900 dark:text-gray-100">Líneas</h3>
            @include('admin.liquidaciones-beneficiarios._lineas', ['filas' => $lineas, 'conPaciente' => $liquidacion->deProfesional(), 'moneda' => $liquidacion->moneda])
        </section>

        @if ($vePagos && ($liquidacion->aprobada() || $liquidacion->pagos->isNotEmpty()))
            <section aria-labelledby="pagos" class="space-y-3">
                <h3 id="pagos" class="font-semibold text-gray-900 dark:text-gray-100">Pagos al beneficiario</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Aparte de la caja de cobros. En efectivo, puede salir de su caja abierta de hoy (como una salida de esa caja); entonces se anula
                    con las reglas de la salida: usted, dentro de los {{ AnularSalida::plazoMinutos() }} minutos; después, con la solicitud que aprueba
                    Gerencia; con la caja cerrada, ya no.
                </p>
                <x-admin.table :headers="['Fecha', 'Monto', 'Medio', 'Comprobante', 'Caja', 'Cargado por', 'Estado']">
                    @forelse ($liquidacion->pagos->sortBy('id') as $pago)
                        <tr @class(['text-gray-400 dark:text-gray-500' => $pago->anulado()])>
                            <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($pago->fecha) }}</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($pago->montoEntero()) }}</td>
                            <td class="px-6 py-3">{{ $pago->medioPago->nombre }}</td>
                            <td class="px-6 py-3">{{ $pago->referencia ?? '—' }}</td>
                            <td class="px-6 py-3">
                                @if ($pago->conCaja())
                                    @if ($veCajas)
                                        <a href="{{ route('admin.cajas.show', $pago->caja_id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Caja N.º {{ $pago->caja_id }}</a>
                                    @else
                                        Caja N.º {{ $pago->caja_id }}
                                    @endif
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $pago->categoriaGasto?->nombre }}</div>
                                    @if (! $pago->anulado() && $pago->salida?->solicitudPendiente)
                                        <div class="text-xs font-medium text-amber-700 dark:text-amber-300">Anulación solicitada a Gerencia</div>
                                    @endif
                                @else
                                    Sin caja
                                @endif
                            </td>
                            <td class="px-6 py-3">{{ $pago->usuario?->persona?->nombre_completo }}</td>
                            <td class="px-6 py-3">
                                {{ $pago->etiquetaEstado() }}
                                @if ($pago->anulado())
                                    <div class="text-xs">{{ Fecha::mostrar($pago->anulado_en, conHora: true) }} por {{ $pago->anuladoPor?->persona?->nombre_completo }}: {{ $pago->motivo_anulacion }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-right">
                                @if ($puedeAnularPago && \App\Support\LiquidacionesBeneficiarios\PagosBeneficiario::puedeAnular(auth()->user(), $pago))
                                    <details class="text-left">
                                        <summary class="cursor-pointer font-medium text-red-600 dark:text-red-400">{{ $pago->conCaja() && ! AnularSalida::dentroDelPlazo($pago->salida) ? 'Solicitar la anulación' : 'Anular' }}</summary>
                                        <form method="POST" action="{{ route('admin.liquidaciones-beneficiarios.pagos.anular', $pago) }}" novalidate class="mt-2 w-56 space-y-2">
                                            @csrf
                                            <x-admin.textarea name="motivo_pago" label="Motivo" rows="2" />
                                            <x-secondary-button type="submit">{{ $pago->conCaja() && ! AnularSalida::dentroDelPlazo($pago->salida) ? 'Solicitar a Gerencia' : 'Anular el pago' }}</x-secondary-button>
                                        </form>
                                    </details>
                                @elseif (! $pago->anulado() && $pago->conCaja() && ! AnularSalida::cajaAbiertaDeHoy($pago->salida->caja))
                                    <span class="text-xs text-gray-500 dark:text-gray-400">La caja ya está cerrada: no se anula.</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-4 text-gray-500 dark:text-gray-400">Todavía no hay pagos.</td></tr>
                    @endforelse
                </x-admin.table>

                @if ($puedePagar)
                    <form method="POST" action="{{ route('admin.liquidaciones-beneficiarios.pagos.store', $liquidacion) }}" novalidate class="flex flex-wrap items-end gap-3" x-data="medioComprobante()">
                        @csrf
                        <div class="w-40"><x-admin.input name="monto" label="Monto (₲)" inputmode="numeric" :value="(string) $liquidacion->saldo()" required /></div>
                        <div class="w-40"><x-admin.fecha name="fecha" label="Fecha" :value="Fecha::hoy()" hasta-hoy /></div>
                        <div class="w-48">
                            <x-admin.select name="medio_pago_id" label="Medio" :value="$medioSugerido" required data-medio x-on:change="actualizar()">
                                @foreach ($medios as $medio)
                                    <option value="{{ $medio->id }}" @if ($medio->exigeComprobante()) data-comprobante="{{ $medio->etiquetaComprobante() }}" @endif @selected((string) old('medio_pago_id', $medioSugerido) === (string) $medio->id)>{{ $medio->nombre }}</option>
                                @endforeach
                            </x-admin.select>
                        </div>
                        <div class="w-48">
                            <label for="referencia" data-etiqueta-comprobante class="block font-medium text-sm text-gray-700 dark:text-gray-300">Comprobante (obligatorio salvo en efectivo)</label>
                            <input id="referencia" name="referencia" type="text" value="{{ old('referencia') }}" maxlength="100" data-comprobante class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                            <x-input-error class="mt-2" :messages="$errors->get('referencia')" />
                        </div>
                        @if ($cajas !== [])
                            <div class="w-72"><x-admin.select name="caja_id" label="Desde la caja (solo efectivo)" :options="$cajas" nullable /></div>
                            <div class="w-56"><x-admin.select name="categoria_gasto_id" label="Categoría de gasto (con caja)" :options="$categorias" nullable /></div>
                        @endif
                        <x-primary-button>Registrar el pago</x-primary-button>
                    </form>
                    @if ($cajas === [])
                        <p class="text-gray-600 dark:text-gray-400">Para pagar en efectivo desde una caja, abra la suya en Cajas: sin caja, el pago queda registrado aparte.</p>
                    @endif
                @endif
            </section>
        @endif

        @if (! $liquidacion->anulada())
            <section class="space-y-3 border-t border-gray-200 pt-6 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">Anular la liquidación</h3>
                @if ($puedeAnular)
                    <p class="text-gray-600 dark:text-gray-400">No se borra nada: queda anulada con el motivo y sus líneas quedan libres para la siguiente.</p>
                    <form method="POST" action="{{ route('admin.liquidaciones-beneficiarios.anular', $liquidacion) }}" novalidate class="w-96 space-y-2"
                        x-data x-on:submit="if (! confirm('¿Anular la liquidación?')) $event.preventDefault()">
                        @csrf
                        <x-admin.textarea name="motivo_anulacion" label="Motivo" rows="2" />
                        <x-danger-button>Anular la liquidación</x-danger-button>
                    </form>
                @elseif ($impedimentoAnular)
                    <p class="text-gray-600 dark:text-gray-400">{{ $impedimentoAnular }}</p>
                @else
                    <p class="text-gray-600 dark:text-gray-400">{{ $liquidacion->aprobada() ? 'Una liquidación aprobada solo la anula quien aprueba las liquidaciones.' : 'No tiene permiso para anularla.' }}</p>
                @endif
            </section>
        @endif
    </div>
</x-admin.page>
