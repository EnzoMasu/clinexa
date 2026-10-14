@use('App\Support\Fecha')
@use('App\Models\SalidaSinAtencion')

{{--
    Retiros y rechazos de los turnos del profesional (SalidaController). Recepción registra el hecho; acá el profesional
    puede completar una nota clínica, una sola vez por cada uno (no se edita ni se borra). Contenido clínico: todo escapado.
--}}
<x-admin.page title="Retiros y rechazos">
    <div class="space-y-4 p-6">
        @if (session('status'))
            <p role="status" class="rounded-md bg-green-50 p-4 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">{{ session('status') }}</p>
        @endif
        @if (session('error'))
            <p role="alert" class="rounded-md bg-red-50 p-4 text-sm text-red-800 dark:bg-red-900/40 dark:text-red-200">{{ session('error') }}</p>
        @endif

        <p class="text-sm text-gray-600 dark:text-gray-400">
            Pacientes de sus turnos que se retiraron o rechazaron la atención sin ser atendidos. Si lo desea, complete una nota clínica:
            se guarda una sola vez y no puede modificarse.
        </p>

        @forelse ($salidas as $salida)
            <section class="space-y-2 rounded-md border border-gray-200 p-4 text-sm dark:border-gray-700">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span class="dato text-xs">{{ Fecha::mostrar($salida->turno->fecha) }} {{ $salida->turno->horaOTipo() }}</span>
                    <span class="flex-1 font-medium">{{ $salida->turno->paciente->persona->nombre_completo }}</span>
                    <span class="text-xs text-gray-600 dark:text-gray-400">{{ SalidaSinAtencion::TIPOS[$salida->tipo] }}{{ $salida->origen === SalidaSinAtencion::ORIGEN_CIERRE_JORNADA ? ' (cierre de jornada)' : '' }}</span>
                </div>
                @if ($salida->nota)
                    <p class="whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $salida->nota->texto }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Nota clínica guardada el {{ Fecha::mostrar($salida->nota->created_at) }}.</p>
                @elseif ($puedeEscribir)
                    <form method="POST" action="{{ route('admin.atencion.salidas.nota', $salida) }}" class="space-y-2">
                        @csrf
                        <x-input-label for="texto-{{ $salida->id }}" value="Nota clínica (se guarda una sola vez)" />
                        <textarea id="texto-{{ $salida->id }}" name="texto" rows="3" maxlength="{{ $textoMaximo }}" required
                            class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">{{ old('salida') == $salida->id ? old('texto') : '' }}</textarea>
                        <input type="hidden" name="salida" value="{{ $salida->id }}">
                        <x-primary-button>Guardar nota</x-primary-button>
                    </form>
                @endif
            </section>
        @empty
            <p class="text-sm text-gray-600 dark:text-gray-400">No hay retiros ni rechazos registrados.</p>
        @endforelse
    </div>
</x-admin.page>
