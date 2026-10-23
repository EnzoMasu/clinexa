{{-- Prestaciones (PrestacionController::index, docs/tarifas-prestaciones.md). Sin contenido clínico. --}}
<x-admin.page title="Prestaciones">
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Cada consulta finalizada genera su prestación, y otra por cada informe de estudio. Si ese día no había tarifa queda "Pendiente de tarifar": al tarifarla se usa la
        tarifa de su fecha de servicio, y el monto queda fijado aunque la tarifa cambie después.
    </p>
    @if ($cantidadSinPrestacion + $cantidadInformesSinPrestacion > 0)
        <div role="status" class="mx-4 mt-4 rounded-md bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            @if ($cantidadSinPrestacion > 0)
                {{ $cantidadSinPrestacion === 1 ? 'Hay 1 consulta finalizada sin prestación.' : "Hay {$cantidadSinPrestacion} consultas finalizadas sin prestación." }}
            @endif
            @if ($cantidadInformesSinPrestacion > 0)
                {{ $cantidadInformesSinPrestacion === 1 ? 'Hay 1 informe de estudio sin prestación.' : "Hay {$cantidadInformesSinPrestacion} informes de estudio sin prestación." }}
            @endif
            @unless ($puedeGenerar)
                Avise al Administrador o a Gerencia para que la generen.
            @endunless
        </div>
    @endif

    {{-- Avisos de cobertura (docs/convenios-seguros.md): particulares de pacientes con cobertura y visaciones anuladas ya aplicadas. --}}
    @if ($cantidadConAviso > 0)
        <div role="status" class="mx-4 mt-4 rounded-md bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            {{ $cantidadConAviso === 1 ? 'Hay 1 prestación con aviso de cobertura.' : "Hay {$cantidadConAviso} prestaciones con aviso de cobertura." }}
            <a href="{{ route('admin.prestaciones.index', ['cobertura' => 'AVISO']) }}" class="font-medium underline">Ver las prestaciones con aviso</a>
        </div>
    @endif

    @if ($cantidadPendientesDeReparto > 0)
        <div role="status" class="mx-4 mt-4 rounded-md bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            {{ $cantidadPendientesDeReparto === 1 ? 'Hay 1 prestación pendiente de reparto.' : "Hay {$cantidadPendientesDeReparto} prestaciones pendientes de reparto." }}
            <a href="{{ route('admin.prestaciones.index', ['reparto' => 'PENDIENTE']) }}" class="font-medium underline">Ver las pendientes</a>
            @unless ($puedeRepartir)
                Avise al Administrador o a Gerencia para que carguen los acuerdos y las repartan.
            @endunless
        </div>
    @endif

    @if ($puedeGenerar && $pendientesDeGenerar->isNotEmpty())
        <section aria-labelledby="sin-prestacion" class="mx-4 mt-4 rounded-md border border-amber-200 dark:border-amber-900">
            <h3 id="sin-prestacion" class="px-4 pt-3 font-semibold text-gray-900 dark:text-gray-100">Consultas sin prestación</h3>
            <p class="px-4 pb-2 text-xs text-gray-600 dark:text-gray-400">
                Finalizadas desde que existen las prestaciones (y, en los informes de estudio, desde que generan la suya). "Generar" usa la
                tarifa vigente el día del servicio; si no hay, queda pendiente de tarifar.
                @if ($cantidadSinPrestacion + $cantidadInformesSinPrestacion > $pendientesDeGenerar->count())
                    Se muestran las {{ $pendientesDeGenerar->count() }} más antiguas.
                @endif
            </p>
            <x-admin.table :headers="['Consulta', 'Fecha', 'Paciente', 'Profesional', 'Origen']">
                @foreach ($pendientesDeGenerar as ['consulta' => $consulta, 'origen' => $origen, 'procedimiento' => $procedimiento, 'ruta' => $ruta])
                    <tr>
                        <td class="px-6 py-3">N.º {{ $consulta->id }}</td>
                        <td class="px-6 py-3 whitespace-nowrap">{{ $consulta->fechaHoraTexto() }}</td>
                        <td class="px-6 py-3">{{ $consulta->historiaClinica->paciente->persona->nombre_completo }}</td>
                        <td class="px-6 py-3">{{ $consulta->profesional->persona->nombre_completo }}</td>
                        <td class="px-6 py-3">
                            {{ $origen }}
                            @if ($procedimiento)
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $procedimiento }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-right">
                            <form method="POST" action="{{ $ruta }}" class="inline">
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
            <div class="w-48"><x-admin.select name="origen" label="Origen" :options="$opcionesOrigen" :value="$filtros['origen']" nullable /></div>
            @if ($veAvisos)
                <div class="w-48"><x-admin.select name="cobertura" label="Cobertura" :options="['AVISO' => 'Con aviso de cobertura']" :value="$filtros['cobertura']" nullable /></div>
            @endif
            @if ($veRepartos)
                <div class="w-48"><x-admin.select name="reparto" label="Reparto" :options="['PENDIENTE' => 'Pendientes de reparto']" :value="$filtros['reparto']" nullable /></div>
            @endif
        </x-slot>

        @include('admin.prestaciones._tabla')
    </x-admin.listado>
</x-admin.page>
