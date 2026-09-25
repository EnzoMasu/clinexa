@use('App\Support\Permisos')

<x-admin.page :title="$usuario->exists ? 'Editar usuario' : 'Nuevo usuario'">
    @if (! $usuario->exists && $personasDisponibles->isEmpty())
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
                            <dt class="text-gray-500 dark:text-gray-400">Email</dt>
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
                <x-admin.select name="persona_id" label="Persona" required autofocus aria-describedby="persona-nota">
                    @foreach ($personasDisponibles as $persona)
                        <option value="{{ $persona->id }}" @selected((string) old('persona_id') === (string) $persona->id)>
                            {{ $persona->nombre_completo }} — {{ $persona->tipoDocumento->codigo }} {{ $persona->nro_documento }} ({{ $persona->email }})
                        </option>
                    @endforeach
                </x-admin.select>
                <p id="persona-nota" class="-mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Solo aparecen personas físicas activas que todavía no tienen usuario. El email del usuario es el de la persona.
                </p>
            @endif

            @if ($usuario->exists && $usuario->is(auth()->user()))
                <div>
                    <x-admin.select name="perfil_acceso_id" label="Perfil de acceso" :options="$perfiles" :value="$usuario->perfil_acceso_id"
                        disabled aria-describedby="perfil-propio-nota" class="disabled:opacity-60 disabled:cursor-not-allowed" />
                    <p id="perfil-propio-nota" class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        No puede cambiar su propio perfil de acceso, para no quedarse sin permisos de administración por error.
                        Si hace falta, solicítelo a otro administrador.
                    </p>
                </div>
            @else
                <x-admin.select name="perfil_acceso_id" label="Perfil de acceso" :options="$perfiles" :value="$usuario->perfil_acceso_id" required />
            @endif

            @if ($usuario->exists)
                <x-admin.select name="estado" label="Estado" :options="['ACTIVO', 'BLOQUEADO', 'INACTIVO']" :value="$usuario->estado" required />
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
