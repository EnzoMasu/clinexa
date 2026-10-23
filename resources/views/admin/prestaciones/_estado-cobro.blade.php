{{-- Estado de cobro de una prestación (Cobrada / Pendiente / A cargo de aseguradora), derivado de si tiene un cobro vigente y de la
     parte de la paciente (docs/cobros-caja.md, docs/convenios-seguros.md). --}}
@if ($estadoCobro !== '')
    <span @class([
        'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
        'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' => $estadoCobro === \App\Models\Prestacion::COBRADA,
        'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-300' => $estadoCobro === \App\Models\Prestacion::A_CARGO_DE_ASEGURADORA,
        'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300' => $estadoCobro === \App\Models\Prestacion::PENDIENTE_DE_COBRO,
    ])>{{ $estadoCobro }}</span>
@else
    <span class="text-gray-500 dark:text-gray-400">—</span>
@endif
