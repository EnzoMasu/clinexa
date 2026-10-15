{{--
    PDF del certificado de reposo médico (App\Support\Reposo\PdfReposo). A4 vertical. Se dibuja SOLO desde el snapshot ($hoja):
    sale idéntico aunque después cambien los datos del paciente, del profesional o de la clínica. Todo escapado. Los textos
    fijos vienen del snapshot (config/reposo.php al emitir). Un certificado marcado borrador lleva el cartel arriba, en el pie
    y en diagonal; uno anulado, "ANULADO" en diagonal. El diagnóstico sale solo si el profesional lo eligió.
--}}
@php
    use App\Support\Fecha;

    $paciente = $hoja['paciente'];
    $profesional = $hoja['profesional'];
    $borrador = (bool) ($hoja['borrador'] ?? false);
    $edad = $paciente['edad'] === null ? null : $paciente['edad'].' '.($paciente['edad'] === 1 ? 'año' : 'años');
    $marca = $anulado ? 'ANULADO' : ($borrador ? $hoja['marca_borrador'] : null);
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $hoja['titulo'] }} {{ $hoja['numero'] }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; line-height: 1.3; color: #111; }
        .marca { position: fixed; top: 120mm; left: 0; width: 100%; text-align: center; }
        .marca span { display: inline-block; transform: rotate(-30deg); font-size: 26pt; font-weight: bold; color: #f2c9a0; border: 2pt solid #f2c9a0; padding: 3mm 6mm; }
        .marca.anulado span { color: #f2b8b8; border-color: #f2b8b8; font-size: 34pt; }
        .cartel { margin: 0 0 4mm; padding: 1.5mm 3mm; border: 1.5pt solid #b45309; color: #92400e; font-weight: bold; text-align: center; letter-spacing: 1px; font-size: 9pt; }
        table { border-collapse: collapse; width: 100%; }
        table.encabezado td { vertical-align: middle; padding: 0 4mm 0 0; }
        .logo { height: 14mm; }
        .clinica { font-size: 12pt; font-weight: bold; }
        .pequeno { font-size: 8.5pt; color: #374151; }
        table.titulo { margin-top: 4mm; border-bottom: 0.75pt solid #9ca3af; font-weight: bold; font-size: 12pt; }
        table.titulo td { padding: 0 0 1mm 0; }
        table.titulo td.derecha { text-align: right; font-size: 10pt; }
        h2 { margin: 4mm 0 1mm; font-size: 10.5pt; border-bottom: 0.5pt solid #d1d5db; padding-bottom: 0.5mm; }
        table.datos td { padding: 0.3mm 0; vertical-align: top; }
        table.datos td.etiqueta { width: 50mm; color: #374151; }
        .texto-libre { white-space: pre-line; }
        .leyenda { margin-top: 5mm; }
        table.firmas { margin-top: 20mm; page-break-inside: avoid; }
        table.firmas td { width: 50%; text-align: center; padding: 0 6mm; }
        .linea { border-top: 0.75pt solid #111; margin-bottom: 1mm; }
        .pie { margin-top: 6mm; padding-top: 1.5mm; border-top: 0.5pt solid #d1d5db; font-size: 7.5pt; color: #374151; }
        .huella { font-family: 'DejaVu Sans Mono', monospace; font-size: 7pt; word-wrap: break-word; }
    </style>
</head>
<body>
    @if ($marca)
        <div @class(['marca', 'anulado' => $anulado])><span>{{ $marca }}</span></div>
    @endif

    @if ($borrador)
        <div class="cartel">{{ $hoja['marca_borrador'] }}</div>
    @endif

    <table class="encabezado">
        <tr>
            @if (is_file($logo))
                <td style="width: 1%"><img src="{{ $logo }}" alt="" class="logo"></td>
            @endif
            <td>
                <div class="clinica">{{ $hoja['clinica']['nombre'] }}</div>
                @if ($hoja['clinica']['direccion'])
                    <div class="pequeno">{{ $hoja['clinica']['direccion'] }}</div>
                @endif
                @if ($hoja['clinica']['telefono'])
                    <div class="pequeno">Tel. {{ $hoja['clinica']['telefono'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="titulo">
        <tr>
            <td>{{ $hoja['titulo'] }} N° {{ $hoja['numero'] }}</td>
            <td class="derecha">Fecha de emisión: {{ Fecha::mostrar($hoja['fecha']) }}</td>
        </tr>
    </table>

    <h2>Paciente</h2>
    <table class="datos">
        <tr><td class="etiqueta">Nombre</td><td>{{ $paciente['nombre'] }}</td></tr>
        <tr><td class="etiqueta">Documento</td><td>{{ $paciente['documento'] }}</td></tr>
        <tr><td class="etiqueta">Fecha de nacimiento</td><td>{{ $paciente['fecha_nacimiento'] ? Fecha::mostrar($paciente['fecha_nacimiento']) : '—' }}{{ $edad ? ' ('.$edad.')' : '' }}</td></tr>
        <tr><td class="etiqueta">Teléfono</td><td>{{ $paciente['telefono'] ?: '—' }}</td></tr>
        <tr><td class="etiqueta">Ficha</td><td>{{ $paciente['ficha'] }}</td></tr>
        @if ($hoja['empleador'])
            <tr><td class="etiqueta">Empleador o institución</td><td>{{ $hoja['empleador'] }}</td></tr>
        @endif
    </table>

    <h2>Profesional</h2>
    <table class="datos">
        <tr><td class="etiqueta">Nombre</td><td>{{ $profesional['nombre'] }}</td></tr>
        @if ($profesional['especialidades'])
            <tr><td class="etiqueta">Especialidad</td><td>{{ implode(', ', $profesional['especialidades']) }}</td></tr>
        @endif
        <tr><td class="etiqueta">Matrícula</td><td>{{ $profesional['matricula'] }}</td></tr>
        @if ($profesional['contacto'])
            <tr><td class="etiqueta">Contacto</td><td>Tel. {{ $profesional['contacto'] }}</td></tr>
        @endif
    </table>

    <h2>Reposo</h2>
    <table class="datos">
        <tr><td class="etiqueta">Fecha de atención</td><td>{{ Fecha::mostrar($hoja['fecha_atencion']) }}</td></tr>
        <tr><td class="etiqueta">Días</td><td>{{ $hoja['dias'] }}</td></tr>
        <tr><td class="etiqueta">Desde</td><td>{{ Fecha::mostrar($hoja['desde']) }}</td></tr>
        <tr><td class="etiqueta">Hasta</td><td>{{ Fecha::mostrar($hoja['hasta']) }} · {{ $hoja['inclusive'] }}</td></tr>
        <tr><td class="etiqueta">Modalidad</td><td>{{ $hoja['modalidad']['texto'] }}@if ($hoja['modalidad']['detalle']): <span class="texto-libre">{{ $hoja['modalidad']['detalle'] }}</span>@endif</td></tr>
        @if ($hoja['prorroga_de'])
            <tr><td class="etiqueta">Prórroga del certificado</td><td>N° {{ $hoja['prorroga_de'] }}</td></tr>
        @endif
        @if ($hoja['diagnosticos'])
            <tr>
                <td class="etiqueta">Diagnóstico (CIE-10)</td>
                <td>@foreach ($hoja['diagnosticos'] as $diagnostico)<div>{{ $diagnostico['codigo'] }} — {{ $diagnostico['descripcion'] }}</div>@endforeach</td>
            </tr>
        @endif
    </table>

    @if ($hoja['observaciones'])
        <h2>Observaciones y recomendaciones</h2>
        <div class="texto-libre">{{ $hoja['observaciones'] }}</div>
    @endif

    <p class="leyenda">{{ $hoja['leyenda'] }}</p>

    <table class="firmas">
        <tr>
            <td><div class="linea"></div>{{ $hoja['firma'] }}</td>
            <td><div class="linea"></div>{{ $hoja['aclaracion'] }}</td>
        </tr>
    </table>

    <div class="pie">
        <div>Emitido por {{ $hoja['emitido_por'] }} · Huella sha256: <span class="huella">{{ $huella }}</span></div>
        @if ($borrador)
            <div><strong>{{ $hoja['marca_borrador'] }}</strong></div>
        @endif
    </div>
</body>
</html>
