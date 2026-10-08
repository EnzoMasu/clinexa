@php
    use App\Enums\TipoDiagnostico;
    use App\Models\CatalogoCIE10;
    use App\Models\ExamenFisico;
    use App\Models\Persona;
    use App\Support\BuscadorPersonas;
    use App\Support\Fecha;

    $paciente = $historia->paciente;
    $edad = $paciente->persona->fecha_nacimiento ? (int) $paciente->persona->fecha_nacimiento->diffInYears(Fecha::hoy()) : null;

    // Anamnesis: las filas del error de validación, o las guardadas en su orden. De cada fila guardada,
    // su tipo original: si hoy está inactivo, se ofrece solo en esa fila.
    $bloquesGuardados = $consulta->exists ? $consulta->bloquesAnamnesis->keyBy('id') : collect();
    $filasAnamnesis = collect(old('con_anamnesis')
            ? (array) old('anamnesis', [])
            : $bloquesGuardados->sortBy(['orden', 'id'])->map(fn ($bloque) => $bloque->only(['id', 'tipo_bloque_anamnesis_id', 'contenido', 'activo']))->all())
        ->map(function ($fila) use ($bloquesGuardados, $tiposBloqueActivos) {
            $guardado = filled($fila['id'] ?? null) ? $bloquesGuardados->get((int) $fila['id']) : null;
            $tipoOriginal = $guardado?->tipoBloqueAnamnesis;

            return [
                'uid' => uniqid('', true),
                'id' => $guardado ? (string) $guardado->id : '',
                'tipo_bloque_anamnesis_id' => (string) ($fila['tipo_bloque_anamnesis_id'] ?? ''),
                'contenido' => (string) ($fila['contenido'] ?? ''),
                'activo' => filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'tipoInactivo' => $tipoOriginal && ! $tiposBloqueActivos->has($tipoOriginal->id) ? ['id' => (string) $tipoOriginal->id, 'nombre' => $tipoOriginal->nombre.' (inactivo)'] : null,
            ];
        })->values()->all();
    $tiposActivos = $tiposBloqueActivos->map(fn ($nombre, $id) => ['id' => (string) $id, 'nombre' => $nombre])->values()->all();

    // Diagnósticos: los del error de validación (el principal es el índice marcado), o los guardados.
    $diagnosticosGuardados = $consulta->exists ? $consulta->diagnosticos->keyBy('id') : collect();
    $principalAnterior = (string) old('diagnostico_principal');
    $filasDiagnosticos = collect(old('con_diagnosticos')
            ? collect((array) old('diagnosticos', []))->map(fn ($fila, $indice) => [...(array) $fila, 'principal' => (string) $indice === $principalAnterior])->all()
            : $diagnosticosGuardados->sortBy('id')->map(fn ($diagnostico) => [...$diagnostico->only(['id', 'codigo_cie10', 'descripcion_adicional', 'activo', 'principal']), 'tipo' => $diagnostico->tipo->value])->all());
    $descripciones = CatalogoCIE10::whereIn('codigo', $filasDiagnosticos->pluck('codigo_cie10')->filter()->unique()->values())->pluck('descripcion', 'codigo');
    $filasDiagnosticos = $filasDiagnosticos->map(fn ($fila) => [
        'uid' => uniqid('', true),
        'id' => filled($fila['id'] ?? null) && $diagnosticosGuardados->has((int) $fila['id']) ? (string) $fila['id'] : '',
        'codigo_cie10' => (string) ($fila['codigo_cie10'] ?? ''),
        'texto' => filled($fila['codigo_cie10'] ?? null) ? $fila['codigo_cie10'].' — '.($descripciones[$fila['codigo_cie10']] ?? '') : '',
        'tipo' => (string) ($fila['tipo'] ?? TipoDiagnostico::PRESUNTIVO->value),
        'descripcion_adicional' => (string) ($fila['descripcion_adicional'] ?? ''),
        'activo' => filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'principal' => (bool) ($fila['principal'] ?? false),
    ])->values()->all();
    $tiposDiagnostico = collect(TipoDiagnostico::cases())->map(fn ($tipo) => ['valor' => $tipo->value, 'nombre' => $tipo->etiqueta()])->all();

    // Examen físico: lo cargado tras un error, o lo guardado (decimales con coma).
    $examen = $consulta->examenFisico;
    $decimalConComa = fn ($valor) => $valor === null ? null
        : (str_contains((string) $valor, '.') ? str_replace('.', ',', rtrim(rtrim((string) $valor, '0'), '.')) : (string) $valor); // 36.50 -> 36,5 ; 170.0 -> 170
    $valorExamen = fn (string $campo) => old("examen.{$campo}", $decimalConComa($examen?->{$campo}));
    $valorExamenTexto = fn (string $campo) => old("examen.{$campo}", $examen?->{$campo});

    $erroresAnamnesis = collect($errors->get('anamnesis*'))->flatten()->unique();
    $erroresDiagnosticos = collect([...$errors->get('diagnosticos*'), ...$errors->get('diagnostico_principal')])->flatten()->unique();
    $clases = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm';
@endphp

<x-admin.page :title="$consulta->exists ? 'Editar consulta' : 'Nueva consulta'">
    <x-admin.form
        :action="$consulta->exists ? route('admin.consultas.update', $consulta) : route('admin.consultas.store', $historia)"
        :method="$consulta->exists ? 'PUT' : 'POST'"
        :cancel="$consulta->exists ? route('admin.consultas.show', $consulta) : route('admin.historias-clinicas.show', $historia)"
        width="max-w-5xl">
        @if ($consulta->exists)
            {{-- Control de concurrencia: si otra ventana guardó después de abrir esta, se rechaza. --}}
            <input type="hidden" name="version" value="{{ $consulta->version() }}">
        @elseif ($turno)
            <input type="hidden" name="turno_id" value="{{ $turno->id }}">
        @endif

        {{-- Fijos: paciente, profesional y (si viene de "Atender") el turno. --}}
        <dl class="grid gap-4 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-700 dark:bg-gray-900/40 sm:grid-cols-3">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Paciente</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $paciente->persona->nombre_completo }}</dd>
                <dd class="text-xs text-gray-500 dark:text-gray-400">
                    Ficha {{ $paciente->nro_ficha }} · {{ $paciente->persona->tipoDocumento->codigo }} {{ $paciente->persona->nro_documento }}
                    @if ($edad !== null) · {{ $edad }} {{ $edad === 1 ? 'año' : 'años' }} @endif
                    @if (isset(Persona::SEXOS[$paciente->persona->sexo])) · {{ Persona::SEXOS[$paciente->persona->sexo] }} @endif
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Profesional</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $profesional->persona->nombre_completo }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ $consulta->exists ? 'Fecha y hora' : 'Turno' }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    @if ($consulta->exists)
                        {{ $consulta->fechaHoraTexto() }}
                    @elseif ($turno)
                        {{ Fecha::mostrar($turno->fecha) }}, {{ substr($turno->hora_inicio, 0, 5) }} – {{ substr($turno->hora_fin, 0, 5) }}
                    @else
                        Sin turno (urgencia)
                    @endif
                </dd>
            </div>
        </dl>

        <x-admin.textarea name="motivo_consulta" label="Motivo de consulta" :value="$consulta->motivo_consulta" rows="3" maxlength="500" required autofocus />

        {{-- Anamnesis (bloques_anamnesis): no se borran; un bloque guardado se retira y se repone. --}}
        <div x-data="{
                filas: {{ Js::from($filasAnamnesis) }},
                tiposActivos: {{ Js::from($tiposActivos) }},
                // Los tipos activos, más el inactivo que esta fila ya tenía.
                tiposPara(fila) { return fila.tipoInactivo ? [...this.tiposActivos, fila.tipoInactivo] : this.tiposActivos },
                agregar() { this.filas.push({ uid: Date.now() + Math.random(), id: '', tipo_bloque_anamnesis_id: '', contenido: '', activo: true, tipoInactivo: null }) },
                descartar(i) { this.filas.splice(i, 1) },
            }" class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Anamnesis</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Hasta {{ \App\Http\Controllers\Admin\ConsultaController::MAXIMO_BLOQUES }} bloques vigentes, en el orden en que se muestran.
                Un bloque guardado no se borra: se retira, y se puede reponer. Los cambios se aplican al guardar.
            </p>

            {{-- Solo con JavaScript: indica que la lista viene completa (sin JS no se tocan los bloques guardados). --}}
            <template x-if="true"><input type="hidden" name="con_anamnesis" value="1"></template>

            <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
                <div class="grid gap-3 rounded-md border p-3 sm:grid-cols-[14rem_1fr_auto] sm:items-start"
                    x-bind:class="fila.activo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40'">
                    <input type="hidden" x-bind:name="`anamnesis[${i}][id]`" x-bind:value="fila.id">
                    <input type="hidden" x-bind:name="`anamnesis[${i}][activo]`" x-bind:value="fila.activo ? 1 : 0">
                    <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                        <label x-bind:for="`bloque_tipo_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                            Tipo
                            <span x-show="! fila.activo" class="ms-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Retirado</span>
                        </label>
                        <select x-bind:id="`bloque_tipo_${i}`" x-bind:name="`anamnesis[${i}][tipo_bloque_anamnesis_id]`" x-model="fila.tipo_bloque_anamnesis_id" required class="{{ $clases }}">
                            <option value="">Seleccionar…</option>
                            <template x-for="tipo in tiposPara(fila)" x-bind:key="tipo.id">
                                <option x-bind:value="tipo.id" x-text="tipo.nombre" x-bind:selected="tipo.id === fila.tipo_bloque_anamnesis_id"></option>
                            </template>
                        </select>
                    </div>
                    <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                        <label x-bind:for="`bloque_contenido_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Contenido</label>
                        <textarea rows="3" maxlength="5000" required x-bind:id="`bloque_contenido_${i}`" x-bind:name="`anamnesis[${i}][contenido]`" x-model="fila.contenido" class="{{ $clases }}"></textarea>
                    </div>
                    <div class="sm:mt-7">
                        {{-- Guardado: se retira o se repone. Nuevo (todavía sin guardar): se descarta. --}}
                        <button type="button" x-show="fila.id" x-on:click="fila.activo = ! fila.activo" x-text="fila.activo ? 'Retirar' : 'Reponer'"
                            class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Retirar</button>
                        <button type="button" x-show="! fila.id" x-on:click="descartar(i)"
                            class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Descartar</button>
                    </div>
                </div>
            </template>

            <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin bloques de anamnesis.</p>

            <button type="button" x-on:click="agregar()" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                + Agregar bloque
            </button>

            @if ($erroresAnamnesis->isNotEmpty())
                <x-input-error :messages="$erroresAnamnesis->all()" />
            @endif
        </div>

        {{-- Examen físico: todo opcional. Coma o punto decimal. --}}
        <fieldset class="space-y-3">
            <legend class="font-medium text-gray-900 dark:text-gray-100">Examen físico</legend>
            <div class="grid gap-4 sm:grid-cols-4">
                @foreach (['presion_arterial' => ['120/80', 'text', 10], 'frecuencia_cardiaca' => ['72', 'numeric', 3], 'frecuencia_respiratoria' => ['16', 'numeric', 2], 'temperatura' => ['36,5', 'decimal', 4], 'peso' => ['70,5', 'decimal', 6], 'talla' => ['165', 'decimal', 5], 'saturacion_oxigeno' => ['98', 'numeric', 3]] as $campo => [$ejemplo, $modo, $largo])
                    <div>
                        <label for="examen_{{ $campo }}" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                            {{ ExamenFisico::CAMPOS[$campo][0] }} <span class="text-xs text-gray-500 dark:text-gray-400">({{ ExamenFisico::CAMPOS[$campo][1] }})</span>
                        </label>
                        <input type="text" id="examen_{{ $campo }}" name="examen[{{ $campo }}]" value="{{ $modo === 'text' ? $valorExamenTexto($campo) : $valorExamen($campo) }}"
                            inputmode="{{ $modo === 'text' ? 'text' : $modo }}" maxlength="{{ $largo }}" placeholder="{{ $ejemplo }}" autocomplete="off" class="{{ $clases }}">
                        <x-input-error class="mt-2" :messages="$errors->get('examen.'.$campo)" />
                    </div>
                @endforeach
            </div>
            <div>
                <label for="examen_hallazgos" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Hallazgos</label>
                <textarea id="examen_hallazgos" name="examen[hallazgos]" rows="3" maxlength="2000" class="{{ $clases }}">{{ $valorExamenTexto('hallazgos') }}</textarea>
                <x-input-error class="mt-2" :messages="$errors->get('examen.hallazgos')" />
            </div>
        </fieldset>

        {{-- Diagnósticos CIE-10: no se borran; un diagnóstico guardado se retira y se repone. --}}
        <div x-data="{
                filas: {{ Js::from($filasDiagnosticos) }},
                tipos: {{ Js::from($tiposDiagnostico) }},
                principal: null,
                init() { this.principal = this.filas.find((fila) => fila.principal)?.uid ?? null },
                agregar() {
                    this.filas.push({ uid: Date.now() + Math.random(), id: '', codigo_cie10: '', texto: '', tipo: 'PRESUNTIVO', descripcion_adicional: '', activo: true, principal: false });
                    // El primero vigente queda como principal.
                    if (! this.filas.some((fila) => fila.activo && fila.uid === this.principal)) { this.principal = this.filas[this.filas.length - 1].uid }
                },
                descartar(i) { this.filas.splice(i, 1) },
            }" class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Diagnósticos</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Hasta {{ \App\Http\Controllers\Admin\ConsultaController::MAXIMO_DIAGNOSTICOS }} diagnósticos vigentes, sin repetir el código; uno de ellos es el principal.
                Un diagnóstico guardado no se borra: se retira, y se puede reponer. Los cambios se aplican al guardar.
            </p>

            <template x-if="true"><input type="hidden" name="con_diagnosticos" value="1"></template>

            <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
                <div class="space-y-3 rounded-md border p-3"
                    x-bind:class="fila.activo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40'">
                    <input type="hidden" x-bind:name="`diagnosticos[${i}][id]`" x-bind:value="fila.id">
                    <input type="hidden" x-bind:name="`diagnosticos[${i}][activo]`" x-bind:value="fila.activo ? 1 : 0">
                    <input type="hidden" x-bind:name="`diagnosticos[${i}][codigo_cie10]`" x-bind:value="fila.codigo_cie10">

                    <div class="grid gap-3 sm:grid-cols-[1fr_12rem_auto] sm:items-start" x-bind:class="fila.activo ? '' : 'opacity-60'">
                        {{-- Código CIE-10: buscador (resources/js/buscador-cie10.js). --}}
                        <div x-data="buscadorCie10({ url: @js(route('admin.historias-clinicas.cie10')), minimo: {{ BuscadorPersonas::MINIMO }} })">
                            <label x-bind:for="`diagnostico_buscar_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                                CIE-10
                                <span x-show="! fila.activo" class="ms-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Retirado</span>
                            </label>
                            <div x-show="fila.codigo_cie10" class="mt-1 flex items-center justify-between gap-3 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm dark:border-indigo-800 dark:bg-indigo-900/30">
                                <span class="font-medium text-gray-900 dark:text-gray-100" x-text="fila.texto"></span>
                                <button type="button" x-show="! fila.id" x-on:click="cambiar()" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Cambiar</button>
                            </div>
                            <div x-show="! fila.codigo_cie10">
                                <input type="search" x-bind:id="`diagnostico_buscar_${i}`" x-model="q" x-on:input.debounce.350ms="buscar()"
                                    placeholder="Buscar por código o descripción…" autocomplete="off" class="{{ $clases }}">
                                <ul class="mt-1 max-h-56 overflow-y-auto rounded-md border border-gray-200 divide-y divide-gray-100 dark:border-gray-700 dark:divide-gray-700"
                                    x-show="cargando || buscado" x-bind:aria-busy="cargando.toString()">
                                    <template x-for="cie10 in resultados" x-bind:key="cie10.codigo">
                                        <li>
                                            <button type="button" x-on:click="elegir(cie10)" class="block w-full px-3 py-2 text-left text-sm text-gray-800 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                                                <span class="font-mono font-medium" x-text="cie10.codigo"></span> — <span x-text="cie10.descripcion"></span>
                                            </button>
                                        </li>
                                    </template>
                                    <li x-show="buscado && ! cargando && resultados.length === 0" class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">No hay códigos activos con esa búsqueda.</li>
                                    <li x-show="cargando" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400" role="status">Buscando…</li>
                                </ul>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Escriba al menos {{ BuscadorPersonas::MINIMO }} caracteres. Se muestran hasta {{ BuscadorPersonas::LIMITE }} códigos activos: si no aparece, afine la búsqueda.
                                </p>
                            </div>
                        </div>

                        <div>
                            <label x-bind:for="`diagnostico_tipo_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Tipo</label>
                            <select x-bind:id="`diagnostico_tipo_${i}`" x-bind:name="`diagnosticos[${i}][tipo]`" x-model="fila.tipo" required class="{{ $clases }}">
                                <template x-for="tipo in tipos" x-bind:key="tipo.valor">
                                    <option x-bind:value="tipo.valor" x-text="tipo.nombre" x-bind:selected="tipo.valor === fila.tipo"></option>
                                </template>
                            </select>
                        </div>

                        <div class="flex flex-col gap-2 sm:mt-7">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="radio" name="diagnostico_principal" x-bind:value="i" x-bind:checked="principal === fila.uid" x-on:change="principal = fila.uid"
                                    x-bind:disabled="! fila.activo" class="border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900">
                                Principal
                            </label>
                            <button type="button" x-show="fila.id" x-on:click="fila.activo = ! fila.activo" x-text="fila.activo ? 'Retirar' : 'Reponer'"
                                class="text-left text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Retirar</button>
                            <button type="button" x-show="! fila.id" x-on:click="descartar(i)"
                                class="text-left text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Descartar</button>
                        </div>
                    </div>

                    <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                        <label x-bind:for="`diagnostico_descripcion_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Descripción adicional (opcional)</label>
                        <input type="text" maxlength="500" x-bind:id="`diagnostico_descripcion_${i}`" x-bind:name="`diagnosticos[${i}][descripcion_adicional]`" x-model="fila.descripcion_adicional" class="{{ $clases }}">
                    </div>
                </div>
            </template>

            <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin diagnósticos.</p>

            <button type="button" x-on:click="agregar()" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                + Agregar diagnóstico
            </button>

            @if ($erroresDiagnosticos->isNotEmpty())
                <x-input-error :messages="$erroresDiagnosticos->all()" />
            @endif
        </div>

        <noscript><p class="text-sm text-red-600">Para cargar la anamnesis y los diagnósticos se necesita JavaScript habilitado. Sin JavaScript se guardan el motivo y el examen físico, y lo ya cargado no se pierde.</p></noscript>
    </x-admin.form>
</x-admin.page>
