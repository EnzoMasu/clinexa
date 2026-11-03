{{--
    Tabla de /admin. "headers": títulos fijos. "grilla" (App\Support\Grilla\Grilla): en su lugar, títulos que
    ordenan (asc/desc, en la consulta) y una fila de filtros por columna (texto, rango de fechas o desplegable).
    Los campos de la fila pertenecen al formulario del listado (x-admin.listado) por el atributo "form":
    filtran en vivo y, sin JavaScript, se envían con el botón "Buscar". Ver docs/grillas.md.
--}}
@props(['headers' => [], 'paginator' => null, 'grilla' => null])

@php
    use App\Support\Grilla\Columna;
    use App\Support\Grilla\Grilla;

    $formulario = Grilla::FORMULARIO;
    $ordenada = $grilla?->ordenada()?->clave;
    $campo = 'block w-full min-w-[6rem] rounded-md border-gray-300 py-1 text-xs font-normal normal-case tracking-normal shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
        <thead class="bg-gray-50 dark:bg-gray-900/50">
            @if ($grilla)
                <tr>
                    @foreach ($grilla->columnas() as $columna)
                        @php($esta = $ordenada === $columna->clave)
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                            @if ($columna->ordenable()) aria-sort="{{ $esta ? ($grilla->direccion() === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
                            @if ($columna->ordenable())
                                <a href="{{ $grilla->urlOrden($columna->clave) }}" data-grilla-orden
                                    title="Ordenar por {{ mb_strtolower($columna->etiqueta) }}"
                                    @class(['inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-gray-100', 'text-gray-900 dark:text-gray-100' => $esta])>
                                    {{ $columna->etiqueta }}
                                    <span aria-hidden="true" @class(['text-[0.65rem]', 'opacity-30' => ! $esta])>{{ $esta && $grilla->direccion() === 'desc' ? '▼' : '▲' }}</span>
                                </a>
                            @else
                                {{ $columna->etiqueta }}
                            @endif
                        </th>
                    @endforeach
                    <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                </tr>
                <tr class="border-t border-gray-200 dark:border-gray-700">
                    @foreach ($grilla->columnas() as $columna)
                        @php($valor = $grilla->valor($columna->clave))
                        @php($nombre = "f[{$columna->clave}]")
                        <td class="px-6 pb-3 align-top">
                            @if (! $columna->filtrable())
                                {{-- Sin filtro para esta columna. --}}
                            @elseif ($columna->tipo === Columna::OPCIONES)
                                <select name="{{ $nombre }}" form="{{ $formulario }}" @if ($columna->diferida) data-grilla-diferida @endif aria-label="Filtrar por {{ mb_strtolower($columna->etiqueta) }}" class="{{ $campo }} pe-7">
                                    <option value="">Todos</option>
                                    @foreach ($columna->opciones as $opcion => $etiqueta)
                                        <option value="{{ $opcion }}" @selected((string) $opcion === $valor)>{{ $etiqueta }}</option>
                                    @endforeach
                                </select>
                            @elseif ($columna->tipo === Columna::FECHA)
                                <div class="space-y-1">
                                    @foreach (['desde' => 'Desde', 'hasta' => 'Hasta'] as $extremo => $texto)
                                        <div x-data="campoFecha({ valor: @js($valor[$extremo]) })">
                                            <input type="text" name="{{ $nombre }}[{{ $extremo }}]" form="{{ $formulario }}" value="{{ $valor[$extremo] }}"
                                                x-ref="entrada" x-bind:value="valor" x-on:input="escribir($event)" data-grilla-fecha
                                                placeholder="{{ $texto }} dd/mm/aaaa" inputmode="numeric" maxlength="10" autocomplete="off"
                                                aria-label="{{ $columna->etiqueta }}: {{ mb_strtolower($texto) }} (dd/mm/aaaa)" class="{{ $campo }} min-w-[8.5rem]">
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <input type="search" name="{{ $nombre }}" form="{{ $formulario }}" value="{{ $valor }}" autocomplete="off"
                                    @if ($columna->tipo === Columna::NUMERO) inputmode="decimal" placeholder="=" @else placeholder="Contiene…" @endif
                                    aria-label="Filtrar por {{ mb_strtolower($columna->etiqueta) }}" class="{{ $campo }}">
                            @endif
                        </td>
                    @endforeach
                    <td class="px-6 pb-3 text-right align-top whitespace-nowrap">
                        @if ($ordenada)
                            <input type="hidden" name="orden" form="{{ $formulario }}" value="{{ $ordenada }}">
                            <input type="hidden" name="dir" form="{{ $formulario }}" value="{{ $grilla->direccion() }}">
                        @endif
                        @if ($grilla->hayFiltros() || $ordenada)
                            <a href="{{ $grilla->urlLimpiar() }}" data-grilla-limpiar class="text-xs font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Limpiar</a>
                        @endif
                    </td>
                </tr>
            @else
                <tr>
                    @foreach ($headers as $header)
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $header }}</th>
                    @endforeach
                    <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                </tr>
            @endif
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
            {{ $slot }}
        </tbody>
    </table>
</div>

@if ($paginator?->hasPages())
    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
        {{ $paginator->links() }}
    </div>
@endif
