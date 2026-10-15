<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>{{ $titulo }}</title>@include('pdf-clinico._estilos')</head>
<body>
    @include('pdf-clinico._encabezado')

    <h2>Motivo de consulta</h2>
    <p>{!! $consulta->motivo_consulta ? nl2br(e($consulta->motivo_consulta)) : '—' !!}</p>

    @include('pdf-clinico._secciones')

    @if ($examen?->hallazgos)
        <h2>Hallazgos</h2>
        <p>{!! nl2br(e($examen->hallazgos)) !!}</p>
        @if ($texto = \App\Support\Atencion\DatosFormulario::autoria($examen->hallazgosUsuario))
            <div class="autoria">{{ $texto }}</div>
        @endif
    @endif

    @if ($diagnosticos->isNotEmpty())
        <h2>Diagnósticos</h2>
        <ul>
            @foreach ($diagnosticos as $diagnostico)
                <li>
                    {{ $diagnostico->codigoYDescripcion() }} · {{ $diagnostico->tipo->etiqueta() }}
                    @if ($diagnostico->principal) <span class="marca">Principal</span>@endif
                    @unless ($diagnostico->activo) <span class="marca">Retirado</span>@endunless
                    @if ($diagnostico->descripcion_adicional)<br>{!! nl2br(e($diagnostico->descripcion_adicional)) !!}@endif
                </li>
            @endforeach
        </ul>
    @endif
</body>
</html>
