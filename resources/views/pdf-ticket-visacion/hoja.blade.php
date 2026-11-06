{{--
    Planilla de aceptación de consulta (App\Support\Convenios\TicketVisacion, docs/convenios-seguros.md): la planilla que firma la paciente.
    A5 vertical. SIN montos ni nada clínico (ni diagnóstico ni informes): solo la afiliación, la visación y los procedimientos. Todo
    escapado.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Aceptación de consulta</title>
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
        .aviso { margin: 2mm 0 0; padding: 1mm 1.5mm; border: 0.75pt solid #92400e; font-weight: bold; }
        .conformidad { margin-top: 5mm; font-size: 9pt; text-align: justify; }
        .sello { float: right; width: 38mm; height: 22mm; border: 0.5pt dashed #6b7280; text-align: center; font-size: 7pt; color: #6b7280; padding-top: 1mm; }
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

    <h1>Aceptación de consulta</h1>
    <table class="datos">
        <tr><td class="etiqueta">Paciente</td><td>{{ $paciente }}</td></tr>
        <tr><td class="etiqueta">Cédula</td><td>{{ $documento }}</td></tr>
        <tr><td class="etiqueta">Aseguradora</td><td>{{ $aseguradora }}</td></tr>
        <tr><td class="etiqueta">Plan</td><td>{{ $plan }}</td></tr>
        <tr><td class="etiqueta">N.º de asegurado</td><td>{{ $asegurado }} ({{ $tipoAfiliado }})</td></tr>
        <tr><td class="etiqueta">Vencimiento de la cobertura</td><td>{{ $vencimiento }}</td></tr>
        <tr><td class="etiqueta">Profesional</td><td>{{ $profesional }}</td></tr>
        <tr><td class="etiqueta">Fecha y hora</td><td>{{ $fechaHora }}</td></tr>
    </table>

    @if ($avisos)
        <p class="aviso">{{ implode(' · ', $avisos) }}</p>
    @endif

    <table class="procedimientos">
        <thead><tr><th>Procedimiento o estudio realizado</th><th>N.º de visación</th></tr></thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr><td>{{ $fila['procedimiento'] }}</td><td>{{ $fila['visacion'] ?? 'Sin visación' }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <p class="conformidad">Declaro haber sido atendido/a en {{ $clinica }}, en la fecha indicada y por el profesional mencionado, por los
        procedimientos detallados arriba, y que los datos de mi cobertura son correctos.</p>

    <div class="firma">
        <div class="linea"></div>
        Firma de la paciente
        <div style="margin-top: 6mm; text-align: left;">Aclaración: ..........................................</div>
    </div>
    <div class="sello">Sello de la clínica</div>

    <div class="pie">Generado el {{ $generadoEn }}</div>
</body>
</html>
