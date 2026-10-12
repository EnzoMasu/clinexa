{{--
    Estado del triaje de un turno (sin contenido clínico): "Sin triaje" (sin consulta), "En triaje" o "Con triaje"
    (terminado). Requiere $consulta (o null), con preparada_en. Internamente sigue siendo la "preparación"
    (consultas.preparada_en, estado EN_PREPARACION): ver docs/triaje.md.
--}}
@if (! $consulta)
    <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Sin triaje</span>
@elseif ($consulta->preparada_en)
    <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-200">Con triaje</span>
@else
    <span class="inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800 dark:bg-violet-900/40 dark:text-violet-200">En triaje</span>
@endif
