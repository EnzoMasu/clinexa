{{--
    Enlaces al PDF de triaje, al de la consulta y al de sus indicaciones generales (docs/pdf-clinico.md,
    docs/indicaciones-generales.md), cada uno solo para quien puede generarlo
    (ConsultaPolicy::generarPdf). Se generan al vuelo: se abren en otra pestaña y no se guardan. Requiere $consulta.
--}}
@foreach (['triaje' => 'PDF de triaje', 'consulta' => 'PDF de la consulta', 'indicaciones' => 'PDF de indicaciones'] as $documento => $texto)
    @can('generarPdf', [$consulta, $documento])
        <a href="{{ route('admin.pdf.'.$documento, $consulta) }}" target="_blank" rel="noopener" data-pdf="{{ $documento }}"
            class="text-sm text-gray-600 hover:underline dark:text-gray-400">{{ $texto }}</a>
    @endcan
@endforeach
