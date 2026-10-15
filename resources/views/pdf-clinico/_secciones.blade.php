{{-- Secciones registradas, tal cual (sin interpretar). Salen solo las que tienen contenido. Todo escapado. --}}
@use('App\Support\Atencion\DatosFormulario')
@use('App\Support\Atencion\EvaluadorSignos')
@use('App\Support\Fecha')

@if ($consulta->triaje_urgente || $consulta->triaje_observacion)
    <h2>Urgente y observación del triaje</h2>
    @if ($consulta->triaje_urgente)
        <p><strong>URGENTE</strong>@if ($urgentePor) · marcado por {{ $urgentePor }}@endif @if ($consulta->triaje_urgente_en) · {{ Fecha::mostrar($consulta->triaje_urgente_en, conHora: true) }}@endif</p>
    @endif
    @if ($consulta->triaje_observacion)
        <p>{!! nl2br(e($consulta->triaje_observacion)) !!}</p>
    @endif
@endif

@if ($bloques->isNotEmpty())
    <h2>Anamnesis</h2>
    <ul>
        @foreach ($bloques as $bloque)
            <li>
                <strong>{{ $bloque->tipoBloqueAnamnesis->nombre }}</strong>@unless ($bloque->activo) <span class="marca">Retirado</span>@endunless<br>
                {!! nl2br(e($bloque->contenido)) !!}
                @if ($texto = DatosFormulario::autoria($bloque->usuario, $bloque->modificadoPor))
                    <div class="autoria">{{ $texto }}</div>
                @endif
            </li>
        @endforeach
    </ul>
@endif

@if ($signos->isNotEmpty())
    <h2>Signos vitales</h2>
    <ul>
        @foreach ($signos as [$sigla, $valor, $nombre, $alerta])
            <li>
                {{ $nombre }}: {{ $valor }}
                @if ($alerta)
                    <span class="marca">{{ EvaluadorSignos::ETIQUETAS[$alerta['nivel'] ?? ''] ?? '' }}@if (($alerta['direccion'] ?? null) !== null) ({{ $alerta['direccion'] }})@endif @if (($alerta['nivel'] ?? null) === EvaluadorSignos::MUY_ALTERADO && ($alerta['confirmado_valor'] ?? null) !== null) · confirmado @endif</span>
                @endif
            </li>
        @endforeach
    </ul>
    @if ($examen && ($texto = DatosFormulario::autoria($examen->signosUsuario)))
        <div class="autoria">{{ $texto }}</div>
    @endif
@endif
