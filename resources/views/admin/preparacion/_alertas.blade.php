{{--
    Pastillas clínicas de una fila de lista (Consulta y Triaje): URGENTE (rojo) si el triaje la marcó y "Signos
    alterados" (ámbar) si algún signo quedó fuera de lo normal. Información clínica: solo en pantallas que exigen
    HISTORIA_CLINICA o el permiso de Triaje, nunca en la agenda. Sin la observación. Requiere $consulta (o null),
    con triaje_urgente y con_alertas.
--}}
@if ($consulta?->triaje_urgente)
    <span data-pastilla="urgente" class="inline-flex rounded-full bg-red-700 px-2 py-0.5 text-xs font-bold text-white">URGENTE</span>
@endif
@if ($consulta?->con_alertas)
    <span data-pastilla="signos" class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900 dark:bg-amber-900/50 dark:text-amber-200">Signos alterados</span>
@endif
