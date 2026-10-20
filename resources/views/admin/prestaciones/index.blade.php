{{-- Prestaciones (PrestacionController::index, docs/tarifas-prestaciones.md). Sin contenido clínico. --}}
<x-admin.page title="Prestaciones">
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Cada consulta finalizada genera su prestación. Si ese día no había tarifa queda "Pendiente de tarifar": al tarifarla se usa la
        tarifa de su fecha de servicio, y el monto queda fijado aunque la tarifa cambie después.
    </p>
    @if ($cantidadSinPrestacion > 0)
        <div role="status" class="mx-4 mt-4 rounded-md bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            {{ $cantidadSinPrestacion === 1 ? 'Hay 1 consulta finalizada sin prestación.' : "Hay {$cantidadSinPrestacion} consultas finalizadas sin prestación." }}
            @unless ($puedeGenerar)
                Avise al Administrador o a Gerencia para que la generen.
            @endunless
        </div>
    @endif

    @if ($puedeGenerar && $consultasSinPrestacion->isNotEmpty())
        <section aria-labelledby="sin-prestacion" class="mx-4 mt-4 rounded-md border border-amber-200 dark:border-amber-900">
            <h3 id="sin-prestacion" class="px-4 pt-3 font-semibold text-gray-900 dark:text-gray-100">Consultas sin prestación</h3>
            <p class="px-4 pb-2 text-xs text-gray-600 dark:text-gray-400">
                Finalizadas desde que existen las prestaciones. "Generar" usa la tarifa vigente el día del servicio; si no hay, queda pendiente de tarifar.
                @if ($cantidadSinPrestacion > $consultasSinPrestacion->count())
                    Se muestran las {{ $consultasSinPrestacion->count() }} más antiguas.
                @endif
            </p>
            <x-admin.table :headers="['Consulta', 'Fecha', 'Paciente', 'Profesional']">
                @foreach ($consultasSinPrestacion as $consulta)
                    <tr>
                        <td class="px-6 py-3">N.º {{ $consulta->id }}</td>
                        <td class="px-6 py-3 whitespace-nowrap">{{ $consulta->fechaHoraTexto() }}</td>
                        <td class="px-6 py-3">{{ $consulta->historiaClinica->paciente->persona->nombre_completo }}</td>
                        <td class="px-6 py-3">{{ $consulta->profesional->persona->nombre_completo }}</td>
                        <td class="px-6 py-3 text-right">
                            <form method="POST" action="{{ route('admin.prestaciones.generar', $consulta) }}" class="inline">
                                @csrf
                                <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Generar</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        </section>
    @endif

    <x-admin.listado :action="route('admin.prestaciones.index')" :busqueda="$busqueda" placeholder="Buscar por paciente, documento o ficha…">
        <x-slot name="filtros">
            <div class="w-40"><x-admin.fecha name="desde" label="Desde" :value="$filtros['desde']" /></div>
            <div class="w-40"><x-admin.fecha name="hasta" label="Hasta" :value="$filtros['hasta']" /></div>
            <div class="w-56"><x-admin.select name="profesional" label="Profesional" :options="$opcionesProfesional" :value="$filtros['profesional']" nullable /></div>
            <div class="w-48"><x-admin.select name="estado" label="Estado" :options="$opcionesEstado" :value="$filtros['estado']" nullable /></div>
        </x-slot>

        @include('admin.prestaciones._tabla')
    </x-admin.listado>
</x-admin.page>
