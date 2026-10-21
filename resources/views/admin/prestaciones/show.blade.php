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
            <div><dt class="text-gray-500 dark:text-gray-400">Origen</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $prestacion->origenTexto() }} · {{ $prestacion->turno ? 'Turno del '.Fecha::mostrar($prestacion->turno->fecha) : 'Sin turno' }} · consulta N.º {{ $prestacion->consulta_id }}</dd></div>
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

        @if ($otrasDeLaConsulta->isNotEmpty())
            <section aria-labelledby="otras-de-la-consulta" class="max-w-3xl space-y-3">
                <h3 id="otras-de-la-consulta" class="font-semibold text-gray-900 dark:text-gray-100">Otras prestaciones de la consulta N.º {{ $prestacion->consulta_id }}</h3>
                <x-admin.table :headers="['Procedimiento', 'Origen', 'Monto', 'Estado']">
                    @foreach ($otrasDeLaConsulta as $otra)
                        <tr>
                            <td class="px-6 py-3">{{ $otra->procedimiento?->nombre ?? 'Sin procedimiento' }}</td>
                            <td class="px-6 py-3">{{ $otra->origenTexto() }}</td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $otra->montoTexto() }}</td>
                            <td class="px-6 py-3">{{ $otra->etiquetaEstado() }}</td>
                            <td class="px-6 py-3 text-right">
                                <a href="{{ route('admin.prestaciones.show', $otra) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </section>
        @endif

        @if ($veRepartos)
            @php($reparto = $prestacion->reparto)
            <section aria-labelledby="reparto" class="max-w-3xl space-y-3">
                <h3 id="reparto" class="font-semibold text-gray-900 dark:text-gray-100">Reparto</h3>
                @if ($reparto?->repartido() || ($reparto?->anulado() && $reparto->lineas->isNotEmpty()))
                    <p class="text-gray-600 dark:text-gray-400">
                        Repartido{{ $reparto->repartido_en ? ' el '.Fecha::mostrar($reparto->repartido_en, conHora: true) : '' }}{{ $reparto->repartidoPor ? ' por '.$reparto->repartidoPor->persona?->nombre_completo : '' }},
                        con los acuerdos vigentes el {{ Fecha::mostrar($prestacion->fecha_servicio) }}. No cambia aunque cambien los acuerdos.
                    </p>
                    <x-admin.table :headers="['Beneficiario', 'Porcentaje', 'Monto', 'Acuerdo usado']">
                        @foreach ($reparto->lineas as $linea)
                            <tr>
                                <td class="px-6 py-3"><span class="font-medium">{{ \App\Models\LineaReparto::ETIQUETAS[$linea->beneficiario] }}</span>@if ($linea->beneficiario !== 'CLINICA') · {{ $linea->beneficiarioTexto() }}@endif</td>
                                <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $linea->porcentajeTexto() }}</td>
                                <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $reparto->moneda->formatear($linea->monto) }}</td>
                                <td class="px-6 py-3 text-gray-600 dark:text-gray-400">
                                    @if ($linea->beneficiario === 'PROFESIONAL')
                                        {{ $linea->acuerdoProfesional->alcanceTexto() }} · {{ $linea->acuerdoProfesional->vigenciaTexto() }}
                                    @elseif ($linea->beneficiario === 'EQUIPO')
                                        {{ $linea->acuerdoEquipo->vigenciaTexto() }}
                                    @else
                                        El resto
                                    @endif
                                </td>
                                <td></td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                @elseif ($pendienteDeReparto)
                    <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                        Pendiente de reparto{{ $reparto?->motivo_pendiente ? ': '.$reparto->motivo_pendiente : '.' }}
                        @unless ($puedeRepartir)
                            Avise al Administrador o a Gerencia.
                        @endunless
                    </p>
                    @if ($puedeRepartir)
                        <form method="POST" action="{{ route('admin.prestaciones.repartir', $prestacion) }}">
                            @csrf
                            <x-primary-button>Repartir</x-primary-button>
                        </form>
                    @endif
                @else
                    <p class="text-gray-600 dark:text-gray-400">
                        @if ($reparto?->anulado())
                            Sin líneas de reparto.
                        @elseif ($prestacion->pendiente())
                            Se reparte al tarifarla.
                        @else
                            Sin reparto (la prestación es anterior a los repartos o está anulada).
                        @endif
                    </p>
                @endif
                @if ($reparto?->anulado())
                    <p class="text-gray-600 dark:text-gray-400">
                        Reparto anulado el {{ Fecha::mostrar($reparto->anulado_en, conHora: true) }}{{ $reparto->anuladoPor ? ' por '.$reparto->anuladoPor->persona?->nombre_completo : '' }}. {{ $reparto->motivo_anulacion }}
                    </p>
                @endif
            </section>
        @endif

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
