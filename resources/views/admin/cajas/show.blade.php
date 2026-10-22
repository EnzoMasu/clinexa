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
        </dl>

        <section aria-labelledby="esperado" class="max-w-3xl space-y-3">
            <h3 id="esperado" class="font-semibold text-gray-900 dark:text-gray-100">Lo esperado por medio de pago</h3>
            <p class="text-gray-600 dark:text-gray-400">El monto inicial (solo en efectivo) más los cobros, menos lo devuelto por cobros revertidos.</p>
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
            <x-admin.table :headers="['Hora', 'Paciente', 'Medios', 'Total']">
                @forelse ($cobros as $cobro)
                    <tr>
                        <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($cobro->cobrado_en, conHora: true) }}</td>
                        <td class="px-6 py-3">{{ $cobro->paciente->persona->nombre_completo }}</td>
                        <td class="px-6 py-3">{{ $cobro->lineas->map(fn ($linea) => $linea->medioPago->nombre.' '.$cobro->moneda->formatear($linea->monto))->join(' · ') }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $cobro->totalTexto() }}@if ($cobro->revertido()) <span class="font-semibold text-red-700 dark:text-red-300">(revertido)</span>@endif</td>
                        <td class="px-6 py-3 text-right">
                            @if ($veCobros)
                                <a href="{{ route('admin.cobros.show', $cobro) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">Todavía no hay cobros en esta caja.</td></tr>
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

        @if ($puedeCerrar)
            <section aria-labelledby="cerrar-caja" class="max-w-3xl space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                <h3 id="cerrar-caja" class="font-semibold text-gray-900 dark:text-gray-100">{{ $caja->devuelta() ? 'Corregir y volver a cerrar' : 'Cerrar la caja' }}</h3>
                @if ($caja->devuelta() && $caja->cierres->first()?->nota_revision)
                    <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">Nota de Gerencia: {{ $caja->cierres->first()->nota_revision }}</p>
                @endif
                <p class="text-gray-600 dark:text-gray-400">Declare lo que contó en cada medio (cero si no hay nada; fuera del efectivo puede ser negativo si se devolvió más de lo que entró). Si no coincide con lo esperado, indique el motivo. Cerrada, la caja ya no recibe cobros.</p>
                <form method="POST" action="{{ route('admin.cajas.cerrar', $caja) }}" novalidate class="space-y-3">
                    @csrf
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($mediosADeclarar as $medio)
                            <div>
                                <x-input-label for="contado_{{ $medio->id }}" :value="$medio->nombre.' (esperado: '.$caja->moneda->formatear($esperado[$medio->id] ?? 0).')'" />
                                {{-- Fuera del efectivo se admite el signo menos (el teclado numérico del teléfono no lo tiene). --}}
                                <input id="contado_{{ $medio->id }}" name="contado[{{ $medio->id }}]" value="{{ old('contado.'.$medio->id) }}" inputmode="{{ $medio->esEfectivo() ? 'numeric' : 'text' }}" maxlength="18" autocomplete="off" required
                                    @if (($esperado[$medio->id] ?? 0) < 0) aria-describedby="negativo_{{ $medio->id }}" @endif
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                @if (($esperado[$medio->id] ?? 0) < 0)
                                    <p id="negativo_{{ $medio->id }}" class="mt-1 text-xs text-gray-500 dark:text-gray-400">Se devolvió más de lo que entró por este medio: declare el neto con el signo menos (por ejemplo, -150.000).</p>
                                @endif
                                <x-input-error class="mt-1" :messages="$errors->get('contado.'.$medio->id)" />
                            </div>
                        @endforeach
                    </div>
                    <x-admin.textarea name="motivo" label="Motivo de la diferencia (obligatorio si hay diferencia)" rows="2" maxlength="{{ CerrarCaja::MOTIVO_MAXIMO }}" />
                    <x-primary-button>Cerrar la caja</x-primary-button>
                </form>
            </section>
        @endif

        @if ($puedeRevisar)
            <section aria-labelledby="revisar-cierre" class="max-w-3xl space-y-4 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                <h3 id="revisar-cierre" class="font-semibold text-gray-900 dark:text-gray-100">Revisar el cierre</h3>
                <form method="POST" action="{{ route('admin.cajas.aprobar', $caja) }}" class="space-y-2">
                    @csrf
                    <x-admin.textarea name="nota" label="Nota (opcional)" rows="2" maxlength="{{ RevisarCierre::NOTA_MAXIMA }}" />
                    <x-primary-button>Aprobar el cierre</x-primary-button>
                </form>
                <details class="rounded-md border border-amber-200 p-3 dark:border-amber-900" @if ($errors->has('nota_devolucion')) open @endif>
                    <summary class="cursor-pointer font-medium text-amber-700 dark:text-amber-300">Devolver al cajero para corregir</summary>
                    <form method="POST" action="{{ route('admin.cajas.devolver', $caja) }}" novalidate class="mt-3 space-y-2">
                        @csrf
                        <x-admin.textarea name="nota_devolucion" label="Qué hay que corregir (obligatorio)" rows="2" minlength="{{ RevisarCierre::NOTA_MINIMA }}" maxlength="{{ RevisarCierre::NOTA_MAXIMA }}" required />
                        <x-secondary-button type="submit">Devolver</x-secondary-button>
                    </form>
                </details>
            </section>
        @endif

        @if ($caja->cierres->isNotEmpty())
            <section aria-labelledby="cierres" class="max-w-4xl space-y-4">
                <h3 id="cierres" class="font-semibold text-gray-900 dark:text-gray-100">Cierres</h3>
                @foreach ($caja->cierres as $cierre)
                    <div class="space-y-2 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-gray-700 dark:text-gray-300">
                            <span class="font-medium">{{ $cierre->etiquetaEstado() }}</span> · cerrado el {{ Fecha::mostrar($cierre->cerrado_en, conHora: true) }}
                            @if ($cierre->revisado_en)
                                · revisado el {{ Fecha::mostrar($cierre->revisado_en, conHora: true) }}{{ $cierre->revisadoPor ? ' por '.$cierre->revisadoPor->persona?->nombre_completo : '' }}
                            @endif
                        </p>
                        <x-admin.table :headers="['Medio de pago', 'Esperado', 'Contado', 'Diferencia']">
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
                            <p class="text-gray-700 dark:text-gray-300"><span class="text-gray-500 dark:text-gray-400">Motivo de la diferencia:</span> {{ $cierre->motivo_diferencia }}</p>
                        @endif
                        @if ($cierre->nota_revision)
                            <p class="text-gray-700 dark:text-gray-300"><span class="text-gray-500 dark:text-gray-400">Nota de la revisión:</span> {{ $cierre->nota_revision }}</p>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif

        <a href="{{ route('admin.cajas.index') }}" class="inline-block text-gray-600 hover:underline dark:text-gray-400">Volver a Cajas</a>
    </div>
</x-admin.page>
