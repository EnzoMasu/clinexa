{{--
    Agregar una adenda a una consulta finalizada (CorreccionController::crearAdenda). Funciona sin JavaScript. La adenda
    no se edita ni se borra: se agrega una vez, con su motivo, su autor y su fecha.
--}}
@use('App\Models\AdendaConsulta')

<x-admin.page title="Agregar adenda">
    <x-admin.form :action="route('admin.correcciones.adenda.store', $consulta)" :cancel="route('admin.consultas.show', $consulta)" width="max-w-3xl" submit-label="Agregar adenda">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Consulta de {{ $consulta->historiaClinica->paciente->persona->nombre_completo }} con {{ $consulta->profesional->persona->nombre_completo }}
            ({{ $consulta->fechaHoraTexto() }}). La consulta no se modifica: la adenda se agrega debajo de lo original y no puede editarse ni borrarse después.
        </p>

        @if (session('error'))
            <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
        @endif

        <div>
            <x-input-label for="motivo" value="Motivo de la adenda (obligatorio)" />
            <textarea id="motivo" name="motivo" rows="2" minlength="{{ AdendaConsulta::MOTIVO_MINIMO }}" maxlength="{{ AdendaConsulta::MOTIVO_MAXIMO }}" required
                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">{{ old('motivo') }}</textarea>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">De {{ AdendaConsulta::MOTIVO_MINIMO }} a {{ AdendaConsulta::MOTIVO_MAXIMO }} caracteres.</p>
            <x-input-error class="mt-2" :messages="$errors->get('motivo')" />
        </div>

        <div>
            <x-input-label for="texto" value="Texto de la adenda" />
            <textarea id="texto" name="texto" rows="8" maxlength="{{ AdendaConsulta::TEXTO_MAXIMO }}" required
                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">{{ old('texto') }}</textarea>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Hasta {{ AdendaConsulta::TEXTO_MAXIMO }} caracteres.</p>
            <x-input-error class="mt-2" :messages="$errors->get('texto')" />
        </div>
    </x-admin.form>
</x-admin.page>
