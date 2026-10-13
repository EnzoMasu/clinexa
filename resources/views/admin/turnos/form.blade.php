<x-admin.page title="Nuevo turno">
    <x-admin.form :action="route('admin.turnos.store')" :cancel="route('admin.turnos.index')" width="max-w-3xl"
        x-data="altaTurno({ url: {{ Js::from(route('admin.turnos.horarios-disponibles')) }}, urlCupo: {{ Js::from(route('admin.turnos.cupo-del-dia')) }}, modalidad: {{ Js::from($modalidad) }}, profesionalId: {{ Js::from($profesionalInicial['id'] ?? null) }}, fecha: {{ Js::from($fecha) }}, hora: {{ Js::from($hora) }} })"
        x-on:elegido="alElegir($event)" x-on:fecha-cambiada="alCambiarFecha($event)">

        {{-- Modalidad: con horario (de la agenda) o entre pacientes (sin horario, dentro del cupo del profesional ese día). --}}
        <fieldset class="space-y-2">
            <legend class="block font-medium text-sm text-gray-700 dark:text-gray-300">Modalidad</legend>
            <div class="flex flex-wrap gap-4 text-sm">
                @foreach (['con_horario' => 'Con horario', 'entre_pacientes' => 'Entre pacientes'] as $valor => $texto)
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="modalidad" value="{{ $valor }}" x-model="modalidad" @checked($modalidad === $valor)
                            class="border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900">
                        {{ $texto }}
                    </label>
                @endforeach
            </div>
        </fieldset>

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
        <div class="space-y-2" x-show="modalidad === 'con_horario'" @if ($modalidad === 'entre_pacientes') style="display: none" @endif>
            <span class="block font-medium text-sm text-gray-700 dark:text-gray-300">Horario</span>
            <input type="hidden" name="hora_inicio" x-bind:value="hora" value="{{ $hora }}">

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
                        <span class="block font-mono font-medium text-current" x-text="`${horario.hora_inicio} – ${horario.hora_fin}`"></span>
                        <span class="block text-xs opacity-80" x-text="horario.consultorio"></span>
                    </button>
                </template>
            </div>
            <x-input-error :messages="$errors->get('hora_inicio')" />
        </div>

        {{-- Entre pacientes: sin horario; el cupo del profesional ese día (CupoEntrePacientes), solo para informar: el
             servidor lo vuelve a contar con la fila bloqueada al guardar. --}}
        <div class="space-y-2" x-show="modalidad === 'entre_pacientes'" @if ($modalidad !== 'entre_pacientes') style="display: none" @endif>
            <span class="block font-medium text-sm text-gray-700 dark:text-gray-300">Cupo entre pacientes</span>
            <p x-show="! listoParaBuscar" class="text-sm text-gray-500 dark:text-gray-400">Elija el profesional y la fecha para ver el cupo.</p>
            <p x-show="listoParaBuscar && cupo !== null" style="display: none" data-cupo class="text-sm text-gray-700 dark:text-gray-300"
                x-text="`Cupo: ${cupo?.usados} de ${cupo?.cupo} usados`"></p>
            <x-input-error :messages="$errors->get('cupo')" />
        </div>

        {{-- Segundo turno del día con el mismo profesional: no se guarda hasta confirmar (lo valida el servidor). --}}
        @if ($errors->has('agendar_igual') || old('agendar_igual'))
            <div role="alert" class="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-100">
                @foreach ($errors->get('agendar_igual') as $aviso)
                    <p class="font-medium">{{ $aviso }}</p>
                @endforeach
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="agendar_igual" value="1" @checked(old('agendar_igual'))
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900">
                    Agendar igual
                </label>
            </div>
        @endif

        <div class="grid gap-6 sm:grid-cols-2">
            <x-admin.select name="procedimiento_id" label="Procedimiento (opcional)" :options="$procedimientos" nullable />
            <x-admin.select name="origen_turno_id" label="Origen del turno (opcional)" :options="$origenes" nullable />
        </div>
        <x-admin.textarea name="observaciones" label="Observaciones (opcional)" maxlength="2000" rows="3" />
    </x-admin.form>
</x-admin.page>
