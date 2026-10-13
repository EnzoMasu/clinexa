{{--
    Ayuda bajo el buscador de pacientes (Atender sin turno, Registrar urgencia): el paciente debe estar registrado y
    activo, con los enlaces para darlo de alta o reactivarlo según los permisos del usuario.
--}}
@use('App\Support\Permisos')
@php
    // Cada enlace, solo con el permiso de su módulo: CREAR para dar de alta; EDITAR (y VER, que
    // pide el listado) para reactivar un paciente, que se hace editando su estado en Pacientes.
    $urlPersona = Permisos::url('admin.personas.create');
    $urlPaciente = Permisos::url('admin.pacientes.create');
    $urlReactivar = auth()->user()->tienePermiso('PACIENTES', 'EDITAR') ? Permisos::url('admin.pacientes.index') : null;
@endphp
<p class="text-xs text-gray-500 dark:text-gray-400">
    Si el paciente no aparece, puede que todavía no esté registrado o que esté inactivo. Un paciente nuevo se da de alta
    primero en Personas y en Pacientes; uno inactivo se reactiva desde Pacientes.
    @if ($urlPersona)
        <a href="{{ $urlPersona }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Nueva persona</a>
    @endif
    @if ($urlPaciente)
        <a href="{{ $urlPaciente }}" class="ms-2 font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Nuevo paciente</a>
    @endif
    @if ($urlReactivar)
        <a href="{{ $urlReactivar }}" class="ms-2 font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ir a Pacientes</a>
    @endif
</p>
