<x-admin.page :title="$usuario->exists ? 'Editar usuario' : 'Nuevo usuario'">
    <x-admin.form
        :action="$usuario->exists ? route('admin.usuarios.update', $usuario) : route('admin.usuarios.store')"
        :method="$usuario->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.usuarios.index')">
        <x-admin.input name="name" label="Nombre" :value="$usuario->name" maxlength="255" required autofocus />
        <x-admin.input name="email" label="Email" type="email" :value="$usuario->email" maxlength="255" required />
        @if ($usuario->exists && $usuario->is(auth()->user()))
            <div>
                <x-admin.select name="perfil_acceso_id" label="Perfil de acceso" :options="$perfiles->all()" :value="$usuario->perfil_acceso_id"
                    disabled aria-describedby="perfil-propio-nota" class="disabled:opacity-60 disabled:cursor-not-allowed" />
                <p id="perfil-propio-nota" class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    No podés cambiar tu propio perfil de acceso, para no quedarte sin permisos de administración por error.
                    Si hace falta, pedíselo a otro administrador.
                </p>
            </div>
        @else
            <x-admin.select name="perfil_acceso_id" label="Perfil de acceso" :options="$perfiles->all()" :value="$usuario->perfil_acceso_id" required />
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
</x-admin.page>
