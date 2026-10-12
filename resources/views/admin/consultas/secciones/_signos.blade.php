{{-- Signos vitales (grupo PREPARACIÓN): todo opcional; coma o punto decimal. Requiere $datos y $clases. --}}
@use('App\Models\ExamenFisico')

<fieldset class="space-y-3">
    <legend class="font-medium text-gray-900 dark:text-gray-100">Signos vitales</legend>
    <div class="grid gap-4 sm:grid-cols-4">
        @foreach (['presion_arterial' => ['120/80', 'text', 10], 'frecuencia_cardiaca' => ['72', 'numeric', 3], 'frecuencia_respiratoria' => ['16', 'numeric', 2], 'temperatura' => ['36,5', 'decimal', 4], 'peso' => ['70,5', 'decimal', 6], 'talla' => ['165', 'decimal', 5], 'saturacion_oxigeno' => ['98', 'numeric', 3]] as $campo => [$ejemplo, $modo, $largo])
            <div>
                <label for="examen_{{ $campo }}" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                    {{ ExamenFisico::CAMPOS[$campo][0] }} <span class="text-xs text-gray-500 dark:text-gray-400">({{ ExamenFisico::CAMPOS[$campo][1] }})</span>
                </label>
                <input type="text" id="examen_{{ $campo }}" name="examen[{{ $campo }}]" value="{{ $datos['valorExamen']($campo) }}"
                    inputmode="{{ $modo === 'text' ? 'text' : $modo }}" maxlength="{{ $largo }}" placeholder="{{ $ejemplo }}" autocomplete="off" class="{{ $clases }}">
                <x-input-error class="mt-2" :messages="$errors->get('examen.'.$campo)" />
                {{-- Valor muy alterado sin confirmar (sin JavaScript, o al Finalizar): el aviso y su casilla. Al
                     marcarla se manda confirmaciones[campo] = el mismo valor; si el valor cambia, no vale. --}}
                @if ($errors->has('confirmaciones.'.$campo))
                    <div class="mt-2 rounded-md border border-red-300 bg-red-50 p-2 text-sm text-red-800 dark:border-red-700 dark:bg-red-900/30 dark:text-red-200">
                        <p>{{ $errors->first('confirmaciones.'.$campo) }}</p>
                        <label class="mt-1 flex items-center gap-2 font-medium">
                            <input type="checkbox" name="confirmaciones[{{ $campo }}]" value="{{ $datos['valorExamen']($campo) }}"
                                class="rounded border-gray-300 text-red-600 shadow-sm focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900">
                            Sí, es correcto
                        </label>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    @if ($datos['autorSignos'])
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $datos['autorSignos'] }}</p>
    @endif
</fieldset>
