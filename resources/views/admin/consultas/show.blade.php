@use('App\Models\ExamenFisico')
@use('App\Support\Fecha')

@php
    $paciente = $consulta->historiaClinica->paciente;
    $bloques = $consulta->bloquesAnamnesis->sortBy(['orden', 'id']);
    // Vigentes primero (el principal arriba); los retirados al final, atenuados.
    $diagnosticos = $consulta->diagnosticos->sortBy([['activo', 'desc'], ['principal', 'desc'], ['id', 'asc']]);
    $examen = $consulta->examenFisico;
@endphp

<x-admin.page title="Consulta" :create-route="$puedeModificar ? route('admin.consultas.edit', $consulta) : null" create-label="Editar">
    <div class="p-6 space-y-8 max-w-5xl">
        <dl class="grid gap-4 sm:grid-cols-4 text-sm">
            <div class="sm:col-span-2">
                <dt class="text-gray-500 dark:text-gray-400">Paciente</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    <a href="{{ route('admin.historias-clinicas.show', $consulta->historiaClinica) }}" class="hover:underline">{{ $paciente->persona->nombre_completo }}</a>
                </dd>
                <dd class="text-xs text-gray-500 dark:text-gray-400">Ficha {{ $paciente->nro_ficha }} · {{ $paciente->persona->tipoDocumento->codigo }} {{ $paciente->persona->nro_documento }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Fecha y hora</dt>
                <dd class="font-medium font-mono text-gray-900 dark:text-gray-100">{{ $consulta->fechaHoraTexto() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Profesional</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $consulta->profesional->persona->nombre_completo }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Turno</dt>
                <dd class="text-gray-900 dark:text-gray-100">
                    {{ $consulta->turno ? Fecha::mostrar($consulta->turno->fecha).', '.substr($consulta->turno->hora_inicio, 0, 5) : 'Sin turno (urgencia)' }}
                </dd>
            </div>
        </dl>

        <section class="space-y-2">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Motivo de consulta</h3>
            <p class="text-sm text-gray-900 dark:text-gray-100 whitespace-pre-line">{{ $consulta->motivo_consulta }}</p>
        </section>

        <section class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Anamnesis</h3>
            @forelse ($bloques as $bloque)
                <div @class(['rounded-md border p-3 text-sm', 'border-gray-200 dark:border-gray-700' => $bloque->activo, 'border-dashed border-gray-300 bg-gray-50 opacity-60 dark:border-gray-600 dark:bg-gray-900/40' => ! $bloque->activo])>
                    <div class="font-medium text-gray-700 dark:text-gray-300">
                        {{ $bloque->tipoBloqueAnamnesis->nombre }}
                        @unless ($bloque->activo)
                            <span class="ms-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Retirado</span>
                        @endunless
                    </div>
                    <p class="mt-1 text-gray-900 dark:text-gray-100 whitespace-pre-line">{{ $bloque->contenido }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Sin anamnesis.</p>
            @endforelse
        </section>

        <section class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Examen físico</h3>
            @if ($examen && $examen->descripcion() !== [])
                <dl class="grid gap-4 sm:grid-cols-4 text-sm">
                    @foreach (array_keys(ExamenFisico::CAMPOS) as $campo)
                        @continue($campo === 'hallazgos')
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ ExamenFisico::CAMPOS[$campo][0] }}</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $examen->valorTexto($campo) ?? '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($examen->hallazgos)
                    <div class="text-sm">
                        <div class="text-gray-500 dark:text-gray-400">Hallazgos</div>
                        <p class="text-gray-900 dark:text-gray-100 whitespace-pre-line">{{ $examen->hallazgos }}</p>
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Sin examen físico.</p>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Diagnósticos</h3>
            @forelse ($diagnosticos as $diagnostico)
                <div @class(['rounded-md border p-3 text-sm', 'border-gray-200 dark:border-gray-700' => $diagnostico->activo, 'border-dashed border-gray-300 bg-gray-50 opacity-60 dark:border-gray-600 dark:bg-gray-900/40' => ! $diagnostico->activo])>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $diagnostico->codigoYDescripcion() }}</span>
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ $diagnostico->tipo->etiqueta() }}</span>
                        @if ($diagnostico->principal)
                            <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200">Principal</span>
                        @endif
                        @unless ($diagnostico->activo)
                            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Retirado</span>
                        @endunless
                    </div>
                    @if ($diagnostico->descripcion_adicional)
                        <p class="mt-1 text-gray-700 dark:text-gray-300 whitespace-pre-line">{{ $diagnostico->descripcion_adicional }}</p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Sin diagnósticos.</p>
            @endforelse
        </section>

        {{-- Solo con VER sobre Auditoría: los cambios de esta consulta (el detalle, en la pantalla de auditoría). --}}
        @if ($historial !== null)
            <section class="space-y-3">
                <h3 class="font-medium text-gray-900 dark:text-gray-100">Historial de cambios</h3>
                <x-admin.table :headers="['Fecha y hora', 'Usuario', 'Acción', 'Qué cambió']">
                    @forelse ($historial as $evento)
                        <tr>
                            <td class="px-6 py-3 whitespace-nowrap font-mono text-sm">{{ Fecha::mostrar($evento->fecha_hora, conHora: true) }}</td>
                            <td class="px-6 py-3">{{ $evento->usuario?->name ?? 'Sin usuario' }}</td>
                            <td class="px-6 py-3">{{ $evento->accion->etiqueta() }}</td>
                            <td class="px-6 py-3 text-gray-600 dark:text-gray-400">
                                {{ collect(array_keys($evento->valor_nuevo ?? []))->map(fn ($campo) => ['bloquesAnamnesis' => 'anamnesis', 'examenFisico' => 'examen físico', 'diagnosticos' => 'diagnósticos', 'motivo_consulta' => 'motivo'][$campo] ?? $campo)->join(', ') }}
                            </td>
                            <td class="px-6 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('admin.auditoria.show', $evento) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ver detalle</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-3 text-sm text-gray-500 dark:text-gray-400">Sin cambios registrados.</td></tr>
                    @endforelse
                </x-admin.table>
            </section>
        @endif

        <a href="{{ route('admin.historias-clinicas.show', $consulta->historiaClinica) }}" class="inline-block text-sm text-gray-600 dark:text-gray-400 hover:underline">Volver a la historia clínica</a>
    </div>
</x-admin.page>
