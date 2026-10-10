@use('App\Support\Fecha')

<x-admin.page title="Consulta">
    <div class="mx-auto max-w-4xl space-y-4 p-4 text-sm sm:p-6">
        {{-- Una consulta cerrada (FINALIZADA) queda llaveada: no hay edición (docs/historia-clinica.md). --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            @if ($consulta->finalizada())
                <p role="status" class="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 dark:bg-gray-700/60 dark:text-gray-200">
                    Consulta cerrada: no puede modificarse.
                </p>
            @else
                <span></span>
            @endif
            <a href="{{ route('admin.historias-clinicas.show', $consulta->historiaClinica) }}"
                class="text-sm text-gray-600 hover:underline dark:text-gray-400">Volver a la historia</a>
        </div>

        @include('admin.consultas._contenido', ['consulta' => $consulta])

        {{--
            Acciones de las recetas: solo en la página (fuera del partial, que comparte el popup). Escribir
            lo decide RecetaPolicy: el profesional que atiende, con el permiso de RECETAS que corresponde.
        --}}
        @if ($verRecetas && ($puedeCrearReceta || $consulta->recetas->isNotEmpty()))
            <section class="rounded-md border border-gray-200 p-3 dark:border-gray-700" aria-labelledby="acciones-recetas">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 id="acciones-recetas" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Acciones de recetas</h4>
                    @if ($puedeCrearReceta)
                        <a href="{{ route('admin.recetas.create', $consulta) }}"
                            class="inline-flex items-center rounded-md bg-gray-800 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white">Nueva receta</a>
                    @endif
                </div>
                @if ($consulta->recetas->isNotEmpty())
                    <ul class="mt-2 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($consulta->recetas->sortBy('id') as $receta)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>
                                    <span class="dato text-xs font-semibold">{{ $receta->numero ?? 'Borrador' }}</span>
                                    <span class="text-xs text-gray-600 dark:text-gray-400">· {{ $receta->etiquetaEstado() }}</span>
                                </span>
                                <span class="flex flex-wrap items-center gap-3 text-sm">
                                    @if ($receta->esBorrador())
                                        @can('update', $receta)
                                            <a href="{{ route('admin.recetas.edit', $receta) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</a>
                                        @endcan
                                        <a href="{{ route('admin.recetas.vista-previa', $receta) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Vista previa</a>
                                    @else
                                        <a href="{{ route('admin.recetas.imprimir', $receta) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">{{ $receta->estaEmitida() ? 'Imprimir' : 'Ver hoja' }}</a>
                                    @endif
                                    @can('anular', $receta)
                                        <details class="relative">
                                            <summary class="cursor-pointer font-medium text-red-600 hover:text-red-900 dark:text-red-400">Anular</summary>
                                            <form method="POST" action="{{ route('admin.recetas.anular', $receta) }}" class="absolute right-0 z-10 mt-2 w-80 space-y-2 rounded-md border border-gray-200 bg-white p-3 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                                @csrf
                                                <label for="motivo_{{ $receta->id }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Motivo de la anulación (obligatorio)</label>
                                                <textarea id="motivo_{{ $receta->id }}" name="motivo" rows="3" minlength="5" maxlength="300" required
                                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"></textarea>
                                                <div class="flex gap-2">
                                                    <button type="submit" class="rounded-md border border-red-500 px-3 py-1 text-xs font-semibold text-red-700 dark:text-red-300">Anular</button>
                                                    @can('corregir', $receta)
                                                        <button type="submit" formaction="{{ route('admin.recetas.corregir', $receta) }}" class="rounded-md border border-gray-400 px-3 py-1 text-xs font-semibold text-gray-700 dark:text-gray-300">Anular y corregir</button>
                                                    @endcan
                                                </div>
                                            </form>
                                        </details>
                                    @endcan
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @error('motivo')
                    <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </section>
        @endif

        {{-- Solo con VER sobre Auditoría (su lectura se registra en el controlador). Plegado: es solo visual. --}}
        @if ($historial !== null)
            <details class="rounded-md border border-gray-200 dark:border-gray-700">
                <summary class="cursor-pointer select-none px-4 py-2 font-medium text-gray-900 dark:text-gray-100">Historial de cambios</summary>
                {{-- Cada racha de autoguardados seguidos del mismo usuario se ve como una entrada; hasta las últimas 50 entradas. --}}
                @if ($cambiosEnTotal > $historial->count())
                    <p class="px-4 pb-2 text-xs text-gray-500 dark:text-gray-400">Se muestran las últimas {{ $historial->count() }} de {{ $cambiosEnTotal }} entradas. El resto está en Auditoría.</p>
                @endif
                <x-admin.table :headers="['Fecha y hora', 'Usuario', 'Acción', 'Qué cambió']">
                    @forelse ($historial as ['evento' => $evento, 'desde' => $desde, 'cantidad' => $cantidad, 'secciones' => $secciones])
                        <tr>
                            <td class="px-6 py-3 whitespace-nowrap dato text-sm">
                                {{ $cantidad > 1 ? Fecha::mostrar($desde, conHora: true).' – '.substr(Fecha::mostrar($evento->fecha_hora, conHora: true), -5) : Fecha::mostrar($evento->fecha_hora, conHora: true) }}
                            </td>
                            <td class="px-6 py-3">{{ $evento->usuario?->name ?? 'Sin usuario' }}</td>
                            <td class="px-6 py-3">
                                {{ $evento->accion->etiqueta() }}@if ($evento->detalle) · {{ $evento->detalle }}@endif
                                @if ($cantidad > 1)<span class="text-xs text-gray-500 dark:text-gray-400">({{ $cantidad }} guardados)</span>@endif
                            </td>
                            <td class="px-6 py-3 text-gray-600 dark:text-gray-400">
                                @if ($evento->tabla_afectada === 'recetas')
                                    Receta {{ $consulta->recetas->firstWhere('id', (int) $evento->registro_afectado_id)?->numero ?? 'en borrador' }}:
                                @endif
                                {{ collect($secciones)->map(fn ($campo) => ['bloquesAnamnesis' => 'anamnesis', 'examenFisico' => 'examen físico', 'diagnosticos' => 'diagnósticos', 'indicaciones' => 'indicaciones generales', 'motivo_consulta' => 'motivo', 'detalles' => 'medicamentos', 'estado_id' => 'estado', 'motivo_anulacion' => 'motivo de la anulación', 'numero' => 'número', 'emitida_en' => 'emisión', 'anulada_en' => 'anulación'][$campo] ?? $campo)->join(', ') }}
                            </td>
                            <td class="px-6 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('admin.auditoria.show', $evento) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ver detalle</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-3 text-sm text-gray-500 dark:text-gray-400">Sin cambios registrados.</td></tr>
                    @endforelse
                </x-admin.table>
            </details>
        @endif
    </div>
</x-admin.page>
