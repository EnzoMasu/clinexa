@php
    use App\Support\Enlace;

    // Categorías tildadas: las del error de validación, o las que ya tiene el proveedor.
    $tildadas = array_map('intval', old('con_categorias') ? (array) old('categorias', []) : $registro->categorias->modelKeys());

    // Contactos: los del error de validación, o los guardados. "guardado": tiene id (se deshabilita, no se descarta).
    $filasContactos = collect(old('con_contactos') ? (array) old('contactos', []) : $contactos->map(fn ($contacto) => $contacto->only(['id', 'nombre', 'apellido', 'telefono', 'correo', 'activo']))->all())
        ->map(fn ($fila) => [
            'uid' => uniqid('', true),
            'id' => filled($fila['id'] ?? null) ? (string) $fila['id'] : '',
            'nombre' => (string) ($fila['nombre'] ?? ''),
            'apellido' => (string) ($fila['apellido'] ?? ''),
            'telefono' => (string) ($fila['telefono'] ?? ''),
            'correo' => (string) ($fila['correo'] ?? ''),
            'activo' => filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ])->values()->all();

    // Redes: las del error de validación, o las guardadas. De cada red guardada: su tipo original (si está
    // inactivo, se ofrece solo en esa fila) y su enlace, para el botón "Abrir" (solo si es http/https).
    $guardadas = $redes->keyBy('id');
    $filasRedes = collect(old('con_redes') ? (array) old('redes', []) : $redes->map(fn ($red) => $red->only(['id', 'tipo_red_social_id', 'enlace']))->all())
        ->map(function ($fila) use ($guardadas, $tiposRedActivos) {
            $guardada = filled($fila['id'] ?? null) ? $guardadas->get((int) $fila['id']) : null;
            $tipoOriginal = $guardada?->tipoRedSocial;

            return [
                'uid' => uniqid('', true),
                'id' => $guardada ? (string) $guardada->id : '',
                'tipo_red_social_id' => (string) ($fila['tipo_red_social_id'] ?? ''),
                'enlace' => (string) ($fila['enlace'] ?? ''),
                'tipoInactivo' => $tipoOriginal && ! $tiposRedActivos->has($tipoOriginal->id) ? ['id' => (string) $tipoOriginal->id, 'nombre' => $tipoOriginal->nombre.' (inactivo)'] : null,
                'enlaceGuardado' => $guardada?->enlace,
                'url' => $guardada && Enlace::esUrlWeb($guardada->enlace) ? $guardada->enlace : null,
            ];
        })->values()->all();
    $tiposActivos = $tiposRedActivos->map(fn ($nombre, $id) => ['id' => (string) $id, 'nombre' => $nombre])->values()->all();

    $erroresContactos = collect($errors->get('contactos*'))->flatten()->unique();
    $erroresRedes = collect($errors->get('redes*'))->flatten()->unique();
    $clases = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm';
@endphp

<x-admin.page :title="$registro->exists ? 'Editar proveedor' : 'Nuevo proveedor'">
    <x-admin.form
        :action="$registro->exists ? route('admin.proveedores.update', $registro) : route('admin.proveedores.store')"
        :method="$registro->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.proveedores.index')"
        width="max-w-4xl">
        @if ($registro->exists)
            <x-admin.persona-solo-lectura :persona="$registro->persona" />
        @else
            <x-admin.selector-persona :url="route('admin.proveedores.personas-disponibles')" />
        @endif

        {{-- Categorías (proveedor_categoria): puede tener varias. --}}
        <fieldset class="space-y-2">
            <legend class="font-medium text-sm text-gray-700 dark:text-gray-300">Categorías</legend>
            <input type="hidden" name="con_categorias" value="1">
            @forelse ($categorias as $categoria)
                <label class="flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100">
                    <input type="checkbox" name="categorias[]" value="{{ $categoria->id }}" @checked(in_array($categoria->id, $tildadas, true))
                        class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800">
                    {{ $categoria->nombre }}
                    @unless ($categoria->estaActivo())
                        <x-admin.estado-badge :estado="$categoria->estado" />
                    @endunless
                </label>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No hay categorías de proveedor cargadas.</p>
            @endforelse
            <x-input-error :messages="[...$errors->get('categorias'), ...$errors->get('categorias.*')]" />
        </fieldset>

        <x-admin.textarea name="condiciones_comerciales" label="Condiciones comerciales (opcional)" :value="$registro->condiciones_comerciales" maxlength="5000" />
        <x-admin.input name="datos_bancarios" label="Datos bancarios (opcional)" :value="$registro->datos_bancarios" maxlength="255"
            placeholder="Banco, tipo y número de cuenta" />

        {{-- Sitio web de la empresa. Sin http:// ni https:// se completa con https:// al guardar. --}}
        <div>
            <x-admin.input name="sitio_web" label="Sitio web (opcional)" :value="$registro->sitio_web" maxlength="255"
                placeholder="clinica.com.py" inputmode="url" autocomplete="off" />
            @if ($registro->sitio_web)
                <p class="mt-1 text-xs"><x-admin.enlace-externo :valor="$registro->sitio_web" texto="Abrir el sitio web" /></p>
            @endif
        </div>

        {{-- Contactos (contactos_proveedor): no se borran; un contacto guardado se deshabilita. --}}
        <div x-data="{
                filas: {{ Js::from($filasContactos) }},
                agregar() { this.filas.push({ uid: Date.now() + Math.random(), id: '', nombre: '', apellido: '', telefono: '', correo: '', activo: true }) },
                descartar(i) { this.filas.splice(i, 1) },
            }" class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Contactos</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Personas de contacto del proveedor (opcional). Indique al menos un teléfono o un correo electrónico. Un contacto guardado
                no se borra: se deshabilita. Los cambios se aplican al guardar.
            </p>

            {{-- Solo con JavaScript: indica que la lista viene completa (sin JS no se tocan los contactos guardados). --}}
            <template x-if="true"><input type="hidden" name="con_contactos" value="1"></template>

            <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
                <div class="grid gap-3 rounded-md border p-3 sm:grid-cols-[1fr_1fr_1fr_1.4fr_auto] sm:items-end"
                    x-bind:class="fila.activo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40'">
                    <input type="hidden" x-bind:name="`contactos[${i}][id]`" x-bind:value="fila.id">
                    <input type="hidden" x-bind:name="`contactos[${i}][activo]`" x-bind:value="fila.activo ? 1 : 0">
                    <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                        <label x-bind:for="`contacto_nombre_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                            Nombre
                            <span x-show="! fila.activo" class="ms-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Deshabilitado</span>
                        </label>
                        <input type="text" maxlength="100" required x-bind:id="`contacto_nombre_${i}`" x-bind:name="`contactos[${i}][nombre]`" x-model="fila.nombre" class="{{ $clases }}">
                    </div>
                    <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                        <label x-bind:for="`contacto_apellido_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Apellido</label>
                        <input type="text" maxlength="100" required x-bind:id="`contacto_apellido_${i}`" x-bind:name="`contactos[${i}][apellido]`" x-model="fila.apellido" class="{{ $clases }}">
                    </div>
                    <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                        <label x-bind:for="`contacto_telefono_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Teléfono</label>
                        <input type="tel" maxlength="20" x-bind:id="`contacto_telefono_${i}`" x-bind:name="`contactos[${i}][telefono]`" x-model="fila.telefono" class="{{ $clases }}">
                    </div>
                    <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                        <label x-bind:for="`contacto_correo_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Correo electrónico</label>
                        <input type="email" maxlength="100" x-bind:id="`contacto_correo_${i}`" x-bind:name="`contactos[${i}][correo]`" x-model="fila.correo" class="{{ $clases }}">
                    </div>
                    <div class="sm:mb-2">
                        {{-- Guardado: se deshabilita o habilita. Nuevo (todavía sin guardar): se descarta. --}}
                        <button type="button" x-show="fila.id" x-on:click="fila.activo = ! fila.activo" x-text="fila.activo ? 'Deshabilitar' : 'Habilitar'"
                            class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Deshabilitar</button>
                        <button type="button" x-show="! fila.id" x-on:click="descartar(i)"
                            class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Descartar</button>
                    </div>
                </div>
            </template>

            <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin contactos cargados.</p>

            <button type="button" x-on:click="agregar()" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                + Agregar contacto
            </button>

            @if ($erroresContactos->isNotEmpty())
                <x-input-error :messages="$erroresContactos->all()" />
            @endif
        </div>

        {{-- Redes sociales de la empresa (redes_sociales_proveedor): se pueden quitar. --}}
        <div x-data="{
                filas: {{ Js::from($filasRedes) }},
                tiposActivos: {{ Js::from($tiposActivos) }},
                // Los tipos activos, más el inactivo que esta fila ya tenía asignado.
                tiposPara(fila) { return fila.tipoInactivo ? [...this.tiposActivos, fila.tipoInactivo] : this.tiposActivos },
                agregar() { this.filas.push({ uid: Date.now() + Math.random(), id: '', tipo_red_social_id: '', enlace: '', tipoInactivo: null, enlaceGuardado: null, url: null }) },
                quitar(i) { this.filas.splice(i, 1) },
            }" class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Redes sociales</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Un enlace (https://…), un @usuario o un número (por ejemplo, de WhatsApp). Los cambios se aplican al guardar.
            </p>

            <template x-if="true"><input type="hidden" name="con_redes" value="1"></template>

            <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
                <div class="grid gap-3 rounded-md border border-gray-200 p-3 dark:border-gray-700 sm:grid-cols-[1fr_2fr_auto] sm:items-end">
                    <input type="hidden" x-bind:name="`redes[${i}][id]`" x-bind:value="fila.id">
                    <div>
                        <label x-bind:for="`red_tipo_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Tipo</label>
                        <select x-bind:id="`red_tipo_${i}`" x-bind:name="`redes[${i}][tipo_red_social_id]`" x-model="fila.tipo_red_social_id" required class="{{ $clases }}">
                            <option value="">Seleccionar…</option>
                            <template x-for="tipo in tiposPara(fila)" x-bind:key="tipo.id">
                                <option x-bind:value="tipo.id" x-text="tipo.nombre" x-bind:selected="tipo.id === fila.tipo_red_social_id"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label x-bind:for="`red_enlace_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Enlace o usuario</label>
                        <input type="text" maxlength="255" required x-bind:id="`red_enlace_${i}`" x-bind:name="`redes[${i}][enlace]`" x-model="fila.enlace"
                            autocomplete="off" class="{{ $clases }}">
                    </div>
                    <div class="flex gap-4 sm:mb-2">
                        {{-- "Abrir": solo para una red guardada cuyo enlace es http/https (lo decide el servidor) y no se modificó. --}}
                        <a x-show="fila.url && fila.enlace === fila.enlaceGuardado" x-bind:href="fila.url" target="_blank" rel="noopener noreferrer"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Abrir</a>
                        <button type="button" x-on:click="quitar(i)" class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Quitar</button>
                    </div>
                </div>
            </template>

            <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin redes sociales cargadas.</p>

            <button type="button" x-on:click="agregar()" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                + Agregar red social
            </button>

            @if ($erroresRedes->isNotEmpty())
                <x-input-error :messages="$erroresRedes->all()" />
            @endif
        </div>

        @if ($registro->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$registro::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$registro->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
