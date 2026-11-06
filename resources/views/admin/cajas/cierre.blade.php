@use('App\Support\Fecha')
@use('App\Support\Cobros\CerrarCaja')

{{-- Pantalla de cierre de la caja (CajaController::cierre, docs/cobros-caja.md): lo declarado por medio; después, la confirmación final. --}}
<x-admin.page title="{{ $caja->devuelta() ? 'Reenviar el arqueo' : 'Cerrar la caja' }} del {{ Fecha::mostrar($caja->fecha) }}">
    <div class="space-y-4 p-6 text-sm">
        @if ($caja->devuelta() && $caja->cierres->first()?->nota_revision)
            <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">Observación de Gerencia: {{ $caja->cierres->first()->nota_revision }}</p>
        @endif
        <p class="max-w-3xl text-gray-600 dark:text-gray-400">Cada medio trae lo esperado: cámbielo si lo que contó es distinto (fuera del efectivo puede ser negativo si se devolvió más de lo que entró). Si hay diferencia, la observación es obligatoria; si no, queda vacía. Antes de cerrar se le pedirá una confirmación.</p>
            {{-- Precargado con lo esperado; resources/js/arqueo-caja.js muestra la diferencia en vivo y bloquea la observación sin diferencia (el servidor verifica todo). --}}
          <form method="POST" action="{{ route('admin.cajas.cierre.revisar', $caja) }}" novalidate class="space-y-3" x-data="arqueoCaja">
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
              <div class="flex items-center gap-3">
                  <x-primary-button>Continuar</x-primary-button>
                  <a href="{{ route('admin.cajas.show', $caja) }}" class="text-sm font-medium text-gray-600 hover:underline dark:text-gray-400">Cancelar</a>
              </div>
          </form>
    </div>
</x-admin.page>
