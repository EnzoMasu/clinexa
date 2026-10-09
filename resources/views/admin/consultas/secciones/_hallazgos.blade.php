{{-- Hallazgos del examen físico (grupo CLÍNICO). Requiere $datos y $clases. --}}
<div>
    <label for="examen_hallazgos" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Hallazgos del examen</label>
    <textarea id="examen_hallazgos" name="examen[hallazgos]" rows="4" maxlength="2000" class="{{ $clases }}">{{ $datos['valorExamen']('hallazgos') }}</textarea>
    @if ($datos['autorHallazgos'])
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $datos['autorHallazgos'] }}</p>
    @endif
    <x-input-error class="mt-2" :messages="$errors->get('examen.hallazgos')" />
</div>
