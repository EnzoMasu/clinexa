@use('App\Support\Fecha')

{{--
    Vista previa de "Cerrar jornada": los turnos de hoy y de días anteriores todavía sin atender
    (pendientes, confirmados o por llamar de nuevo) cuya hora ya llegó pasan a AUSENTE; sus preparaciones
    se anulan. Los de hoy que todavía no llegaron a su hora se muestran aparte y quedan como están.
--}}
<x-admin.page title="Cerrar jornada">
    <div class="space-y-4 p-6">
        @if ($conConsultasEnCurso)
            <p role="alert" class="rounded-md bg-red-50 p-4 text-sm text-red-800 dark:bg-red-900/40 dark:text-red-200">
                {{ \App\Support\Atencion\CerrarJornada::CON_CONSULTAS_EN_CURSO }}
            </p>
        @endif

        @if ($turnos->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">No hay turnos para pasar a ausente{{ $aunNoEsSuHora->isEmpty() ? ': no queda nada pendiente de hoy ni de días anteriores' : '' }}.</p>
        @else
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {{ $turnos->count() === 1 ? 'Este turno pasará' : "Estos {$turnos->count()} turnos pasarán" }} a ausente. Si alguno tenía la preparación
                empezada, esa preparación se anula. No se puede deshacer.
            </p>
            <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
                @foreach ($turnos as $turno)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5">
                        <span class="w-36 dato text-xs">{{ Fecha::mostrar($turno->fecha) }} {{ substr($turno->hora_inicio, 0, 5) }}</span>
                        <span class="flex-1">@include('admin.atencion._paciente', ['paciente' => $turno->paciente])</span>
                        <x-admin.estado-badge :estado="$turno->estado" />
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($aunNoEsSuHora->isNotEmpty())
            <section class="space-y-2" aria-labelledby="titulo-aun-no">
                <h3 id="titulo-aun-no" class="text-sm font-semibold text-gray-900 dark:text-gray-100">Todavía no llegaron a su hora (quedan pendientes)</h3>
                <p role="status" class="rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {{ \App\Support\Atencion\CerrarJornada::AUN_NO_ES_SU_HORA }}
                </p>
                <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
                    @foreach ($aunNoEsSuHora as $turno)
                        <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5">
                            <span class="w-36 dato text-xs">{{ Fecha::mostrar($turno->fecha) }} {{ substr($turno->hora_inicio, 0, 5) }}</span>
                            <span class="flex-1">@include('admin.atencion._paciente', ['paciente' => $turno->paciente])</span>
                            <x-admin.estado-badge :estado="$turno->estado" />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="flex items-center gap-4">
            @if ($turnos->isNotEmpty() && ! $conConsultasEnCurso)
                <form method="POST" action="{{ route('admin.atencion.cerrar-jornada.confirmar') }}">
                    @csrf
                    <x-primary-button>Cerrar jornada</x-primary-button>
                </form>
            @endif
            <a href="{{ route('admin.atencion.index') }}" class="text-sm text-gray-600 hover:underline dark:text-gray-400">Volver</a>
        </div>
    </div>
</x-admin.page>
