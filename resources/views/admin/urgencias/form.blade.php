{{--
    Registrar urgencia (UrgenciaController::create): paciente registrado y activo (el buscador de turnos) y profesional
    activo (primero los que atienden hoy). Sin texto libre: lo clínico lo escribe el triaje. Crea un turno URGENCIA de
    hoy, PENDIENTE, origen presencial.
--}}
<x-admin.page title="Registrar urgencia">
    <x-admin.form :action="route('admin.turnos.urgencias.store')" :cancel="route('admin.turnos.urgencias')" width="max-w-3xl" submit-label="Registrar urgencia">
        <x-admin.selector-persona name="paciente_id" label="Paciente" :url="route('admin.turnos.pacientes')"
            :inicial="$pacienteInicial" :ofrecer-alta="false" sin-resultados="No hay pacientes activos con esa búsqueda."
            ayuda="Solo aparecen pacientes activos. Busque por nombre, documento o número de ficha." />
        @include('admin.atencion._ayuda-paciente')

        <x-admin.select name="profesional_id" label="Profesional">
            @if ($atiendenHoy->isNotEmpty())
                <optgroup label="Atienden hoy">
                    @foreach ($atiendenHoy as $profesional)
                        <option value="{{ $profesional->id }}" @selected((string) old('profesional_id') === (string) $profesional->id)>{{ $profesional->persona->nombre_completo }}</option>
                    @endforeach
                </optgroup>
            @endif
            @if ($otros->isNotEmpty())
                <optgroup label="Otros profesionales">
                    @foreach ($otros as $profesional)
                        <option value="{{ $profesional->id }}" @selected((string) old('profesional_id') === (string) $profesional->id)>{{ $profesional->persona->nombre_completo }}</option>
                    @endforeach
                </optgroup>
            @endif
        </x-admin.select>

        {{-- Segundo turno del día con el mismo profesional: no se registra hasta confirmar (lo valida el servidor). --}}
        @if ($errors->has('agendar_igual') || old('agendar_igual'))
            <div role="alert" class="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-100">
                @foreach ($errors->get('agendar_igual') as $aviso)
                    <p class="font-medium">{{ $aviso }}</p>
                @endforeach
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="agendar_igual" value="1" @checked(old('agendar_igual'))
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900">
                    Registrar igual
                </label>
            </div>
        @endif

        <p class="text-sm text-gray-600 dark:text-gray-400">
            Se registra como urgencia de hoy y le aparece al profesional en su pantalla de Consulta. Los datos clínicos los carga el triaje.
        </p>
    </x-admin.form>
</x-admin.page>
