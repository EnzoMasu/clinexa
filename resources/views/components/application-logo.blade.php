{{--
    Logo de la clínica. "alto" es la clase de altura de Tailwind (el ancho se ajusta solo).
    El PNG tiene fondo transparente y trazos oscuros: en modo oscuro va sobre una placa clara para que se lea.
--}}
@props(['alto' => 'h-12'])

<img src="{{ asset('images/logo-plenitud-mujer.png') }}" alt="Plenitud Mujer"
    {{ $attributes->merge(['class' => "{$alto} w-auto dark:bg-white dark:rounded-md dark:p-0.5"]) }}>
