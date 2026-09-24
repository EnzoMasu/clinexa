@props(['estado'])

<span @class([
    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' => $estado === 'ACTIVO',
    'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => $estado !== 'ACTIVO',
])>{{ $estado }}</span>
