{{--
    Planilla de la liquidación a la aseguradora (App\Support\Liquidaciones\PlanillaLiquidacion, docs/liquidaciones-aseguradoras.md).
    A4 apaisada. SIN diagnóstico ni nada clínico: solo la afiliación, la visación, el procedimiento y lo que paga la aseguradora. Todo
    escapado.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Planilla de liquidación</title>
    <style>
        @page { size: A4 landscape; margin: 12mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; line-height: 1.3; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        table.encabezado td { vertical-align: middle; padding: 0 3mm 0 0; }
        .logo { height: 12mm; }
        .clinica { font-size: 11.5pt; font-weight: bold; }
        h1 { margin: 4mm 0 2mm; font-size: 11pt; border-bottom: 0.75pt solid #9ca3af; padding-bottom: 1mm; }
        table.datos td { padding: 0.4mm 0; vertical-align: top; }
        table.datos td.etiqueta { width: 40mm; color: #374151; }
        table.lineas { margin-top: 3mm; }
        table.lineas th, table.lineas td { border: 0.5pt solid #9ca3af; padding: 1mm 1.5mm; text-align: left; }
        table.lineas th { background: #f3f4f6; font-weight: bold; }
        table.lineas td.monto, table.lineas th.monto { text-align: right; white-space: nowrap; }
        table.lineas tr.total td { font-weight: bold; background: #f9fafb; }
        .firmas { margin-top: 18mm; page-break-inside: avoid; }
        .firmas td { width: 50%; text-align: center; padding: 0 10mm; }
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

    <h1>Liquidación N.º {{ $numero }} a la aseguradora</h1>
    <table class="datos">
        <tr><td class="etiqueta">Aseguradora</td><td>{{ $aseguradora }}@if ($documentoAseguradora !== '') ({{ $documentoAseguradora }})@endif</td></tr>
        <tr><td class="etiqueta">Convenio</td><td>{{ $convenio }}</td></tr>
        <tr><td class="etiqueta">Fecha de corte</td><td>{{ $corte }}</td></tr>
        <tr><td class="etiqueta">Período</td><td>{{ $periodo }}</td></tr>
        <tr><td class="etiqueta">Estado</td><td>{{ $estado }}</td></tr>
    </table>

    <table class="lineas">
        <thead>
            <tr>
                <th>Paciente</th><th>Documento</th><th>N.º de asegurado</th><th>Fecha</th><th>Procedimiento</th><th>N.º de visación</th><th class="monto">Monto</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr>
                    <td>{{ $fila['paciente'] }}</td><td>{{ $fila['documento'] }}</td><td>{{ $fila['asegurado'] }}</td><td>{{ $fila['fecha'] }}</td>
                    <td>{{ $fila['procedimiento'] }}</td><td>{{ $fila['visacion'] }}</td><td class="monto">{{ $fila['monto'] }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="6">Total ({{ count($filas) }} prestaciones)</td><td class="monto">{{ $total }}</td></tr>
        </tbody>
    </table>

    <table class="firmas">
        <tr>
            <td><div class="linea"></div>Por la clínica</td>
            <td><div class="linea"></div>Recibido por la aseguradora (firma, aclaración y fecha)</td>
        </tr>
    </table>

    <div class="pie">Generado el {{ $generadoEn }}</div>
</body>
</html>
