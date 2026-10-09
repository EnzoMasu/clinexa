{{--
    Badge de estado: muestra Estado.nombre; el color sale del código. ACTIVO verde, BLOQUEADO rojo;
    flujo de atención: EN CONSULTA y EN CURSO azul, SALTADO ámbar, EN PREPARACIÓN violeta, FINALIZADO
    verde azulado; el resto gris.
--}}
@props(['estado'])

@php($codigo = $estado?->codigo)

<span @class([
    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' => $codigo === 'ACTIVO',
    'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300' => $codigo === 'BLOQUEADO',
    'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300' => in_array($codigo, ['EN_CONSULTA', 'EN_CURSO']),
    'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300' => $codigo === 'SALTADO',
    'bg-violet-100 text-violet-800 dark:bg-violet-900/50 dark:text-violet-300' => $codigo === 'EN_PREPARACION',
    'bg-teal-100 text-teal-800 dark:bg-teal-900/50 dark:text-teal-300' => $codigo === 'FINALIZADO',
    'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => ! in_array($codigo, ['ACTIVO', 'BLOQUEADO', 'EN_CONSULTA', 'EN_CURSO', 'SALTADO', 'EN_PREPARACION', 'FINALIZADO']),
])>{{ $estado?->nombre ?? '—' }}</span>
