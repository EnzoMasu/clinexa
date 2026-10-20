{{--
    PDF de un informe de estudio (App\Support\PdfClinico\PdfInformeEstudio). A4 vertical. Lista cerrada: clínica y logotipo,
    paciente, profesional, fecha, estudio, solicitante si hay, el texto tal cual y las líneas de firma y sello. Sin
    diagnósticos ni medicamentos; sin numeración, huella ni marca diagonal (taparía campos). Todo escapado.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de estudio</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; line-height: 1.35; color: #111; }
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
        table.firmas { margin-top: 20mm; page-break-inside: avoid; }
        table.firmas td { width: 50%; text-align: center; padding: 0 6mm; }
        .linea { border-top: 0.75pt solid #111; margin-bottom: 1mm; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            @if (is_file($logo))
                <td style="width: 1%"><img src="{{ $logo }}" alt="" class="logo"></td>
            @endif
            <td>
                <div class="clinica">{{ $clinica['nombre'] }}</div>
                @if ($clinica['direccion'])
                    <div class="pequeno">{{ $clinica['direccion'] }}</div>
                @endif
                @if ($clinica['telefono'])
                    <div class="pequeno">Tel. {{ $clinica['telefono'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="titulo">
        <tr>
            <td>Informe de estudio</td>
            <td class="derecha">Fecha: {{ $fecha }}</td>
        </tr>
    </table>

    <h2>Paciente</h2>
    <table class="datos">
        <tr><td class="etiqueta">Nombre</td><td>{{ $paciente['nombre'] }}</td></tr>
        <tr><td class="etiqueta">Documento</td><td>{{ $paciente['documento'] }}</td></tr>
        <tr><td class="etiqueta">Fecha de nacimiento</td><td>{{ $paciente['fecha_nacimiento'] ?? '—' }}{{ $paciente['edad'] ? ' ('.$paciente['edad'].')' : '' }}</td></tr>
        <tr><td class="etiqueta">Ficha</td><td>{{ $paciente['ficha'] }}</td></tr>
    </table>

    <h2>Profesional</h2>
    <table class="datos">
        <tr><td class="etiqueta">Nombre</td><td>{{ $profesional['nombre'] }}</td></tr>
        @if ($profesional['especialidades'])
            <tr><td class="etiqueta">Especialidad</td><td>{{ implode(', ', $profesional['especialidades']) }}</td></tr>
        @endif
        <tr><td class="etiqueta">Matrícula</td><td>{{ $profesional['matricula'] }}</td></tr>
    </table>

    <h2>Estudio</h2>
    <table class="datos">
        <tr><td class="etiqueta">Estudio realizado</td><td>{{ $estudio }}</td></tr>
        @if ($solicitante)
            <tr><td class="etiqueta">Solicitante</td><td>{{ $solicitante }}</td></tr>
        @endif
    </table>

    <h2>Informe</h2>
    <div class="texto-libre">{{ $texto }}</div>

    <table class="firmas">
        <tr>
            <td><div class="linea"></div>Firma</td>
            <td><div class="linea"></div>Aclaración y sello</td>
        </tr>
    </table>
</body>
</html>
