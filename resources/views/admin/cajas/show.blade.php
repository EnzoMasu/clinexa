@use('App\Support\Fecha')
@use('App\Support\Cobros\CerrarCaja')
@use('App\Support\Cobros\RevisarCierre')

{{-- Detalle de una caja (CajaController::show, docs/cobros-caja.md): lo esperado por medio, los cobros, los cierres y, según el caso,
     el formulario de cierre (su cajero) o la aprobación y la devolución (Gerencia). --}}
<x-admin.page title="Caja del {{ Fecha::mostrar($caja->fecha) }}">
    <div class="space-y-6 p-6 text-sm">
        <dl class="grid max-w-3xl grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Estado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $caja->etiquetaEstado() }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Cajero</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $caja->cajero->persona?->nombre_completo ?? $caja->cajero->email }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Apertura</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($caja->abierta_en, conHora: true) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Monto inicial (efectivo)</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $caja->montoInicialTexto() }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Sucursal</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $caja->sucursal?->nombre }}</dd></div>
        </dl>

        @if ($puedeImprimir)
            <a href="{{ route('admin.cajas.arqueo', $caja) }}" target="_blank" rel="noopener" class="inline-block font-medium text-indigo-600 hover:underline dark:text-indigo-400">Imprimir el arqueo (PDF)</a>
        @endif

        @if ($puedeCorregirCobros)
            <p role="status" class="max-w-3xl rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                Arqueo devuelto por diferencias. Puede corregir el medio de pago y el comprobante de cada cobro desde "Ver" (no el monto: la
                anulación sigue su propio camino). Lo esperado se recalcula solo; después declare lo contado y reenvíe.
            </p>
        @endif

        <section aria-labelledby="esperado" class="max-w-3xl space-y-3">
            <h3 id="esperado" class="font-semibold text-gray-900 dark:text-gray-100">Lo esperado por medio de pago</h3>
            <p class="text-gray-600 dark:text-gray-400">El monto inicial (solo en efectivo) más los cobros, menos lo devuelto por cobros revertidos y las salidas de caja vigentes (en efectivo).</p>
            <x-admin.table :headers="['Medio de pago', 'Esperado']">
                @forelse ($mediosConMovimientos as $medio)
                    <tr>
                        <td class="px-6 py-3">{{ $medio->nombre }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear($esperado[$medio->id] ?? 0) }}</td>
                        <td></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">Sin movimientos.</td></tr>
                @endforelse
            </x-admin.table>
        </section>

        <section aria-labelledby="cobros-caja" class="max-w-4xl space-y-3">
            <h3 id="cobros-caja" class="font-semibold text-gray-900 dark:text-gray-100">Cobros ({{ $cobros->count() }})</h3>
            <x-admin.table :headers="['Hora', 'Paciente', 'Medios', 'Comprobantes', 'Total']">
                @forelse ($cobros as $cobro)
                    <tr>
                        <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($cobro->cobrado_en, conHora: true) }}</td>
                        <td class="px-6 py-3">{{ $cobro->paciente->persona->nombre_completo }}</td>
                        <td class="px-6 py-3">{{ $cobro->lineas->map(fn ($linea) => $linea->medioPago->nombre.' '.$cobro->moneda->formatear($linea->monto))->join(' · ') }}</td>
                        <td class="px-6 py-3">{{ $cobro->lineas->filter(fn ($linea) => $linea->referencia !== null)->map(fn ($linea) => $linea->medioPago->nombre.': '.$linea->referencia)->join(' · ') ?: '—' }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $cobro->totalTexto() }}@if ($cobro->revertido()) <span class="font-semibold text-red-700 dark:text-red-300">(revertido)</span>@endif</td>
                        <td class="px-6 py-3 text-right">
                            @if ($veCobros)
                                <a href="{{ route('admin.cobros.show', $cobro) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">Todavía no hay cobros en esta caja.</td></tr>
                @endforelse
            </x-admin.table>
        </section>

        @if ($devoluciones->isNotEmpty())
            <section aria-labelledby="devoluciones-caja" class="max-w-4xl space-y-3">
                <h3 id="devoluciones-caja" class="font-semibold text-gray-900 dark:text-gray-100">Devoluciones de cobros revertidos ({{ $devoluciones->count() }})</h3>
                <x-admin.table :headers="['Hora', 'Cobro', 'Paciente', 'Medio de pago', 'Egreso']">
                    @foreach ($devoluciones as $devolucion)
                        <tr>
                            <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($devolucion->registrado_en, conHora: true) }}</td>
                            <td class="px-6 py-3 whitespace-nowrap">N.º {{ $devolucion->cobro_id }}</td>
                            <td class="px-6 py-3">{{ $devolucion->cobro->paciente->persona->nombre_completo }}</td>
                            <td class="px-6 py-3">{{ $devolucion->medioPago->nombre }}</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear($devolucion->monto) }}</td>
                            <td class="px-6 py-3 text-right">
                                @if ($veCobros)
                                    <a href="{{ route('admin.cobros.show', $devolucion->cobro_id) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </section>
        @endif

        {{-- Salidas de dinero (Cobros\RegistrarSalida y Cobros\AnularSalida): siempre en efectivo, restan de lo esperado; la anulada se
             muestra tachada y su monto vuelve al efectivo. --}}
        @if ($salidas->isNotEmpty() || $puedeRegistrarSalida)
            <section id="salidas-caja" aria-labelledby="salidas-caja-titulo" class="max-w-5xl space-y-3">
                <h3 id="salidas-caja-titulo" class="font-semibold text-gray-900 dark:text-gray-100">Salidas de caja ({{ $salidas->count() }})</h3>
                <x-admin.table :headers="['Hora', 'Categoría', 'Beneficiario', 'Comprobante', 'Concepto', 'Monto']">
                    @forelse ($salidas as $salida)
                        <tr>
                            <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($salida->registrada_en, conHora: true) }}</td>
                            <td class="px-6 py-3">{{ $salida->categoria->nombre }}</td>
                            <td class="px-6 py-3">{{ $salida->beneficiario }}</td>
                            <td class="px-6 py-3">{{ $salida->comprobante ?? '—' }}</td>
                            <td class="px-6 py-3">
                                {{ $salida->concepto }}
                                @if ($salida->anulada())
                                    <p class="mt-1 text-xs text-red-700 dark:text-red-300">Anulada el {{ Fecha::mostrar($salida->anulada_en, conHora: true) }}{{ $salida->anuladaPor ? ' por '.($salida->anuladaPor->persona?->nombre_completo ?? $salida->anuladaPor->email) : '' }}. Motivo: {{ $salida->motivo_anulacion }}</p>
                                @elseif ($salida->solicitudPendiente)
                                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">Anulación pedida a Gerencia el {{ Fecha::mostrar($salida->solicitudPendiente->solicitada_en, conHora: true) }}: pendiente de aprobación.</p>
                                @endif
                            </td>
                            <td @class(['px-6 py-3 whitespace-nowrap tabular-nums', 'line-through text-gray-500 dark:text-gray-400' => $salida->anulada()])>{{ $caja->moneda->formatear(-1 * $salida->monto) }}@if ($salida->anulada()) <span class="no-underline font-semibold text-red-700 dark:text-red-300">(anulada)</span>@endif</td>
                            <td class="px-6 py-3 text-right">
                                @if ($puedeAnularSalidas && ! $salida->anulada() && ! $salida->solicitudPendiente && $salida->usuario_id === auth()->id() && \App\Support\Cobros\AnularSalida::cajaAbiertaDeHoy($caja))
                                    @php($directa = in_array($salida->id, $anulablesDirecto, true))
                                    @php($bolsa = $errors->getBag('salida'.$salida->id))
                                    <details class="text-left" @if ($bolsa->any()) open @endif>
                                        <summary class="cursor-pointer font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">{{ $directa ? 'Anular' : 'Solicitar la anulación' }}</summary>
                                        <form method="POST" action="{{ route($directa ? 'admin.salidas-caja.anular' : 'admin.salidas-caja.solicitar-anulacion', $salida) }}" novalidate class="mt-2 w-64 space-y-2">
                                            @csrf
                                            <label for="motivo_salida_{{ $salida->id }}" class="block text-xs text-gray-600 dark:text-gray-400">Motivo (obligatorio)</label>
                                            <textarea id="motivo_salida_{{ $salida->id }}" name="motivo" rows="2" required minlength="{{ \App\Support\Cobros\AnularSalida::MOTIVO_MINIMO }}" maxlength="{{ \App\Support\Cobros\AnularSalida::MOTIVO_MAXIMO }}"
                                                class="block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"></textarea>
                                            <x-input-error :messages="$bolsa->get('motivo')" />
                                            <x-secondary-button type="submit">{{ $directa ? 'Anular la salida' : 'Enviar la solicitud' }}</x-secondary-button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">Todavía no hay salidas en esta caja.</td></tr>
                    @endforelse
                    @if ($salidas->isNotEmpty())
                        <tr class="font-semibold">
                            <td class="px-6 py-3" colspan="5">Total de salidas vigentes</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear(-1 * $salidas->reject(fn ($salida) => $salida->anulada())->sum('monto')) }}</td>
                            <td></td>
                        </tr>
                    @endif
                </x-admin.table>

                @if ($puedeRegistrarSalida)
                    <div class="max-w-3xl space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                        <h4 class="font-semibold text-gray-900 dark:text-gray-100">Registrar una salida</h4>
                        <p class="text-gray-600 dark:text-gray-400">Sale en efectivo y resta de lo esperado en efectivo. Si no alcanza, no se registra. Quien la registra puede anularla dentro de los {{ $plazoAnulacionSalida }} minutos; después, solo con la aprobación de Gerencia. No se edita: para corregirla, anúlela y registre otra.</p>
                        @if ($categoriasSalida->isEmpty())
                            <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">No hay categorías de gasto activas: pida a Compras y tesorería o a Gerencia que carguen una.</p>
                        @else
                            <form method="POST" action="{{ route('admin.cajas.salidas.store', $caja) }}" novalidate class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @csrf
                                <x-admin.select name="categoria_gasto_id" label="Categoría de gasto" :options="$categoriasSalida->pluck('nombre', 'id')->all()" required />
                                <x-admin.input name="monto" label="Monto en efectivo (₲)" inputmode="numeric" maxlength="18" autocomplete="off" required />
                                <x-admin.input name="beneficiario" label="Beneficiario" maxlength="{{ \App\Support\Cobros\RegistrarSalida::BENEFICIARIO_MAXIMO }}" required />
                                <x-admin.input name="comprobante" label="Comprobante o recibo (opcional)" maxlength="{{ \App\Support\Cobros\RegistrarSalida::COMPROBANTE_MAXIMO }}" />
                                <div class="sm:col-span-2">
                                    <x-admin.textarea name="concepto" label="Concepto (obligatorio)" rows="2" minlength="{{ \App\Support\Cobros\RegistrarSalida::CONCEPTO_MINIMO }}" maxlength="{{ \App\Support\Cobros\RegistrarSalida::CONCEPTO_MAXIMO }}" required />
                                </div>
                                <div class="sm:col-span-2"><x-primary-button>Registrar la salida</x-primary-button></div>
                            </form>
                        @endif
                    </div>
                @endif
            </section>
        @endif

        @if ($puedeCerrar)
            <section aria-labelledby="cerrar-caja" class="max-w-3xl space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                <h3 id="cerrar-caja" class="font-semibold text-gray-900 dark:text-gray-100">{{ $caja->devuelta() ? 'Corregir y reenviar el arqueo' : 'Cerrar la caja' }}</h3>
                @if ($caja->devuelta() && $caja->cierres->first()?->nota_revision)
                    <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">Observación de Gerencia: {{ $caja->cierres->first()->nota_revision }}</p>
                @endif
                <p class="text-gray-600 dark:text-gray-400">Cada medio trae lo esperado: cámbielo si lo que contó es distinto (fuera del efectivo puede ser negativo si se devolvió más de lo que entró). Si hay diferencia, la observación es obligatoria; si no, queda vacía. Cerrada, la caja ya no recibe cobros y queda pendiente de recepción.</p>
                {{-- Precargado con lo esperado; resources/js/arqueo-caja.js muestra la diferencia en vivo y bloquea la observación sin diferencia (el servidor verifica todo). --}}
                <form method="POST" action="{{ route('admin.cajas.cerrar', $caja) }}" novalidate class="space-y-3" x-data="arqueoCaja">
                    @csrf
                    <x-admin.table :headers="['Medio de pago', 'Esperado', 'Declarado', 'Diferencia']">
                        @foreach ($mediosADeclarar as $medio)
                            @php($esperadoMedio = $esperado[$medio->id] ?? 0)
                            <tr data-fila-arqueo data-esperado="{{ $esperadoMedio }}">
                                <td class="px-6 py-3"><label for="contado_{{ $medio->id }}">{{ $medio->nombre }}</label></td>
                                <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear($esperadoMedio) }}</td>
                                <td class="px-6 py-3">
                                    {{-- Fuera del efectivo se admite el signo menos (el teclado numérico del teléfono no lo tiene). --}}
                                    <input id="contado_{{ $medio->id }}" name="contado[{{ $medio->id }}]" value="{{ old('contado.'.$medio->id, number_format($esperadoMedio, 0, '', '.')) }}" inputmode="{{ $medio->esEfectivo() ? 'numeric' : 'text' }}" maxlength="18" autocomplete="off" required
                                        data-declarado x-on:input="recalcular()"
                                        @if ($esperadoMedio < 0) aria-describedby="negativo_{{ $medio->id }}" @endif
                                        class="block w-40 rounded-md border-gray-300 shadow-sm tabular-nums dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    @if ($esperadoMedio < 0)
                                        <p id="negativo_{{ $medio->id }}" class="mt-1 text-xs text-gray-500 dark:text-gray-400">Se devolvió más de lo que entró: declare el neto con el signo menos (por ejemplo, -150.000).</p>
                                    @endif
                                    <x-input-error class="mt-1" :messages="$errors->get('contado.'.$medio->id)" />
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap tabular-nums" data-diferencia aria-live="polite">—</td>
                                <td></td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <td class="px-6 py-3">Total</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear(collect($mediosADeclarar)->sum(fn ($medio) => $esperado[$medio->id] ?? 0)) }}</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums" x-text="totalDeclarado">—</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums" x-text="totalDiferencia" aria-live="polite">—</td>
                            <td></td>
                        </tr>
                    </x-admin.table>
                    <div>
                        <x-input-label for="motivo" value="Observación (obligatoria solo si hay diferencia)" />
                        <textarea id="motivo" name="motivo" rows="2" maxlength="{{ CerrarCaja::MOTIVO_MAXIMO }}" data-observacion
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm disabled:bg-gray-100 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:disabled:bg-gray-800">{{ old('motivo') }}</textarea>
                        <p x-show="!conDiferencia" style="display: none" class="mt-1 text-xs text-gray-500 dark:text-gray-400">Sin diferencia: la observación queda vacía.</p>
                        <x-input-error class="mt-1" :messages="$errors->get('motivo')" />
                    </div>
                    <x-primary-button>{{ $caja->devuelta() ? 'Reenviar el arqueo' : 'Cerrar la caja' }}</x-primary-button>
                </form>
            </section>
        @endif

        @if ($puedeRevisar)
            <section aria-labelledby="revisar-cierre" class="max-w-3xl space-y-4 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                <h3 id="revisar-cierre" class="font-semibold text-gray-900 dark:text-gray-100">Recibir el arqueo</h3>
                <form method="POST" action="{{ route('admin.cajas.aprobar', $caja) }}" class="space-y-2">
                    @csrf
                    <x-admin.textarea name="nota" label="Nota (opcional)" rows="2" maxlength="{{ RevisarCierre::NOTA_MAXIMA }}" />
                    <x-primary-button>Aceptar (recibido)</x-primary-button>
                </form>
                <details class="rounded-md border border-amber-200 p-3 dark:border-amber-900" @if ($errors->has('nota_devolucion')) open @endif>
                    <summary class="cursor-pointer font-medium text-amber-700 dark:text-amber-300">Rechazar (devolver por diferencias)</summary>
                    <form method="POST" action="{{ route('admin.cajas.devolver', $caja) }}" novalidate class="mt-3 space-y-2">
                        @csrf
                        <x-admin.textarea name="nota_devolucion" label="Observación con el inconveniente (obligatoria)" rows="2" minlength="{{ RevisarCierre::NOTA_MINIMA }}" maxlength="{{ RevisarCierre::NOTA_MAXIMA }}" required />
                        <x-secondary-button type="submit">Rechazar</x-secondary-button>
                    </form>
                </details>
            </section>
        @endif

        @if ($caja->cierres->isNotEmpty())
            <section aria-labelledby="cierres" class="max-w-4xl space-y-4">
                <h3 id="cierres" class="font-semibold text-gray-900 dark:text-gray-100">Historial del arqueo</h3>
                @foreach ($caja->cierres as $cierre)
                    <div class="space-y-2 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-gray-700 dark:text-gray-300">
                            <span class="font-medium">Intento {{ $caja->cierres->count() - $loop->index }}: {{ $cierre->etiquetaEstado() }}</span>
                            · enviado el {{ Fecha::mostrar($cierre->cerrado_en, conHora: true) }}{{ $cierre->cajero ? ' por '.($cierre->cajero->persona?->nombre_completo ?? $cierre->cajero->email) : '' }}
                            @if ($cierre->revisado_en)
                                · {{ $cierre->tieneEstado(\App\Models\Estado::DEVUELTO) ? 'devuelto' : 'recibido' }} el {{ Fecha::mostrar($cierre->revisado_en, conHora: true) }}{{ $cierre->revisadoPor ? ' por '.($cierre->revisadoPor->persona?->nombre_completo ?? $cierre->revisadoPor->email) : '' }}
                            @endif
                        </p>
                        <x-admin.table :headers="['Medio de pago', 'Esperado', 'Declarado', 'Diferencia']">
                            @foreach ($cierre->medios as $fila)
                                <tr>
                                    <td class="px-6 py-3">{{ $fila->medioPago->nombre }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear($fila->esperado) }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear($fila->contado) }}</td>
                                    <td @class(['px-6 py-3 whitespace-nowrap tabular-nums', 'font-semibold text-red-700 dark:text-red-300' => (float) $fila->diferencia != 0])>{{ $caja->moneda->formatear($fila->diferencia) }}</td>
                                    <td></td>
                                </tr>
                            @endforeach
                        </x-admin.table>
                        @if ($cierre->motivo_diferencia)
                            <p class="text-gray-700 dark:text-gray-300"><span class="text-gray-500 dark:text-gray-400">Observación del cajero:</span> {{ $cierre->motivo_diferencia }}</p>
                        @endif
                        @if ($cierre->nota_revision)
                            <p class="text-gray-700 dark:text-gray-300"><span class="text-gray-500 dark:text-gray-400">{{ $cierre->tieneEstado(\App\Models\Estado::DEVUELTO) ? 'Motivo de la devolución' : 'Nota de Gerencia' }}:</span> {{ $cierre->nota_revision }}</p>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif

        <a href="{{ route('admin.cajas.index') }}" class="inline-block text-gray-600 hover:underline dark:text-gray-400">Volver a Cajas</a>
    </div>
</x-admin.page>
