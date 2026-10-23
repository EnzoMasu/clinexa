{{--
    Ticket de la aseguradora (App\Support\Convenios\TicketVisacion, docs/convenios-seguros.md): la planilla que firma la paciente.
    A5 vertical. SIN montos ni nada clínico (ni diagnóstico ni informes): solo la afiliación, la visación y los procedimientos. Todo
    escapado.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket de la aseguradora</title>
    <style>
        @page { size: A5 portrait; margin: 10mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.3; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        table.encabezado td { vertical-align: middle; padding: 0 3mm 0 0; }
        .logo { height: 12mm; }
        .clinica { font-size: 11.5pt; font-weight: bold; }
        h1 { margin: 4mm 0 2mm; font-size: 11pt; border-bottom: 0.75pt solid #9ca3af; padding-bottom: 1mm; }
        table.datos td { padding: 0.4mm 0; vertical-align: top; }
        table.datos td.etiqueta { width: 38mm; color: #374151; }
        table.procedimientos { margin-top: 3mm; }
        table.procedimientos th, table.procedimientos td { border: 0.5pt solid #9ca3af; padding: 1mm 1.5mm; text-align: left; }
        table.procedimientos th { background: #f3f4f6; font-weight: bold; }
        .firma { margin-top: 18mm; width: 70%; margin-left: auto; margin-right: auto; text-align: center; page-break-inside: avoid; }
        .linea { border-top: 0.75pt solid #111; margin-bottom: 1mm; }
        .pie { margin-top: 6mm; padding-top: 1mm; border-top: 0.5pt solid #d1d5db; font-size: 7pt; color: #374151; }
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

    <h1>Constancia de atención para la aseguradora</h1>
    <table class="datos">
        <tr><td class="etiqueta">Paciente</td><td>{{ $paciente }}</td></tr>
        <tr><td class="etiqueta">Cédula</td><td>{{ $documento }}</td></tr>
        <tr><td class="etiqueta">Aseguradora</td><td>{{ $aseguradora }}</td></tr>
        <tr><td class="etiqueta">Plan</td><td>{{ $plan }}</td></tr>
        <tr><td class="etiqueta">N.º de asegurado</td><td>{{ $asegurado }} ({{ $tipoAfiliado }})</td></tr>
        <tr><td class="etiqueta">Profesional</td><td>{{ $profesional }}</td></tr>
        <tr><td class="etiqueta">Fecha y hora</td><td>{{ $fechaHora }}</td></tr>
    </table>

    <table class="procedimientos">
        <thead><tr><th>Procedimiento o estudio realizado</th><th>N.º de visación</th></tr></thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr><td>{{ $fila['procedimiento'] }}</td><td>{{ $fila['visacion'] }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <div class="firma">
        <div class="linea"></div>
        Firma de la paciente
        <div style="margin-top: 6mm; text-align: left;">Aclaración: ......................................................</div>
    </div>

    <div class="pie">Generado el {{ $generadoEn }}</div>
</body>
</html>
