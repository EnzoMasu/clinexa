@use('App\Support\Permisos')

{{--
    Pantalla "Consulta" del profesional (AtencionController::index). Abrirla registra un VER sobre consultas;
    la lista (admin.atencion._tabla) se actualiza sola cada 30 s sin registrar lecturas. Los botones "Entre pacientes
    (N)" y "Urgencias (N)", el aviso de urgencia nueva y sus popups: resources/js/pendientes-profesional.js.
--}}
@php
    $boton = 'inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700';
@endphp

<x-admin.page title="Consulta">
    <div x-data="pendientesProfesional({ urls: {{ Js::from([
            'contadores' => route('admin.atencion.contadores'),
            'entrePacientes' => route('admin.atencion.entre-pacientes'),
            'urgencias' => route('admin.atencion.urgencias'),
            'pagina' => route('admin.atencion.index'),
        ]) }}, iniciales: {{ Js::from($contadores) }} })" x-on:keydown.window="teclado($event)">

    {{-- Aviso de urgencia nueva: solo el nombre del paciente (x-text: texto, nunca HTML). --}}
    <div x-show="aviso" x-cloak style="display: none" role="alert" data-aviso-urgencia
        class="fixed inset-x-0 top-0 z-40 flex flex-wrap items-center justify-center gap-3 bg-red-700 px-4 py-3 text-sm font-semibold text-white shadow-lg">
        <span>Nueva urgencia: <span x-text="aviso?.nombre"></span></span>
        <button type="button" x-on:click="abrir('urgencias', $event)" class="rounded-md bg-white px-3 py-1 text-xs font-semibold text-red-800 hover:bg-red-50">Ver urgencias</button>
        <button type="button" x-on:click="cerrarAviso()" class="rounded p-1 hover:bg-red-800" aria-label="Cerrar el aviso">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
        </button>
    </div>

    @if (session('confirmar_urgencia'))
        {{-- Atender una urgencia con otra consulta en curso: la pregunta del servidor, que también exige la confirmación. --}}
        @php($confirmar = session('confirmar_urgencia'))
        <div role="alert" data-confirmar-urgencia class="m-6 mb-0 space-y-3 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-700 dark:bg-red-900/30 dark:text-red-100">
            <p class="font-medium">{{ $confirmar['mensaje'] }}</p>
            <p>La consulta en curso queda abierta: la retoma con "Continuar".</p>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.atencion.atender', $confirmar['turno']) }}">
                    @csrf
                    <input type="hidden" name="confirmar_urgencia" value="1">
                    <button type="submit" class="inline-flex items-center rounded-md bg-red-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-800">Atender la urgencia igualmente</button>
                </form>
                <a href="{{ route('admin.atencion.index') }}" class="inline-flex items-center rounded-md border border-red-300 px-3 py-1.5 text-xs font-semibold hover:bg-red-100 dark:border-red-700 dark:hover:bg-red-900/50">No, volver</a>
            </div>
        </div>
    @endif

    <div x-data="{ sinTurno: false }" class="space-y-4 border-b border-gray-200 p-6 dark:border-gray-700">
        <div class="flex flex-wrap items-center gap-3">
            @if ($puedeAtender)
                <button type="button" x-on:click="sinTurno = ! sinTurno" x-bind:aria-expanded="sinTurno.toString()" aria-controls="panel-sin-turno" class="{{ $boton }}">
                    Atender sin turno
                </button>
            @endif
            <button type="button" data-boton="entre-pacientes" x-on:click="abrir('entre-pacientes', $event)" aria-haspopup="dialog" class="{{ $boton }}">
                Entre pacientes (<span x-text="entrePacientes">{{ $contadores['entre_pacientes'] }}</span>)
            </button>
            <button type="button" data-boton="urgencias" x-on:click="abrir('urgencias', $event)" aria-haspopup="dialog"
                class="{{ $boton }}" x-bind:class="urgencias > 0 && '!border-red-600 !bg-red-700 !text-white hover:!bg-red-800'">
                Urgencias (<span x-text="urgencias">{{ $contadores['urgencias'] }}</span>)
            </button>
            @if ($puedePasarAusente)
                <a href="{{ route('admin.atencion.salidas') }}" class="{{ $boton }}">Retiros y rechazos</a>
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

    @include('admin.atencion._popup-pendientes')
    </div>
</x-admin.page>
