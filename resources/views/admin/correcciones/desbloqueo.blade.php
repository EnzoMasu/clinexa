{{--
    Desbloquear una consulta finalizada para corregirla (CorreccionController::crearDesbloqueo). Funciona sin
    JavaScript. Abre una ventana de un solo uso, vigente 24 horas; mientras tanto solo se pueden corregir el motivo, la
    anamnesis, los hallazgos, los diagnósticos y las indicaciones. Antes de aplicar la corrección se guarda una versión.
--}}
@use('App\Models\DesbloqueoConsulta')

<x-admin.page title="Desbloquear para corregir">
    <x-admin.form :action="route('admin.correcciones.desbloqueo.store', $consulta)" :cancel="route('admin.consultas.show', $consulta)" width="max-w-3xl" submit-label="Desbloquear">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Consulta de {{ $consulta->historiaClinica->paciente->persona->nombre_completo }} con {{ $consulta->profesional->persona->nombre_completo }}
            ({{ $consulta->fechaHoraTexto() }}).
        </p>
        <ul class="list-disc space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-400">
            <li>El desbloqueo vale {{ DesbloqueoConsulta::HORAS_DE_VIGENCIA }} horas y se usa una sola vez: al guardar, la consulta vuelve a cerrarse.</li>
            <li>Se pueden corregir el motivo, la anamnesis, los hallazgos, los diagnósticos y las indicaciones. Nunca los signos vitales, el triaje, las recetas, el paciente, el profesional, las fechas ni el estado.</li>
            <li>Lo que había antes queda guardado en el historial de correcciones.</li>
        </ul>

        @if (session('error'))
            <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
        @endif

        <div>
            <x-input-label for="motivo" value="Motivo del desbloqueo (obligatorio)" />
            <textarea id="motivo" name="motivo" rows="3" minlength="{{ DesbloqueoConsulta::MOTIVO_MINIMO }}" maxlength="{{ DesbloqueoConsulta::MOTIVO_MAXIMO }}" required
                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">{{ old('motivo') }}</textarea>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">De {{ DesbloqueoConsulta::MOTIVO_MINIMO }} a {{ DesbloqueoConsulta::MOTIVO_MAXIMO }} caracteres.</p>
            <x-input-error class="mt-2" :messages="$errors->get('motivo')" />
        </div>
    </x-admin.form>
</x-admin.page>
