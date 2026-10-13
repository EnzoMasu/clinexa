@use('App\Support\Permisos')

{{--
    Pantalla "Consulta" del profesional (AtencionController::index). Abrirla registra un VER sobre consultas;
    la lista (admin.atencion._tabla) se actualiza sola cada 30 s sin registrar lecturas.
--}}
@php
    $boton = 'inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700';
@endphp

<x-admin.page title="Consulta">
    <div x-data="{ sinTurno: false }" class="space-y-4 border-b border-gray-200 p-6 dark:border-gray-700">
        <div class="flex flex-wrap items-center gap-3">
            @if ($puedeAtender)
                <button type="button" x-on:click="sinTurno = ! sinTurno" x-bind:aria-expanded="sinTurno.toString()" aria-controls="panel-sin-turno" class="{{ $boton }}">
                    Atender sin turno
                </button>
            @endif
            @if ($puedePasarAusente)
                <a href="{{ route('admin.atencion.cerrar-jornada') }}" class="{{ $boton }}">Cerrar jornada</a>
            @endif
        </div>

        @if ($puedeAtender)
            {{--
                Atender sin turno: el buscador del alta de turnos (solo pacientes activos) con el id de
                la historia clínica; "Atender" envía un POST que crea la consulta EN_CURSO y abre la pantalla de atención.
            --}}
            <div id="panel-sin-turno" x-show="sinTurno" style="display: none" class="max-w-2xl space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700"
                x-data="{ historia: null, plantilla: {{ Js::from(route('admin.atencion.atender-sin-turno', ['historiaClinica' => '__ID__'])) }} }"
                x-on:elegido="historia = $event.detail.id">
                <x-admin.selector-persona name="paciente_atencion" label="Paciente" :url="route('admin.atencion.pacientes')"
                    :ofrecer-alta="false" sin-resultados="No hay pacientes activos con esa búsqueda."
                    ayuda="Solo aparecen pacientes activos. Busque por nombre, documento o número de ficha." />

                <form method="POST" x-show="historia" style="display: none" x-bind:action="historia ? plantilla.replace('__ID__', historia) : '#'">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white">Atender</button>
                </form>

                @include('admin.atencion._ayuda-paciente')
            </div>
        @endif
    </div>

    <div x-data="refrescoPeriodico({ url: {{ Js::from(route('admin.atencion.index')) }} })">
        @include('admin.atencion._tabla')
    </div>
</x-admin.page>
