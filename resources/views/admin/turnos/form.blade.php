<x-admin.page title="Nuevo turno">
    <x-admin.form :action="route('admin.turnos.store')" :cancel="route('admin.turnos.index')" width="max-w-3xl"
        x-data="altaTurno({ url: {{ Js::from(route('admin.turnos.horarios-disponibles')) }}, profesionalId: {{ Js::from($profesionalInicial['id'] ?? null) }}, fecha: {{ Js::from($fecha) }}, hora: {{ Js::from(old('hora_inicio', '')) }} })"
        x-on:elegido="alElegir($event)" x-on:fecha-cambiada="alCambiarFecha($event)">

        <x-admin.selector-persona name="paciente_id" label="Paciente" :url="route('admin.turnos.pacientes')"
            :inicial="$pacienteInicial" :ofrecer-alta="false" sin-resultados="No hay pacientes activos con esa búsqueda."
            ayuda="Solo aparecen pacientes activos. Busque por nombre, documento o número de ficha." />

        <x-admin.selector-persona name="profesional_id" label="Profesional" :url="route('admin.turnos.profesionales')"
            :inicial="$profesionalInicial" :ofrecer-alta="false" sin-resultados="No hay profesionales activos con esa búsqueda."
            ayuda="Solo aparecen profesionales activos. Busque por nombre, documento o matrícula." />

        <div class="max-w-xs">
            <x-admin.fecha name="fecha" label="Fecha" :value="$fecha" required />
        </div>

        {{-- Horarios libres del profesional en esa fecha (App\Support\Agenda); el consultorio sale del horario elegido. --}}
        <div class="space-y-2">
            <span class="block font-medium text-sm text-gray-700 dark:text-gray-300">Horario</span>
            <input type="hidden" name="hora_inicio" x-bind:value="hora" value="{{ old('hora_inicio') }}">

            <p x-show="! listoParaBuscar" class="text-sm text-gray-500 dark:text-gray-400">Elija el profesional y la fecha para ver los horarios libres.</p>
            <p x-show="cargando" style="display: none" class="text-sm text-gray-500 dark:text-gray-400" role="status">Buscando horarios…</p>
            <p x-show="listoParaBuscar && cargado && ! cargando && ! error && horarios.length === 0" style="display: none"
                class="text-sm text-amber-700 dark:text-amber-300">
                No hay horarios libres para ese profesional en esa fecha.
            </p>
            <p x-show="error && ! cargando" style="display: none" class="text-sm text-red-600 dark:text-red-400">
                No se pudieron cargar los horarios. Revise la fecha.
            </p>

            <div x-show="horarios.length > 0 && ! cargando" style="display: none" class="grid grid-cols-2 gap-2 sm:grid-cols-4"
                role="radiogroup" aria-label="Horarios libres">
                <template x-for="horario in horarios" x-bind:key="horario.hora_inicio">
                    <button type="button" x-on:click="elegir(horario)" role="radio" x-bind:aria-checked="(hora === horario.hora_inicio).toString()"
                        class="rounded-md border px-3 py-2 text-left text-sm"
                        x-bind:class="hora === horario.hora_inicio
                            ? 'border-indigo-600 bg-indigo-600 text-white'
                            : 'border-gray-300 text-gray-900 hover:border-indigo-400 hover:bg-indigo-50 dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-700'">
                        <span class="block font-mono font-medium" x-text="`${horario.hora_inicio} – ${horario.hora_fin}`"></span>
                        <span class="block text-xs opacity-80" x-text="horario.consultorio"></span>
                    </button>
                </template>
            </div>
            <x-input-error :messages="$errors->get('hora_inicio')" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <x-admin.select name="procedimiento_id" label="Procedimiento (opcional)" :options="$procedimientos" nullable />
            <x-admin.select name="origen_turno_id" label="Origen del turno (opcional)" :options="$origenes" nullable />
        </div>
        <x-admin.textarea name="observaciones" label="Observaciones (opcional)" maxlength="2000" rows="3" />
    </x-admin.form>
</x-admin.page>
