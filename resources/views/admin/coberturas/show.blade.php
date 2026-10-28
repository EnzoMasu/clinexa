@use('App\Http\Controllers\Admin\CoberturaController')
@use('App\Models\CoberturaPaciente')
@use('App\Models\LineaVisacion')
@use('App\Models\Prestacion')
@use('App\Support\Fecha')
@use('App\Support\Permisos')

{{--
    Cobertura y visaciones de la paciente (CoberturaController::show, docs/convenios-seguros.md). Sin entrada en el menú: se llega desde
    la ficha, el turno o la prestación. La cobertura es solo un dato de afiliación; la visación (la respuesta de la aseguradora) se carga
    antes de atender, para un turno o una consulta. Sin contenido clínico.
--}}
@php
    // Con el turno elegido (desde la Agenda general o Turnos) la visación va primero. Si la paciente no tiene cobertura, sus datos van
    // en el mismo formulario que la visación (un solo envío); si no, el formulario de la cobertura es el de la sección Cobertura.
    $coberturaEnVisacion = ! $vigente && $veVisaciones && $puedeCargarVisacion && $puedeCargarCobertura && $planes !== [];
@endphp
<x-admin.page :title="'Cobertura y visaciones: '.$paciente->persona->nombre_completo">
    <div class="flex flex-col gap-10 p-6 text-sm">
        <p class="order-first text-gray-600 dark:text-gray-400">
            Ficha {{ $paciente->nro_ficha }} · {{ $paciente->persona->documentoPrincipal() }}
            · <a href="{{ route('admin.turnos.index', ['q' => $paciente->persona->nro_documento]) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Ver sus turnos</a>
        </p>

        {{-- Cobertura --}}
        <section class="space-y-4" aria-labelledby="titulo-cobertura">
            <h3 id="titulo-cobertura" class="text-base font-semibold text-gray-900 dark:text-gray-100">Cobertura</h3>
            @if ($vigente)
                <dl class="grid max-w-3xl grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                    <div><dt class="text-gray-500 dark:text-gray-400">Plan</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $vigente->plan->nombreCompleto() }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">N.º de asegurado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $vigente->numero_asegurado }} ({{ $vigente->tipoTexto() }})</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Vigencia</dt><dd>{{ $vigente->vigenciaTexto() }}</dd></div>
                    @if ($vigente->vigente_hasta && $vigente->vigente_hasta->lt(Fecha::hoy()))
                        <div><dd class="font-medium text-amber-700 dark:text-amber-300">Vencida: las prestaciones nuevas quedan particulares.</dd></div>
                    @endif
                </dl>
                @if ($puedeBajaCobertura)
                    <form method="POST" action="{{ route('admin.coberturas.baja', $vigente) }}" novalidate class="flex flex-wrap items-end gap-3"
                        x-data x-on:submit="if (! confirm('¿Dar de baja la cobertura?')) $event.preventDefault()">
                        @csrf
                        <div class="w-48"><x-admin.fecha name="ultimo_dia" label="Último día" :value="Fecha::hoy()" /></div>
                        <x-danger-button>Dar de baja</x-danger-button>
                    </form>
                @endif
            @else
                <p class="text-gray-600 dark:text-gray-400">Sin cobertura vigente: sus prestaciones son particulares.</p>
            @endif

            @if ($coberturaEnVisacion)
                <p class="text-gray-600 dark:text-gray-400">Cárguela junto con la visación, en el mismo formulario de más abajo.</p>
            @elseif ($puedeCargarCobertura)
                <details id="cambiar-cobertura" @if ($errors->hasAny(['plan_convenio_id', 'numero_asegurado', 'tipo_afiliado', 'vigente_desde', 'vigente_hasta']) || (! $vigente && ! $turno)) open @endif class="max-w-3xl rounded-md border border-gray-200 p-4 dark:border-gray-700">
                    <summary class="cursor-pointer font-medium text-indigo-700 dark:text-indigo-300">{{ $vigente ? 'Cambiar la cobertura' : 'Cargar la cobertura' }}</summary>
                    @if ($planes === [])
                        <p class="mt-3 text-gray-600 dark:text-gray-400">No hay planes activos en convenios vigentes. Facturación y convenios los carga en Económico → Convenios.</p>
                    @else
                        <form method="POST" action="{{ route('admin.coberturas.store', $paciente) }}" novalidate class="mt-4 space-y-4">
                            @csrf
                            @if ($vigente)
                                <p class="text-gray-600 dark:text-gray-400">La vigente se cierra el día anterior al inicio de la nueva y queda en el historial.</p>
                            @endif
                            @include('admin.coberturas._campos-cobertura')
                            <x-primary-button>Guardar cobertura</x-primary-button>
                        </form>
                    @endif
                </details>
            @endif

            @if ($coberturas->count() > ($vigente ? 1 : 0))
                <details class="max-w-4xl">
                    <summary class="cursor-pointer text-gray-600 dark:text-gray-400">Historial de coberturas ({{ $coberturas->count() }})</summary>
                    <x-admin.table :headers="['Plan', 'N.º de asegurado', 'Vigencia', 'Estado', 'Cargada por']">
                        @foreach ($coberturas as $cobertura)
                            <tr>
                                <td class="px-6 py-3">{{ $cobertura->plan->nombreCompleto() }}</td>
                                <td class="px-6 py-3">{{ $cobertura->numero_asegurado }} ({{ $cobertura->tipoTexto() }})</td>
                                <td class="px-6 py-3">{{ $cobertura->vigenciaTexto() }}</td>
                                <td class="px-6 py-3"><x-admin.estado-badge :estado="$cobertura->estado" /></td>
                                <td class="px-6 py-3">{{ $cobertura->usuario?->persona?->nombre_completo }}</td>
                                <td></td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                </details>
            @endif
        </section>

        @if ($veVisaciones)
            {{-- Visaciones --}}
            <section @class(['space-y-4', 'order-first' => $turno]) aria-labelledby="titulo-visaciones">
                <h3 id="titulo-visaciones" class="text-base font-semibold text-gray-900 dark:text-gray-100">Visaciones</h3>
                @if ($turno)
                    <p class="rounded-md bg-indigo-50 p-3 font-medium text-indigo-900 dark:bg-indigo-900/30 dark:text-indigo-100">
                        {{ $destinos['Turnos']['turno:'.$turno->id] ?? 'Turno del '.Fecha::mostrar($turno->fecha) }}
                        @if ($volver)
                            · <a href="{{ $volver }}" class="underline">Volver sin guardar</a>
                        @endif
                    </p>
                @endif
                <p class="text-gray-600 dark:text-gray-400">
                    La respuesta de la aseguradora para un turno o una consulta. Al finalizar la consulta, cada procedimiento visado toma la
                    tarifa del convenio y la parte de cada uno; lo que no tiene visación queda particular (con un aviso).
                </p>

                @if ($puedeCargarVisacion)
                    <details @if ($errors->hasAny(['destino', 'numero', 'fecha', 'plan_convenio_id', 'numero_asegurado', 'tipo_afiliado', 'vigente_desde', 'vigente_hasta']) || old('numero') || $destino) open @endif class="max-w-5xl rounded-md border border-gray-200 p-4 dark:border-gray-700">
                        <summary class="cursor-pointer font-medium text-indigo-700 dark:text-indigo-300">Nueva visación</summary>
                        @if (! $vigente && ! $coberturaEnVisacion)
                            <p class="mt-3 text-gray-600 dark:text-gray-400">La paciente no tiene cobertura vigente y {{ $planes === [] ? 'no hay planes activos en convenios vigentes para cargarla.' : 'usted no puede cargarla.' }}</p>
                        @else
                            <form method="POST" action="{{ route('admin.visaciones.store', $paciente) }}" novalidate class="mt-4 space-y-4"
                                x-data="{ resultados: {}, copagos: {} }">
                                @csrf
                                @if ($volver)
                                    <input type="hidden" name="volver" value="{{ $volver }}">
                                @endif
                                @if ($coberturaEnVisacion)
                                    <input type="hidden" name="con_cobertura" value="1">
                                    <fieldset class="space-y-4 rounded-md border border-amber-300 p-4 dark:border-amber-700">
                                        <legend class="px-1 font-medium text-gray-900 dark:text-gray-100">Cobertura de la paciente (todavía no tiene)</legend>
                                        @include('admin.coberturas._campos-cobertura')
                                    </fieldset>
                                @else
                                    <p class="text-gray-600 dark:text-gray-400">
                                        Seguro: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $vigente->plan->nombreCompleto() }}</span>
                                        · asegurado {{ $vigente->numero_asegurado }} ({{ $vigente->tipoTexto() }}) · {{ $vigente->vigenciaTexto() }}
                                        @if ($puedeCargarCobertura)
                                            · <a href="#cambiar-cobertura" x-on:click="document.getElementById('cambiar-cobertura')?.setAttribute('open', '')" class="text-indigo-600 hover:underline dark:text-indigo-400">Cambiar la cobertura</a>
                                        @endif
                                    </p>
                                @endif
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div class="sm:col-span-3">
                                        <x-admin.select name="destino" label="Para el turno o la consulta" :value="$destino" required>
                                            @php($elegido = old('destino', $destino))
                                            @foreach ($destinos as $grupo => $opciones)
                                                @if ($opciones)
                                                    <optgroup label="{{ $grupo }}">
                                                        @foreach ($opciones as $valor => $texto)
                                                            <option value="{{ $valor }}" @selected($elegido === $valor)>{{ $texto }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                            @endforeach
                                        </x-admin.select>
                                    </div>
                                    <x-admin.input name="numero" label="N.º de visación" maxlength="50" required />
                                    <x-admin.fecha name="fecha" label="Fecha de la visación" :value="Fecha::hoy()" />
                                </div>

                                <fieldset class="space-y-2">
                                    <legend class="font-medium text-gray-900 dark:text-gray-100">Procedimientos visados</legend>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Las filas vacías no cuentan. "Aprobado": elija cuánto paga la paciente (nunca más que la tarifa; el resto lo paga la aseguradora). "No cubierto": paga la tarifa general completa.</p>
                                    @for ($i = 0; $i < CoberturaController::LINEAS; $i++)
                                        <div class="grid grid-cols-1 gap-3 rounded-md bg-gray-50 p-3 sm:grid-cols-4 dark:bg-gray-900/40">
                                            <x-admin.select :name="'lineas['.$i.'][procedimiento_id]'" label="Procedimiento" :options="$procedimientos" :value="old('lineas.'.$i.'.procedimiento_id', $i === 0 ? $procedimientoDelTurno : null)" nullable />
                                            <x-admin.select :name="'lineas['.$i.'][resultado]'" label="Resultado" :options="LineaVisacion::RESULTADOS" :value="old('lineas.'.$i.'.resultado')" nullable />
                                            <x-admin.select :name="'lineas['.$i.'][copago]'" label="Paga la paciente" :options="LineaVisacion::COPAGOS" :value="old('lineas.'.$i.'.copago')" nullable />
                                            <div>
                                                <x-admin.input :name="'lineas['.$i.'][valor]'" label="Porcentaje o monto" :value="old('lineas.'.$i.'.valor')" maxlength="20" autocomplete="off" />
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">30 o 30,5 (%), o 100000 (₲).</p>
                                            </div>
                                        </div>
                                    @endfor
                                </fieldset>
                                <x-primary-button>Guardar visación</x-primary-button>
                            </form>
                        @endif
                    </details>
                @endif

                <x-admin.table :headers="['N.º', 'Aseguradora', 'Para', 'Fecha', 'Procedimientos', 'Estado']">
                    @forelse ($visaciones as $visacion)
                        <tr>
                            <td class="px-6 py-3 font-medium">{{ $visacion->numero }}</td>
                            <td class="px-6 py-3">{{ $visacion->responsablePago->persona->nombre_completo }}</td>
                            <td class="px-6 py-3">{{ $visacion->destinoTexto() }}</td>
                            <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($visacion->fecha) }}</td>
                            <td class="px-6 py-3">
                                @foreach ($visacion->lineas as $linea)
                                    <div>{{ $linea->procedimiento->nombre }}: {{ $veMontos ? $linea->textoResultado($moneda) : LineaVisacion::RESULTADOS[$linea->resultado] }}</div>
                                @endforeach
                            </td>
                            <td class="px-6 py-3">
                                <x-admin.estado-badge :estado="$visacion->estado" />
                                @if ($visacion->anulada())
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $visacion->motivo_anulacion }} ({{ $visacion->anuladaPor?->persona?->nombre_completo }})</div>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-right">
                                @if ($visacion->vigente() && $puedeAnularVisacion)
                                    <details class="text-left">
                                        <summary class="cursor-pointer font-medium text-red-600 dark:text-red-400">Anular</summary>
                                        <form method="POST" action="{{ route('admin.visaciones.anular', $visacion) }}" novalidate class="mt-2 w-64 space-y-2">
                                            @csrf
                                            <x-admin.textarea name="motivo_anulacion" label="Motivo" rows="2" />
                                            <x-danger-button>Anular la visación</x-danger-button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-4 text-gray-500 dark:text-gray-400">Todavía no tiene visaciones.</td></tr>
                    @endforelse
                </x-admin.table>
            </section>

            {{-- Prestaciones de aseguradora o con aviso --}}
            <section class="space-y-4" aria-labelledby="titulo-prestaciones">
                <h3 id="titulo-prestaciones" class="text-base font-semibold text-gray-900 dark:text-gray-100">Prestaciones con cobertura</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Las de aseguradora, las que quedaron particulares con un aviso y las que tienen visación en su turno o consulta. "Aplicar
                    visación" recalcula la prestación (solo sin cobro vigente: si ya se cobró, primero se revierte el cobro).
                </p>
                <x-admin.table :headers="['Fecha', 'Procedimiento', 'Pagador', $veMontos ? 'Paciente / aseguradora' : 'Cobro', 'Firma']">
                    @forelse ($prestaciones as $prestacion)
                        <tr class="align-top">
                            <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($prestacion->fecha_servicio) }}</td>
                            <td class="px-6 py-3">
                                {{ $prestacion->procedimiento?->nombre ?? 'Sin procedimiento' }}
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $prestacion->profesional->persona->nombre_completo }}</div>
                            </td>
                            <td class="px-6 py-3">
                                {{ $prestacion->pagadorTexto() }}
                                @if ($prestacion->deAseguradora())
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $prestacion->responsablePago->persona->nombre_completo }} · {{ $prestacion->plan->nombre }} · Visación N.º {{ $prestacion->numero_visacion }}</div>
                                @endif
                                @if ($prestacion->aviso_cobertura)
                                    <div class="text-xs text-amber-700 dark:text-amber-300">{{ $prestacion->aviso_cobertura }}</div>
                                @endif
                                @if ($prestacion->visacion?->anulada())
                                    <div class="text-xs text-amber-700 dark:text-amber-300">La visación N.º {{ $prestacion->visacion->numero }} fue anulada: corrija la prestación a mano.</div>
                                @endif
                            </td>
                            <td class="px-6 py-3 whitespace-nowrap tabular-nums">
                                @if ($veMontos)
                                    {{ $prestacion->montoPacienteTexto() }} / {{ $prestacion->montoAseguradoraTexto() }}
                                @endif
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $prestacion->estadoCobroTexto() }}</div>
                            </td>
                            <td class="px-6 py-3">
                                @if ($prestacion->deAseguradora())
                                    @if ($prestacion->firma_recibida_en)
                                        Recibida el {{ Fecha::mostrar($prestacion->firma_recibida_en, conHora: true) }}
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $prestacion->firmaRecibidaPor?->persona?->nombre_completo }}</div>
                                        @if ($prestacion->lineaLiquidacion)
                                            <div class="text-xs text-gray-500 dark:text-gray-400">Liquidada: no se desmarca.</div>
                                        @elseif ($puedeAplicar)
                                            <details>
                                                <summary class="cursor-pointer text-xs text-red-600 dark:text-red-400">Desmarcar</summary>
                                                <form method="POST" action="{{ route('admin.visaciones.firma.desmarcar', $prestacion) }}" novalidate class="mt-2 w-56 space-y-2">
                                                    @csrf
                                                    <x-admin.textarea name="motivo_firma" label="Motivo" rows="2" />
                                                    <x-secondary-button type="submit">Desmarcar la firma</x-secondary-button>
                                                </form>
                                            </details>
                                        @endif
                                    @elseif ($puedeAplicar)
                                        <form method="POST" action="{{ route('admin.visaciones.firma', $prestacion) }}">
                                            @csrf
                                            <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Firma recibida</button>
                                        </form>
                                    @else
                                        Pendiente
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-6 py-3 text-right">
                                <div class="flex flex-col items-end gap-2">
                                    @if ($puedeTicket && $prestacion->deAseguradora())
                                        <a href="{{ route('admin.visaciones.ticket', $prestacion->consulta_id) }}" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ticket (consulta N.º {{ $prestacion->consulta_id }})</a>
                                    @endif
                                    @if ($puedeAplicar && $prestacion->lineaLiquidacion)
                                        <span class="text-xs text-gray-500 dark:text-gray-400">En una liquidación: su visación, su firma y su monto no cambian.</span>
                                    @elseif ($puedeAplicar && ! $prestacion->cobro_vigente_exists)
                                        <details class="text-left">
                                            <summary class="cursor-pointer font-medium text-indigo-600 dark:text-indigo-400">Aplicar visación</summary>
                                            <form method="POST" action="{{ route('admin.visaciones.aplicar', $prestacion) }}" novalidate class="mt-2 w-64 space-y-2">
                                                @csrf
                                                <x-admin.select name="visacion_id" label="Visación" required>
                                                    @foreach ($prestacion->visaciones_posibles as $posible)
                                                        <option value="{{ $posible->id }}">N.º {{ $posible->numero }}</option>
                                                    @endforeach
                                                    <option value="PARTICULAR">Particular (sin visación)</option>
                                                </x-admin.select>
                                                <x-admin.textarea name="motivo_aplicacion" label="Motivo" rows="2" />
                                                <x-primary-button>Aplicar</x-primary-button>
                                            </form>
                                        </details>
                                    @elseif ($puedeAplicar)
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Cobrada: para aplicar otra visación, primero se revierte el cobro.</span>
                                    @endif
                                    @if (Permisos::puedeRuta('admin.prestaciones.show'))
                                        <a href="{{ route('admin.prestaciones.show', $prestacion) }}" class="text-gray-600 hover:underline dark:text-gray-400">Ver la prestación</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-4 text-gray-500 dark:text-gray-400">No tiene prestaciones con cobertura ni avisos.</td></tr>
                    @endforelse
                </x-admin.table>
            </section>
        @endif
    </div>
</x-admin.page>
