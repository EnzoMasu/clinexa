@use('App\Support\Permisos')
@use('App\Support\Facturacion\EmisorSimulado')

{{-- Pestañas de Facturas (docs/facturacion.md): cada una para quien tiene su permiso. Con la simulación activa, la marca. --}}
@php
    $pestanas = array_filter([
        'facturas' => Permisos::puedeRuta('admin.facturas.index') ? ['Facturas', route('admin.facturas.index')] : null,
        'solicitudes' => Permisos::puedeRuta('admin.facturas.solicitudes')
            ? ['Solicitudes de anulación ('.App\Models\SolicitudAnulacionFactura::query()->pendientes()->count().')', route('admin.facturas.solicitudes')] : null,
        'timbrados' => Permisos::puedeRuta('admin.facturas.timbrados') ? ['Timbrados', route('admin.facturas.timbrados')] : null,
        'tasas' => Permisos::puedeRuta('admin.facturas.tasas-iva') ? ['Tasas de IVA', route('admin.facturas.tasas-iva')] : null,
        'motivos' => Permisos::puedeRuta('admin.facturas.motivos') ? ['Motivos de anulación', route('admin.facturas.motivos')] : null,
    ]);
@endphp
<nav class="flex flex-wrap gap-4 border-b border-gray-200 px-4 pt-4 text-sm dark:border-gray-700" aria-label="Vistas de Facturas">
    @foreach ($pestanas as $clave => [$texto, $url])
        <a href="{{ $url }}" @if ($activa === $clave) aria-current="page" @endif
            @class([
                '-mb-px border-b-2 px-1 pb-2 font-medium',
                'border-indigo-500 text-indigo-700 dark:text-indigo-300' => $activa === $clave,
                'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200' => $activa !== $clave,
            ])>{{ $texto }}</a>
    @endforeach
</nav>
@include('admin.facturas._simulacion')
