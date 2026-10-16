{{--
    Enlace a las órdenes de estudio de la consulta (docs/ordenes-estudio.md), solo para quien puede verlas
    (OrdenEstudioPolicy::verDeConsulta). Se abre en otra pestaña: con la consulta abierta, la atención sigue en la suya.
    Requiere $consulta.
--}}
@can('verDeConsulta', [App\Models\OrdenEstudio::class, $consulta])
    <a href="{{ route('admin.ordenes-estudio.consulta', $consulta) }}" target="_blank" rel="noopener" data-orden-estudio
        class="text-sm text-gray-600 hover:underline dark:text-gray-400">Órdenes de estudio</a>
@endcan
