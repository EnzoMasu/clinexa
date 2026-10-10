@use('App\Support\Permisos')
@php
    // Perfiles tildados: los del error de validación, o los que ya tiene el usuario.
    $tildados = array_map('intval', old('perfiles') !== null ? (array) old('perfiles') : ($usuario->exists ? $usuario->perfiles->modelKeys() : []));
@endphp

<x-admin.page :title="$usuario->exists ? 'Editar usuario' : 'Nuevo usuario'">
    @if (! $usuario->exists && ! $hayPersonasDisponibles)
        {{-- Sin personas para elegir: hay que cargar una antes de crear el usuario. --}}
        <div class="p-6 max-w-xl space-y-4">
            <div class="rounded-md bg-amber-50 dark:bg-amber-900/30 p-4 text-sm text-amber-800 dark:text-amber-200 space-y-2">
                <p class="font-medium">No hay personas disponibles para crear un usuario.</p>
                <p>
                    Cada usuario del sistema es una persona ya cargada: física, activa y que todavía no tenga usuario.
                    Todas las personas que cumplen eso ya tienen uno. Primero cargue la persona y después vuelva a crear el usuario.
                </p>
            </div>
            <div class="flex items-center gap-4">
                @if ($url = Permisos::url('admin.personas.create'))
                    <a href="{{ $url }}" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                        Cargar una persona nueva
                    </a>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-400">Su perfil no tiene permiso para cargar personas: solicítelo a un administrador.</p>
                @endif
                <a href="{{ route('admin.usuarios.index') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">Volver</a>
            </div>
        </div>
    @else
        <x-admin.form
            :action="$usuario->exists ? route('admin.usuarios.update', $usuario) : route('admin.usuarios.store')"
            :method="$usuario->exists ? 'PUT' : 'POST'"
            :cancel="route('admin.usuarios.index')">

            @if ($usuario->exists)
                {{-- La persona no se cambia una vez creado el usuario; sus datos se editan en Personas. --}}
                <div class="rounded-md border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                    <dl class="grid gap-3 sm:grid-cols-2 text-sm">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Nombre</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $usuario->persona->nombre_completo }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Documento</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $usuario->persona->tipoDocumento->codigo }} {{ $usuario->persona->nro_documento }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-gray-500 dark:text-gray-400">Correo electrónico</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $usuario->email }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Estos datos son de la persona y no se editan acá.
                        @if ($url = Permisos::url('admin.personas.edit', $usuario->persona))
                            <a href="{{ $url }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">Editar la persona</a>
                        @endif
                    </p>
                </div>
            @else
                <x-admin.selector-persona :url="route('admin.usuarios.personas-disponibles')" con-email
                    ayuda="Solo aparecen personas físicas activas que todavía no tienen usuario. El email del usuario es el de la persona." />
            @endif

            {{-- Perfiles (usuario_perfil): puede tener varios; sus permisos se suman. --}}
            <fieldset class="space-y-2" aria-describedby="perfiles-nota">
                <legend class="font-medium text-sm text-gray-700 dark:text-gray-300">Perfiles de acceso</legend>
                @forelse ($perfiles as $perfil)
                    <label class="flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100">
                        <input type="checkbox" name="perfiles[]" value="{{ $perfil->id }}" @checked(in_array($perfil->id, $tildados, true))
                            class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800">
                        {{ $perfil->nombre }}
                        @unless ($perfil->estaActivo())
                            <x-admin.estado-badge :estado="$perfil->estado" />
                        @endunless
                    </label>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">No hay perfiles de acceso activos.</p>
                @endforelse
                <p id="perfiles-nota" class="text-xs text-gray-500 dark:text-gray-400">
                    Elija al menos uno activo. Los permisos del usuario son la suma de los de sus perfiles activos.
                    Solo puede asignar perfiles cuyos permisos usted ya tiene.
                </p>
                <x-input-error :messages="[...$errors->get('perfiles'), ...$errors->get('perfiles.*')]" />
            </fieldset>

            @if ($usuario->exists)
                <x-admin.select name="estado_id" label="Estado" :options="$usuario::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$usuario->estado_id" required />
            @else
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    No hace falta cargar una contraseña: al guardar, el usuario recibe un email con un link para definirla.
                </p>
            @endif
        </x-admin.form>

        @if ($usuario->exists)
            <div class="p-6 border-t border-gray-200 dark:border-gray-700 max-w-xl space-y-3">
                <h3 class="font-medium text-gray-900 dark:text-gray-100">Contraseña</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Envía a {{ $usuario->email }} un link para definir una contraseña nueva. La actual sigue sirviendo hasta que la cambie.
                </p>
                <form method="POST" action="{{ route('admin.usuarios.invitacion', $usuario) }}">
                    @csrf
                    <x-secondary-button type="submit">Reenviar invitación / restablecer contraseña</x-secondary-button>
                </form>
            </div>
        @endif
    @endif
</x-admin.page>
