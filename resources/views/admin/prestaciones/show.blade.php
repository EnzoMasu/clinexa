@use('App\Support\Fecha')
@use('App\Support\Prestaciones\AnularPrestacion')

{{-- Detalle de una prestación (PrestacionController::show). Sin contenido clínico de la consulta. --}}
<x-admin.page title="Prestación">
    <div class="space-y-6 p-6 text-sm">
        <dl class="grid max-w-3xl grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Estado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $prestacion->etiquetaEstado() }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Fecha de servicio</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($prestacion->fecha_servicio) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Paciente</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $prestacion->paciente->persona->nombre_completo }} ({{ $prestacion->paciente->nro_ficha }})</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Profesional</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $prestacion->profesional->persona->nombre_completo }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Procedimiento</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $prestacion->procedimiento?->nombre ?? 'Sin procedimiento' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Origen</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $prestacion->turno ? 'Turno del '.Fecha::mostrar($prestacion->turno->fecha) : 'Sin turno' }} · consulta N.º {{ $prestacion->consulta_id }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Monto</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $prestacion->montoTexto() }}</dd></div>
            @if ($prestacion->tarifa)
                <div><dt class="text-gray-500 dark:text-gray-400">Tarifa usada</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $prestacion->tarifa->profesionalTexto() }} · {{ $prestacion->tarifa->vigenciaTexto() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Tarifada</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($prestacion->tarifada_en, conHora: true) }}{{ $prestacion->tarifadaPor ? ' por '.$prestacion->tarifadaPor->persona?->nombre_completo : ' al finalizar la consulta' }}</dd></div>
            @endif
            @if ($prestacion->anulada())
                <div><dt class="text-gray-500 dark:text-gray-400">Anulada</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($prestacion->anulada_en, conHora: true) }}{{ $prestacion->anuladaPor ? ' por '.$prestacion->anuladaPor->persona?->nombre_completo : '' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Motivo de la anulación</dt><dd class="whitespace-pre-line font-medium text-gray-900 dark:text-gray-100">{{ $prestacion->motivo_anulacion }}</dd></div>
            @endif
        </dl>

        <div class="flex flex-wrap items-center gap-4">
            @if ($puedeTarifar)
                <form method="POST" action="{{ route('admin.prestaciones.tarifar', $prestacion) }}">
                    @csrf
                    <x-primary-button>Tarifar</x-primary-button>
                </form>
            @endif
            <a href="{{ route('admin.prestaciones.index') }}" class="text-gray-600 hover:underline dark:text-gray-400">Volver a Prestaciones</a>
        </div>

        @if ($puedeAnular)
            <details class="max-w-xl rounded-md border border-red-200 p-3 dark:border-red-900" @if ($errors->has('motivo')) open @endif>
                <summary class="cursor-pointer font-medium text-red-600 dark:text-red-400">Anular la prestación</summary>
                <form method="POST" action="{{ route('admin.prestaciones.anular', $prestacion) }}" class="mt-3 space-y-2">
                    @csrf
                    <label for="motivo" class="block font-medium text-gray-700 dark:text-gray-300">Motivo de la anulación (obligatorio)</label>
                    <textarea id="motivo" name="motivo" rows="3" minlength="{{ AnularPrestacion::MOTIVO_MINIMO }}" maxlength="{{ AnularPrestacion::MOTIVO_MAXIMO }}" required
                        class="block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ old('motivo') }}</textarea>
                    <x-input-error :messages="$errors->get('motivo')" />
                    <button type="submit" class="rounded-md border border-red-600 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/30">Anular</button>
                </form>
            </details>
        @endif
    </div>
</x-admin.page>
