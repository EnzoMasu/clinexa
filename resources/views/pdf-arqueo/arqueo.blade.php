{{--
    Arqueo de una caja (App\Support\Cobros\PdfArqueo, docs/cobros-caja.md). A4 vertical. Sin nada clínico: pacientes, facturas,
    medios, comprobantes y montos. Todo escapado.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Arqueo de caja</title>
    <style>
        @page { size: A4; margin: 12mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; line-height: 1.3; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        table.encabezado td { vertical-align: middle; padding: 0 3mm 0 0; }
        .logo { height: 12mm; }
        .clinica { font-size: 11.5pt; font-weight: bold; }
        h1 { margin: 4mm 0 2mm; font-size: 11pt; border-bottom: 0.75pt solid #9ca3af; padding-bottom: 1mm; }
        h2 { margin: 4mm 0 1mm; font-size: 9pt; }
        table.datos td { padding: 0.4mm 0; vertical-align: top; }
        table.datos td.etiqueta { width: 32mm; color: #374151; }
        table.lineas th, table.lineas td { border: 0.5pt solid #9ca3af; padding: 0.8mm 1.2mm; text-align: left; }
        table.lineas th { background: #f3f4f6; font-weight: bold; }
        table.lineas td.monto, table.lineas th.monto { text-align: right; white-space: nowrap; }
        table.lineas tr.total td { font-weight: bold; background: #f9fafb; }
        tr.revertido td { color: #6b7280; }
        .marca { font-weight: bold; color: #b91c1c; }
        .vacio { color: #6b7280; }
        .observacion { border: 0.5pt solid #9ca3af; min-height: 12mm; padding: 1.5mm; }
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

    <h1>Arqueo de la caja N.º {{ $numero }}</h1>
    <table class="datos">
        <tr><td class="etiqueta">Fecha</td><td>{{ $fecha }}</td><td class="etiqueta">Sucursal</td><td>{{ $sucursal }}</td></tr>
        <tr><td class="etiqueta">Cajero</td><td>{{ $cajero }}</td><td class="etiqueta">Estado</td><td>{{ $estado }}</td></tr>
        <tr><td class="etiqueta">Apertura</td><td>{{ $apertura }}</td><td class="etiqueta">Cierre</td><td>{{ $cierre }}</td></tr>
        <tr><td class="etiqueta">Monto inicial</td><td>{{ $montoInicial }} (efectivo)</td><td></td><td></td></tr>
    </table>

    <h2>Facturas cobradas ({{ count($facturas) }})</h2>
    @if ($facturas === [])
        <p class="vacio">Sin facturas.</p>
    @else
        <table class="lineas">
            <thead><tr><th>Factura</th><th>Paciente</th><th class="monto">Total</th></tr></thead>
            <tbody>
                @foreach ($facturas as $factura)
                    <tr @class(['revertido' => $factura['revertido']])>
                        <td>{{ $factura['numero'] }}@if ($factura['revertido']) <span class="marca">(revertido)</span>@endif</td>
                        <td>{{ $factura['paciente'] }}</td>
                        <td class="monto">{{ $factura['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Transacciones ({{ count($transacciones) }})</h2>
    @if ($transacciones === [])
        <p class="vacio">Sin cobros.</p>
    @else
        <table class="lineas">
            <thead><tr><th>Fecha y hora</th><th>Cobro</th><th>Factura</th><th>Paciente</th><th>Medio de pago</th><th>Comprobante</th><th class="monto">Monto</th></tr></thead>
            <tbody>
                @foreach ($transacciones as $fila)
                    <tr @class(['revertido' => $fila['revertido']])>
                        <td>{{ $fila['hora'] }}</td>
                        <td>N.º {{ $fila['cobro'] }}@if ($fila['revertido']) <span class="marca">(revertido)</span>@endif</td>
                        <td>{{ $fila['factura'] }}</td>
                        <td>{{ $fila['paciente'] }}</td>
                        <td>{{ $fila['medio'] }}</td>
                        <td>{{ $fila['comprobante'] }}</td>
                        <td class="monto">{{ $fila['monto'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($devoluciones !== [])
        <h2>Devoluciones de cobros revertidos hechas desde esta caja ({{ count($devoluciones) }})</h2>
        <table class="lineas">
            <thead><tr><th>Fecha y hora</th><th>Cobro</th><th>Paciente</th><th>Medio de pago</th><th class="monto">Egreso</th></tr></thead>
            <tbody>
                @foreach ($devoluciones as $fila)
                    <tr><td>{{ $fila['hora'] }}</td><td>N.º {{ $fila['cobro'] }}</td><td>{{ $fila['paciente'] }}</td><td>{{ $fila['medio'] }}</td><td class="monto">{{ $fila['monto'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Totales por medio de pago</h2>
    <table class="lineas">
        <thead><tr><th>Medio de pago</th><th class="monto">Esperado</th><th class="monto">Declarado</th><th class="monto">Diferencia</th></tr></thead>
        <tbody>
            @foreach ($totales as $fila)
                <tr>
                    <td>{{ $fila['medio'] }}</td><td class="monto">{{ $fila['esperado'] }}</td><td class="monto">{{ $fila['declarado'] }}</td>
                    <td class="monto">@if ($fila['conDiferencia'])<span class="marca">{{ $fila['diferencia'] }}</span>@else{{ $fila['diferencia'] }}@endif</td>
                </tr>
            @endforeach
            <tr class="total"><td>Total</td><td class="monto">{{ $total['esperado'] }}</td><td class="monto">{{ $total['declarado'] }}</td><td class="monto">{{ $total['diferencia'] }}</td></tr>
        </tbody>
    </table>

    <h2>Observación</h2>
    <div class="observacion">{{ $observacion }}</div>

    @if ($revision)
        <p>{{ $revision['estado'] }} por {{ $revision['por'] }} el {{ $revision['en'] }}@if ($revision['nota'] !== ''). Observación de Gerencia: {{ $revision['nota'] }}@endif</p>
    @endif

    <table class="firmas">
        <tr>
            <td><div class="linea"></div>Firma del cajero</td>
            <td><div class="linea"></div>Firma del gerente</td>
        </tr>
    </table>

    <div class="pie">Generado el {{ $generadoEn }}</div>
</body>
</html>
