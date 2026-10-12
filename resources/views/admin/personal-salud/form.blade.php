<x-admin.page :title="$registro->exists ? 'Editar personal de salud' : 'Nuevo personal de salud'">
    <x-admin.form
        :action="$registro->exists ? route('admin.personal-salud.update', $registro) : route('admin.personal-salud.store')"
        :method="$registro->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.personal-salud.index')">
        @if ($registro->exists)
            <x-admin.persona-solo-lectura :persona="$registro->persona" />
        @else
            <x-admin.selector-persona :url="route('admin.personal-salud.personas-disponibles')"
                ayuda="Solo aparecen personas físicas activas que todavía no son personal de salud." />
        @endif

        <x-admin.select name="cargo_salud_id" label="Cargo" :options="$cargos" :value="$registro->cargo_salud_id" required />
        <x-admin.input name="registro_profesional" label="Registro profesional (opcional)" :value="$registro->registro_profesional" maxlength="50" />

        @if ($registro->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$registro::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$registro->estado_id" required />
        @endif
        <p class="text-xs text-gray-600 dark:text-gray-400">
            Con el rol activo, la persona puede cargar el triaje si además su usuario tiene el permiso del módulo Triaje.
        </p>
    </x-admin.form>
</x-admin.page>
