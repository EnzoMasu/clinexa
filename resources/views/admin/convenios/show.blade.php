@use('App\Support\Fecha')
@use('App\Support\Permisos')

{{-- Detalle de un convenio (ConvenioController::show): sus planes (agregar, activar o desactivar), sus tarifas vigentes y el paso a
     histórico. Nada se borra. --}}
<x-admin.page :title="'Convenio: '.$convenio->nombre">
    <div class="space-y-8 p-6 text-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                <div><dt class="text-gray-500 dark:text-gray-400">Aseguradora</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $convenio->aseguradoraTexto() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Vigencia</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $convenio->vigenciaTexto() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Estado</dt><dd><x-admin.estado-badge :estado="$convenio->estado" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Cargado por</dt><dd>{{ $convenio->usuario?->persona?->nombre_completo }} · {{ Fecha::mostrar($convenio->created_at, conHora: true) }}</dd></div>
            </dl>
            <a href="{{ route('admin.convenios.index') }}" class="text-gray-600 hover:underline dark:text-gray-400">Volver a Convenios</a>
        </div>

        <section class="space-y-3">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">Planes</h3>
            <p class="text-gray-600 dark:text-gray-400">Solo el nombre: sin reglas de cobertura ni copago. Un plan inactivo no se ofrece al cargar una cobertura.</p>
            @php($puedeEstado = Permisos::puedeRuta('admin.convenios.planes.estado'))
            <x-admin.table :headers="['Plan', 'Estado']">
                @forelse ($convenio->planes as $plan)
                    <tr>
                        <td class="px-6 py-3 font-medium">{{ $plan->nombre }}</td>
                        <td class="px-6 py-3"><x-admin.estado-badge :estado="$plan->estado" /></td>
                        <td class="px-6 py-3 text-right">
                            @if ($puedeEstado)
                                <form method="POST" action="{{ route('admin.convenios.planes.estado', $plan) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="accion" value="{{ $plan->estaActivo() ? 'desactivar' : 'activar' }}">
                                    <button type="submit" @class(['font-medium', 'text-red-600 hover:text-red-900 dark:text-red-400' => $plan->estaActivo(), 'text-indigo-600 hover:text-indigo-900 dark:text-indigo-400' => ! $plan->estaActivo()])>
                                        {{ $plan->estaActivo() ? 'Desactivar' : 'Activar' }}
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-6 py-4 text-gray-500 dark:text-gray-400">Todavía no tiene planes.</td></tr>
                @endforelse
            </x-admin.table>

            @if ($convenio->vigente() && Permisos::puedeRuta('admin.convenios.planes.store'))
                <form method="POST" action="{{ route('admin.convenios.planes.store', $convenio) }}" novalidate class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="w-72"><x-admin.input name="nombre" label="Nuevo plan" maxlength="100" required /></div>
                    <x-primary-button>Agregar plan</x-primary-button>
                </form>
            @endif
        </section>

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">Tarifas vigentes del convenio</h3>
                @if ($convenio->vigente() && ($nueva = Permisos::url('admin.convenios.tarifas.create', ['convenio' => $convenio->id])))
                    <a href="{{ $nueva }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Cargar o cambiar una tarifa</a>
                @endif
            </div>
            <p class="text-gray-600 dark:text-gray-400">Un procedimiento que no figura acá usa la tarifa general (con la del profesional, si tiene).</p>
            <x-admin.table :headers="['Procedimiento', 'Monto', 'Vigencia']">
                @forelse ($tarifas as $tarifa)
                    <tr>
                        <td class="px-6 py-3 font-medium">{{ $tarifa->procedimiento->nombre }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $tarifa->montoTexto() }}</td>
                        <td class="px-6 py-3">{{ $tarifa->vigenciaTexto() }}</td>
                        <td class="px-6 py-3 text-right"><a href="{{ route('admin.convenios.tarifas.show', $tarifa) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Historial</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-4 text-gray-500 dark:text-gray-400">Sin tarifas propias: todos los procedimientos usan la tarifa general.</td></tr>
                @endforelse
            </x-admin.table>
        </section>

        @if ($convenio->vigente() && Permisos::puedeRuta('admin.convenios.historico'))
            <section class="space-y-3 border-t border-gray-200 pt-6 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">Pasar a histórico</h3>
                <p class="text-gray-600 dark:text-gray-400">El convenio deja de aplicarse después de su último día. No se borra nada; las prestaciones ya creadas no cambian.</p>
                <form method="POST" action="{{ route('admin.convenios.historico', $convenio) }}" novalidate class="flex flex-wrap items-end gap-3"
                    x-data x-on:submit="if (! confirm('¿Pasar el convenio a histórico?')) $event.preventDefault()">
                    @csrf
                    <div class="w-48"><x-admin.fecha name="ultimo_dia" label="Último día" :value="Fecha::hoy()" /></div>
                    <x-danger-button>Pasar a histórico</x-danger-button>
                </form>
            </section>
        @endif
    </div>
</x-admin.page>
