@use('App\Support\Fecha')

{{--
    Atenciones sin turno del día: solo lo de la fila (nada de anamnesis, examen físico ni diagnósticos).
    Cada fila es un enlace real a la página de la consulta. El motivo se corta en el servidor.
--}}
<p class="px-4 pt-3 text-xs text-gray-500 dark:text-gray-400">
    {{ $esHoy ? 'Hoy, ' : '' }}{{ $fecha }} · {{ $consultas->total() }} {{ $consultas->total() === 1 ? 'atención' : 'atenciones' }}
</p>

@if ($consultas->isEmpty())
    <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
        {{ $busqueda !== '' ? 'No hay atenciones sin turno ese día con esa búsqueda.' : 'No hay atenciones sin turno ese día.' }}
    </p>
@else
    <ul class="mx-4 my-3 divide-y divide-gray-200 rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
        @foreach ($consultas as $consulta)
            @php($paciente = $consulta->historiaClinica->paciente)
            <li>
                <a href="{{ route('admin.consultas.show', $consulta) }}"
                    class="grid gap-x-4 gap-y-1 px-4 py-2.5 text-gray-900 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:text-gray-100 dark:hover:bg-gray-700/50 dark:focus:bg-gray-700/50 sm:grid-cols-[4rem_14rem_12rem_1fr] sm:items-center">
                    <span class="font-mono text-xs whitespace-nowrap">{{ substr(Fecha::mostrar($consulta->fecha_hora, conHora: true), -5) }}</span>
                    <span class="truncate">
                        <span class="font-medium">{{ $paciente->persona->nombre_completo }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Ficha {{ $paciente->nro_ficha }}</span>
                    </span>
                    <span class="truncate">{{ $consulta->profesional->persona->nombre_completo }}</span>
                    <span class="truncate text-gray-600 dark:text-gray-400">{{ Str::limit($consulta->motivo_consulta, 90) }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif

@if ($consultas->hasPages())
    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
        {{ $consultas->links() }}
    </div>
@endif
