<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>{{ $titulo }}</title>@include('pdf-clinico._estilos')</head>
<body>
    @include('pdf-clinico._encabezado')
    @include('pdf-clinico._secciones')
    @if (! $consulta->triaje_urgente && ! $consulta->triaje_observacion && $bloques->isEmpty() && $signos->isEmpty())
        <p class="vacio">Todavía no hay datos de triaje registrados.</p>
    @endif
</body>
</html>
