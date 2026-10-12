@use('App\Support\Permisos')

{{-- Matriz de permisos, pestaña Comparar: todos los perfiles en columnas, solo lectura. En un perfil predefinido se
     marcan las celdas que difieren de PerfilesPredefinidos::MATRIZ (lo predefinido no se cambia acá). --}}
<x-admin.page title="Matriz de permisos">
    <div class="p-6 space-y-4">
        @include('admin.matriz-permisos._pestanas', ['activa' => 'comparar'])

        <p class="text-sm text-gray-600 dark:text-gray-400">
            Qué puede hacer cada perfil en cada módulo. Un usuario con varios perfiles suma los permisos de los que estén
            activos. <span class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900/50 dark:text-amber-200">Marcado</span>:
            un perfil predefinido que difiere de su matriz predefinida.
        </p>

        @if ($perfiles->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">No hay perfiles de acceso cargados.</p>
        @else
            <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th scope="col" class="sticky left-0 bg-gray-50 dark:bg-gray-900 px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Módulo</th>
                            @foreach ($perfiles as $perfil)
                                <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 align-bottom">
                                    <a href="{{ route('admin.matriz-permisos.index', ['perfil' => $perfil->id]) }}" class="hover:underline">{{ $perfil->nombre }}</a>
                                    @unless ($perfil->estaActivo())
                                        <span class="block font-normal text-gray-600 dark:text-gray-400">(inactivo)</span>
                                    @endunless
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                        @foreach ($grupos as $grupo => $modulos)
                            <tr class="bg-gray-50 dark:bg-gray-900/40">
                                <th scope="colgroup" colspan="{{ $perfiles->count() + 1 }}" class="sticky left-0 px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">{{ $grupo }}</th>
                            </tr>
                            @foreach ($modulos as $modulo)
                                <tr>
                                    <th scope="row" class="sticky left-0 bg-white dark:bg-gray-800 px-4 py-2 text-left font-medium whitespace-nowrap">
                                        {{ $modulo->nombre }}
                                        @if ($modulo->es_sensible)
                                            <span class="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">Sensible</span>
                                        @endif
                                    </th>
                                    @foreach ($perfiles as $perfil)
                                        @php($acciones = $matriz[$perfil->id][$modulo->codigo])
                                        @php($esperado = $difiere[$perfil->id][$modulo->codigo] ?? null)
                                        <td @class(['px-3 py-2 whitespace-nowrap', 'bg-amber-100 dark:bg-amber-900/50' => $esperado !== null])
                                            data-perfil="{{ $perfil->id }}" data-modulo="{{ $modulo->codigo }}" @if ($esperado !== null) data-difiere title="Predefinido: {{ $esperado ? implode(', ', $esperado) : 'sin permisos' }}" @endif>
                                            @if ($acciones)
                                                <span class="text-xs">{{ implode(', ', $acciones) }}</span>
                                            @else
                                                <span class="text-gray-600 dark:text-gray-400" aria-label="Sin permisos">—</span>
                                            @endif
                                            @if ($esperado !== null)
                                                <span class="sr-only">(difiere de lo predefinido: {{ $esperado ? implode(', ', $esperado) : 'sin permisos' }})</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-admin.page>
