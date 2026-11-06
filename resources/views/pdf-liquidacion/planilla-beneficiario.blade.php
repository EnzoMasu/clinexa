{{--
    Planilla de la liquidación a un beneficiario (App\Support\LiquidacionesBeneficiarios\PlanillaLiquidacionBeneficiario,
    docs/liquidacion-beneficiarios.md). A4 apaisada. SIN nada clínico. La paciente, solo en la del profesional; en la del dueño del equipo,
    solo el N.º de prestación. Todo escapado.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Planilla de liquidación a beneficiario</title>
    <style>
        @page { size: A4 landscape; margin: 12mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; line-height: 1.3; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        table.encabezado td { vertical-align: middle; padding: 0 3mm 0 0; }
        .logo { height: 12mm; }
        .clinica { font-size: 11.5pt; font-weight: bold; }
        h1 { margin: 4mm 0 2mm; font-size: 11pt; border-bottom: 0.75pt solid #9ca3af; padding-bottom: 1mm; }
        h2 { margin: 4mm 0 1mm; font-size: 9.5pt; }
        table.datos td { padding: 0.4mm 0; vertical-align: top; }
        table.datos td.etiqueta { width: 40mm; color: #374151; }
        table.lineas { margin-top: 2mm; }
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

    <h1>Liquidación N.º {{ $numero }} a {{ $tipo === 'Profesional' ? 'profesional' : 'dueño de equipo' }}</h1>
    <table class="datos">
        <tr><td class="etiqueta">Beneficiario</td><td>{{ $beneficiario }}@if ($documento !== '') ({{ $documento }})@endif</td></tr>
        <tr><td class="etiqueta">Fecha de corte</td><td>{{ $corte }}</td></tr>
        <tr><td class="etiqueta">Período</td><td>{{ $periodo }}</td></tr>
        <tr><td class="etiqueta">Estado</td><td>{{ $estado }}</td></tr>
    </table>

    <table class="lineas">
        <thead>
            <tr>
                <th>Concepto</th><th>N.º de prestación</th><th>Fecha</th>
                @if ($conPaciente)<th>Paciente</th>@endif
                <th>Procedimiento</th>
                @unless ($conPaciente)<th>Equipo</th>@endunless
                <th class="monto">Base</th><th class="monto">%</th><th class="monto">Monto</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr>
                    <td>{{ $fila['concepto'] }}</td><td>{{ $fila['prestacion'] }}</td><td>{{ $fila['fecha'] }}</td>
                    @if ($conPaciente)<td>{{ $fila['paciente'] }}</td>@endif
                    <td>{{ $fila['procedimiento'] }}</td>
                    @unless ($conPaciente)<td>{{ $fila['equipo'] }}</td>@endunless
                    <td class="monto">{{ $fila['base'] }}</td><td class="monto">{{ $fila['porcentaje'] }}</td><td class="monto">{{ $fila['monto'] }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="7">Total ({{ count($filas) }} líneas)</td><td class="monto">{{ $total }}</td></tr>
        </tbody>
    </table>

    @if ($pagos !== [])
        <h2>Pagos</h2>
        <table class="lineas">
            <thead><tr><th>Fecha</th><th>Medio</th><th>Comprobante</th><th class="monto">Monto</th></tr></thead>
            <tbody>
                @foreach ($pagos as $pago)
                    <tr><td>{{ $pago['fecha'] }}</td><td>{{ $pago['medio'] }}</td><td>{{ $pago['comprobante'] }}</td><td class="monto">{{ $pago['monto'] }}</td></tr>
                @endforeach
                <tr class="total"><td colspan="3">Pagado</td><td class="monto">{{ $pagado }}</td></tr>
                <tr class="total"><td colspan="3">Saldo</td><td class="monto">{{ $saldo }}</td></tr>
            </tbody>
        </table>
    @endif
    @if ($enContra !== null)
        <p>Saldo en contra del beneficiario: {{ $enContra }}. No se paga: pasa a su liquidación siguiente.</p>
    @endif

    <table class="firmas">
        <tr>
            <td><div class="linea"></div>Por la clínica</td>
            <td><div class="linea"></div>Recibí conforme (firma, aclaración y fecha)</td>
        </tr>
    </table>

    <div class="pie">Generado el {{ $generadoEn }}. Montos brutos, sin impuestos ni retenciones.</div>
</body>
</html>
