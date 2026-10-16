{{--
    PDF de las indicaciones generales de la consulta (App\Support\PdfClinico\PdfIndicaciones). A4 vertical, hoja aparte.
    Solo el encabezado (clínica y logotipo, paciente, profesional, fecha de la consulta) y las indicaciones vigentes tal cual,
    con su tipo si lo tienen. Sin medicamentos, diagnósticos ni receta; sin textos legales ni clínicos agregados. Todo escapado.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Indicaciones generales</title>
    <style>
        @page { size: A4 portrait; margin: 14mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; line-height: 1.35; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        table.encabezado td { vertical-align: middle; padding: 0 4mm 0 0; }
        .logo { height: 14mm; }
        .clinica { font-size: 12pt; font-weight: bold; }
        h1 { margin: 5mm 0 2mm; font-size: 13pt; border-bottom: 0.75pt solid #9ca3af; padding-bottom: 1mm; }
        table.datos td { padding: 0.4mm 0; vertical-align: top; }
        table.datos td.etiqueta { width: 32mm; color: #374151; }
        ol { margin: 5mm 0 0; padding-left: 6mm; }
        li { margin-bottom: 3.5mm; page-break-inside: avoid; }
        .tipo { font-weight: bold; }
        .texto-libre { white-space: pre-line; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            @if (is_file($logo))
                <td style="width: 1%"><img src="{{ $logo }}" alt="" class="logo"></td>
            @endif
            <td><div class="clinica">{{ $clinica }}</div></td>
        </tr>
    </table>

    <h1>Indicaciones generales</h1>
    <table class="datos">
        <tr><td class="etiqueta">Paciente</td><td>{{ $paciente }}</td></tr>
        <tr><td class="etiqueta">Documento</td><td>{{ $documento }}</td></tr>
        <tr><td class="etiqueta">Profesional</td><td>{{ $profesional }}</td></tr>
        <tr><td class="etiqueta">Fecha</td><td>{{ $fecha }}</td></tr>
    </table>

    <ol>
        @foreach ($indicaciones as $indicacion)
            <li>
                @if ($indicacion['tipo'])
                    <div class="tipo">{{ $indicacion['tipo'] }}</div>
                @endif
                <div class="texto-libre">{{ $indicacion['texto'] }}</div>
            </li>
        @endforeach
    </ol>
</body>
</html>
