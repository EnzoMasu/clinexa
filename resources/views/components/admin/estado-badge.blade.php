{{-- Badge de estado: muestra Estado.nombre; el color sale del código (ACTIVO verde, BLOQUEADO rojo, el resto gris). --}}
@props(['estado'])

@php($codigo = $estado?->codigo)

<span @class([
    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' => $codigo === 'ACTIVO',
    'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300' => $codigo === 'BLOQUEADO',
    'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => ! in_array($codigo, ['ACTIVO', 'BLOQUEADO']),
])>{{ $estado?->nombre ?? '—' }}</span>
