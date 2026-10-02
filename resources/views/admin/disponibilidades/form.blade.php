@use('App\Models\Disponibilidad')

<x-admin.page :title="$disponibilidad->exists ? 'Editar disponibilidad' : 'Nueva disponibilidad'">
    <x-admin.form
        :action="$disponibilidad->exists ? route('admin.disponibilidades.update', $disponibilidad) : route('admin.disponibilidades.store')"
        :method="$disponibilidad->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.disponibilidades.index')">
        <x-admin.selector-persona name="profesional_id" label="Profesional" :url="route('admin.disponibilidades.profesionales')"
            :inicial="$profesionalInicial" :ofrecer-alta="false" sin-resultados="No hay profesionales activos con esa búsqueda."
            ayuda="Solo aparecen profesionales activos. Busque por nombre, documento o matrícula." />

        <x-admin.select name="consultorio_id" label="Consultorio" :options="$consultorios" :value="$disponibilidad->consultorio_id" required />

        <div class="grid gap-6 sm:grid-cols-2">
            <x-admin.select name="dia_semana" label="Día de la semana" :options="Disponibilidad::DIAS" :value="$disponibilidad->dia_semana" required />
            <x-admin.input name="duracion_turno_minutos" label="Duración de cada turno (minutos)" type="number" min="5" max="480" step="5"
                :value="$disponibilidad->duracion_turno_minutos" required />
            <x-admin.hora name="hora_desde" label="Hora desde" :value="$disponibilidad->hora_desde" required />
            <x-admin.hora name="hora_hasta" label="Hora hasta" :value="$disponibilidad->hora_hasta" required />
            <x-admin.fecha name="vigencia_desde" label="Vigencia desde" :value="$disponibilidad->vigencia_desde" required />
            <x-admin.fecha name="vigencia_hasta" label="Vigencia hasta (opcional)" :value="$disponibilidad->vigencia_hasta" />
        </div>
        <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">
            La franja se divide en turnos de la duración indicada (el último solo si entra completo). No puede superponerse con otra
            disponibilidad activa del mismo profesional ni del mismo consultorio.
        </p>

        @if ($disponibilidad->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$disponibilidad::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$disponibilidad->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
