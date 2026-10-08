@use('App\Models\Persona')
@use('App\Support\Fecha')

@php
    $paciente = $historia->paciente;
    $persona = $paciente->persona;
    // Edad a hoy (hora de Paraguay), en años cumplidos.
    $edad = $persona->fecha_nacimiento ? (int) $persona->fecha_nacimiento->diffInYears(Fecha::hoy()) : null;
@endphp

<x-admin.page title="Historia clínica"
    :create-route="$puedeAtender ? route('admin.consultas.create', $historia) : null" create-label="Atender sin turno">
    <div class="p-6 space-y-6">
        <dl class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6 text-sm">
            <div class="sm:col-span-2">
                <dt class="text-gray-500 dark:text-gray-400">Paciente</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $persona->nombre_completo }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Documento</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $persona->tipoDocumento->codigo }}</span>
                    <span class="font-mono">{{ $persona->nro_documento }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Nro. ficha</dt>
                <dd class="font-medium font-mono text-gray-900 dark:text-gray-100">{{ $paciente->nro_ficha }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Edad</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $edad === null ? '—' : $edad.' '.($edad === 1 ? 'año' : 'años') }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Sexo</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ Persona::SEXOS[$persona->sexo] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Apertura de la historia</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($historia->fecha_apertura) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Estado del paciente</dt>
                <dd><x-admin.estado-badge :estado="$paciente->estado" /></dd>
            </div>
        </dl>

        <div class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Consultas</h3>

            <x-admin.table :headers="['Fecha y hora', 'Profesional', 'Motivo', 'Diagnósticos']" :paginator="$consultas">
                @forelse ($consultas as $consulta)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm">{{ $consulta->fechaHoraTexto() }}</td>
                        <td class="px-6 py-4">{{ $consulta->profesional->persona->nombre_completo }}</td>
                        <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ Str::limit($consulta->motivo_consulta, 120) }}</td>
                        <td class="px-6 py-4 font-mono text-sm whitespace-nowrap">
                            {{-- Vigentes, el principal primero y en negrita. --}}
                            @forelse ($consulta->diagnosticos as $diagnostico)
                                <span @class(['font-semibold' => $diagnostico->principal]) @if ($diagnostico->principal) title="Diagnóstico principal" @endif>{{ $diagnostico->codigo_cie10 }}</span>@unless ($loop->last), @endunless
                            @empty
                                <span class="text-gray-500 dark:text-gray-400">—</span>
                            @endforelse
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            <a href="{{ route('admin.consultas.show', $consulta) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver consulta</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">Todavía no hay consultas en esta historia.</td>
                    </tr>
                @endforelse
            </x-admin.table>
        </div>

        <a href="{{ route('admin.historias-clinicas.index') }}" class="inline-block text-sm text-gray-600 dark:text-gray-400 hover:underline">Volver a las historias clínicas</a>
    </div>
</x-admin.page>
