@use('App\Support\Fecha')
@use('App\Support\Permisos')

{{-- Parámetros clínicos (Catálogos): el juego fijo de signos vitales con sus tres bandas, su estado y quién lo aprobó. --}}
<x-admin.page title="Parámetros clínicos">
    <div class="space-y-4 p-6">
        <p class="text-sm text-gray-700 dark:text-gray-300">Estos valores los define y aprueba un médico. Los rangos son para adultos.</p>
        @if ($faltanAprobados)
            @include('admin.parametros-clinicos._banner')
        @endif
        <p class="text-xs text-gray-600 dark:text-gray-400">
            <span class="font-semibold text-red-700 dark:text-red-300">Imposible</span>: fuera de esta banda se rechaza (rige siempre).
            <span class="font-semibold text-red-700 dark:text-red-300">Muy alterado</span>: fuera de esta banda pide confirmación.
            <span class="font-semibold text-amber-800 dark:text-amber-300">Normal</span>: fuera de esta banda se marca "Fuera de lo normal".
            Solo los parámetros aprobados marcan alertas.
        </p>

        <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:bg-gray-900/50 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-4 py-3">Parámetro</th>
                        <th scope="col" class="px-4 py-3">Imposible</th>
                        <th scope="col" class="px-4 py-3">Muy alterado</th>
                        <th scope="col" class="px-4 py-3">Normal</th>
                        <th scope="col" class="px-4 py-3">Estado</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 text-gray-900 dark:divide-gray-700 dark:text-gray-100">
                    @foreach ($parametros as $parametro)
                        <tr data-parametro="{{ $parametro->codigo }}">
                            <td class="px-4 py-3 font-medium">{{ $parametro->nombre }} <span class="text-xs font-normal text-gray-600 dark:text-gray-400">({{ $parametro->unidad }})</span></td>
                            <td class="px-4 py-3 dato text-red-800 dark:text-red-300">{{ $parametro->banda('imposible') }}</td>
                            <td class="px-4 py-3 dato text-red-700 dark:text-red-300">{{ $parametro->banda('muy_alterado') }}</td>
                            <td class="px-4 py-3 dato text-amber-900 dark:text-amber-300">{{ $parametro->banda('normal') }}</td>
                            <td class="px-4 py-3">
                                <x-admin.estado-badge :estado="$parametro->estado" />
                                @if ($parametro->aprobado() && $parametro->aprobadoPor)
                                    <span class="block text-xs text-gray-600 dark:text-gray-400">
                                        {{ \App\Support\Atencion\DatosFormulario::nombre($parametro->aprobadoPor) }}, {{ Fecha::mostrar($parametro->aprobado_en, conHora: true) }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap space-x-3">
                                @if ($url = Permisos::url('admin.parametros-clinicos.edit', $parametro))
                                    <a href="{{ $url }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Editar</a>
                                @endif
                                @if (! $parametro->aprobado() && ($url = Permisos::url('admin.parametros-clinicos.aprobar', $parametro)) && auth()->user()->profesional?->estaActivo())
                                    <form method="POST" action="{{ $url }}" class="inline">
                                        @csrf
                                        <button type="submit" class="font-medium text-green-700 hover:text-green-900 dark:text-green-400">Aprobar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-admin.page>
