{{--
    Barra "Documentos de la consulta": todas las acciones de documentos en un solo lugar, con botones. Cada uno se
    muestra solo con el permiso (y la regla) que ya exigía esa función; no cambia lo que abre. Sin montos.
    Requiere $consulta. Opcionales: $enPantalla (la pantalla de atención: Indicaciones e Informe de estudio abren su
    panel), $puedeCrearReceta. Las pantallas que se abren van en otra pestaña (la atención sigue en la suya).
--}}
@php
    $botonDoc = 'inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700';
    $enPantalla = $enPantalla ?? false;
    $puedeCrearReceta = $puedeCrearReceta ?? false;
    $enContenido = $enContenido ?? false;
@endphp
<nav class="basis-full space-y-1" aria-label="Documentos de la consulta" data-documentos-consulta>
    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Documentos de la consulta</h4>
    <div class="flex flex-wrap items-center gap-2">
        @if ($puedeCrearReceta)
            <a href="{{ route('admin.recetas.create', $consulta) }}" target="_blank" rel="noopener noreferrer" data-documento="receta" class="{{ $botonDoc }}">Receta</a>
        @endif
        @can('verDeConsulta', [App\Models\OrdenEstudio::class, $consulta])
            <a href="{{ route('admin.ordenes-estudio.consulta', $consulta) }}" target="_blank" rel="noopener" data-documento="pedir-estudio" data-orden-estudio class="{{ $botonDoc }}">Pedir estudio</a>
        @endcan
        @if ($enPantalla)
            <button type="button" x-on:click="ver('estudios')" data-documento="informe-estudio" class="{{ $botonDoc }}">Informe de estudio</button>
        @endif
        @can('verDeConsulta', [App\Models\ReposoMedico::class, $consulta])
            <a href="{{ route('admin.reposos.consulta', $consulta) }}" target="_blank" rel="noopener" data-documento="reposo" data-reposo-medico class="{{ $botonDoc }}">Reposo médico</a>
        @endcan
        @if ($enPantalla)
            <button type="button" x-on:click="ver('indicaciones')" data-documento="indicaciones" class="{{ $botonDoc }}">Indicaciones</button>
        @endif
        {{-- Consulta cerrada (solo lectura): los dos llevan a lo ya cargado, si hay algo. --}}
        @if (! $enPantalla && $enContenido ?? false)
            @if ($consulta->informesEstudio->isNotEmpty())
                <a href="#informes-estudio-consulta" data-documento="informe-estudio" class="{{ $botonDoc }}">Informe de estudio</a>
            @endif
            @if ($consulta->indicaciones->isNotEmpty())
                <a href="#indicaciones-consulta" data-documento="indicaciones" class="{{ $botonDoc }}">Indicaciones</a>
            @endif
        @endif
        @foreach (['triaje' => 'PDF de triaje', 'consulta' => 'PDF de la consulta', 'indicaciones' => 'PDF de indicaciones'] as $documento => $texto)
            @can('generarPdf', [$consulta, $documento])
                <a href="{{ route('admin.pdf.'.$documento, $consulta) }}" target="_blank" rel="noopener" data-pdf="{{ $documento }}" class="{{ $botonDoc }}">{{ $texto }}</a>
            @endcan
        @endforeach
    </div>
</nav>
