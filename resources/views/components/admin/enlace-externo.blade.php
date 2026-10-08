{{--
    Un valor cargado por un usuario (sitio web, red social). Solo se arma un enlace si es una URL
    http:// o https:// (App\Support\Enlace::esUrlWeb); si no (un @usuario, un número), va como texto.
    Siempre escapado. Los enlaces abren en otra pestaña sin darle acceso a esta (noopener noreferrer).
    "texto": lo que se muestra en lugar de la URL (p. ej. "Abrir").
--}}
@props(['valor', 'texto' => null])

@if (\App\Support\Enlace::esUrlWeb($valor))
    <a href="{{ $valor }}" target="_blank" rel="noopener noreferrer"
        {{ $attributes->merge(['class' => 'text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 underline-offset-2 hover:underline break-all']) }}>{{ $texto ?? $valor }}</a>
@elseif ($valor !== null && $valor !== '' && $texto === null)
    <span {{ $attributes->merge(['class' => 'break-all']) }}>{{ $valor }}</span>
@endif
