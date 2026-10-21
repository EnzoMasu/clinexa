{{-- Alta y edición de un equipo (EquipoController). Un procedimiento usa como máximo un equipo activo. --}}
@php
    $tildados = array_map('intval', old('con_procedimientos') ? (array) old('procedimientos', []) : $equipo->procedimientos->modelKeys());
@endphp

<x-admin.page :title="$equipo->exists ? 'Editar equipo' : 'Nuevo equipo'">
    <x-admin.form
        :action="$equipo->exists ? route('admin.equipos.update', $equipo) : route('admin.equipos.store')"
        :method="$equipo->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.equipos.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$equipo->nombre" unico="equipo.nombre" :unico-ignorar="$equipo->id" maxlength="150" required autofocus />

        <div>
            <x-admin.select name="tipo_equipo_id" label="Tipo de equipo" :options="$tipos" :value="$equipo->tipo_equipo_id" required />
            @if ($tipos === [])
                <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">No hay tipos de equipo cargados. Cárguelos en Catálogos, Tipos de equipo.</p>
            @endif
        </div>

        <div>
            <x-admin.select name="proveedor_id" label="Dueño (proveedor)" :options="$duenos" :value="$equipo->proveedor_id" required />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Recibe el porcentaje del acuerdo del equipo. Normalmente, un proveedor de la categoría "Equipos médicos".</p>
        </div>

        <fieldset class="space-y-2">
            <legend class="font-medium text-sm text-gray-700 dark:text-gray-300">Procedimientos que lo usan</legend>
            <input type="hidden" name="con_procedimientos" value="1">
            @forelse ($procedimientos as $procedimiento)
                <label class="flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100">
                    <input type="checkbox" name="procedimientos[]" value="{{ $procedimiento->id }}" @checked(in_array($procedimiento->id, $tildados, true))
                        class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800">
                    {{ $procedimiento->nombre }} <span class="text-xs text-gray-500 dark:text-gray-400">({{ $procedimiento->codigo }})</span>
                    @if (isset($enUso[$procedimiento->id]))
                        <span class="text-xs text-amber-700 dark:text-amber-300">ya usa el equipo {{ $enUso[$procedimiento->id] }}</span>
                    @endif
                    @unless ($procedimiento->estaActivo())
                        <x-admin.estado-badge :estado="$procedimiento->estado" />
                    @endunless
                </label>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No hay procedimientos cargados.</p>
            @endforelse
            <x-input-error :messages="[...$errors->get('procedimientos'), ...$errors->get('procedimientos.*')]" />
        </fieldset>

        @if ($equipo->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$equipo::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$equipo->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
