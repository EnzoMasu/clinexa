{{--
    Factura individual CONTADO (App\Support\Facturacion\PdfFactura, docs/facturacion.md). A4. Todo sale del snapshot de la factura;
    sin CDC ni QR; con la marca de simulación si la factura es simulada. Sin nada clínico. Todo escapado.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura {{ $factura->numero_completo }}</title>
    <style>
        @page { size: A4; margin: 12mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; line-height: 1.3; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        .caja { border: 0.75pt solid #6b7280; padding: 2mm 3mm; vertical-align: top; }
        .logo { height: 14mm; }
        .clinica { font-size: 11.5pt; font-weight: bold; }
        .tipo { font-size: 12pt; font-weight: bold; text-align: center; margin: 1mm 0; }
        .numero { font-size: 11pt; font-weight: bold; text-align: center; }
        .simulacion { margin: 0 0 3mm; padding: 2mm; border: 1.5pt solid #b91c1c; color: #b91c1c; font-weight: bold; font-size: 11pt; text-align: center; }
        .anulada { margin: 0 0 3mm; padding: 2mm; border: 1.5pt solid #111; font-weight: bold; font-size: 11pt; text-align: center; }
        table.datos { margin-top: 3mm; }
        table.datos td { padding: 0.6mm 2mm 0.6mm 0; vertical-align: top; }
        table.datos td.etiqueta { width: 32mm; color: #374151; }
        table.lineas { margin-top: 3mm; }
        table.lineas th, table.lineas td { border: 0.5pt solid #9ca3af; padding: 1mm 1.5mm; text-align: left; }
        table.lineas th { background: #f3f4f6; font-weight: bold; }
        table.lineas .monto { text-align: right; white-space: nowrap; }
        table.lineas tr.sub td { font-weight: bold; background: #f9fafb; }
        table.totales { margin-top: 3mm; width: 60%; margin-left: 40%; }
        table.totales td { border: 0.5pt solid #9ca3af; padding: 1mm 1.5mm; }
        table.totales td.monto { text-align: right; white-space: nowrap; }
        table.totales tr.total td { font-weight: bold; font-size: 10pt; }
        .pie { margin-top: 6mm; padding-top: 1mm; border-top: 0.5pt solid #d1d5db; font-size: 7pt; color: #374151; }
    </style>
</head>
<body>
    @if ($marcaSimulacion)
        <div class="simulacion">{{ $marcaSimulacion }}</div>
    @endif
    @if ($anulada)
        <div class="anulada">FACTURA ANULADA</div>
    @endif

    <table>
        <tr>
            <td class="caja" style="width: 58%">
                @if (is_file($logo))
                    <img src="{{ $logo }}" alt="" class="logo"><br>
                @endif
                <div class="clinica">{{ $factura->emisor_nombre_fantasia ?: $factura->emisor_razon_social }}</div>
                @if ($factura->emisor_nombre_fantasia)
                    <div>{{ $factura->emisor_razon_social }}</div>
                @endif
                <div>{{ $factura->emisor_actividad }}</div>
                <div>{{ $factura->emisor_direccion }}</div>
                @if ($factura->emisor_telefono)
                    <div>Tel.: {{ $factura->emisor_telefono }}</div>
                @endif
            </td>
            <td class="caja">
                <div>Timbrado N.º {{ $factura->numero_timbrado }}</div>
                <div>Vigencia: {{ $vigencia }}</div>
                <div>RUC: {{ $factura->emisor_ruc }}</div>
                <div class="tipo">FACTURA{{ $copia ? ' (COPIA)' : '' }}</div>
                <div class="numero">N.º {{ $factura->numero_completo }}</div>
            </td>
        </tr>
    </table>

    <table class="datos">
        <tr><td class="etiqueta">Fecha y hora</td><td>{{ $fecha }}</td><td class="etiqueta">Condición</td><td>CONTADO</td></tr>
        <tr><td class="etiqueta">Nombre o razón social</td><td>{{ $factura->cliente_nombre }}</td><td class="etiqueta">Documento</td><td>{{ $factura->cliente_documento }}</td></tr>
        <tr><td class="etiqueta">Moneda</td><td colspan="3">{{ $factura->moneda->nombre }}</td></tr>
    </table>

    <table class="lineas">
        <thead>
            <tr>
                <th style="width: 8%">Cant.</th><th>Descripción</th><th class="monto">Precio unitario</th>
                <th class="monto">Exentas</th><th class="monto">5 %</th><th class="monto">10 %</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lineas as $linea)
                <tr>
                    <td>{{ $linea['cantidad'] }}</td><td>{{ $linea['descripcion'] }}</td><td class="monto">{{ $linea['precio'] }}</td>
                    <td class="monto">{{ $linea['exenta'] }}</td><td class="monto">{{ $linea['cinco'] }}</td><td class="monto">{{ $linea['diez'] }}</td>
                </tr>
            @endforeach
            <tr class="sub">
                <td colspan="3">Subtotales</td>
                <td class="monto">{{ $subtotales['exenta'] }}</td><td class="monto">{{ $subtotales['cinco'] }}</td><td class="monto">{{ $subtotales['diez'] }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totales">
        @foreach ($totales as [$texto, $monto])
            <tr><td>{{ $texto }}</td><td class="monto">{{ $monto }}</td></tr>
        @endforeach
        <tr><td>Total IVA</td><td class="monto">{{ $ivaTotal }}</td></tr>
        <tr class="total"><td>Total a pagar</td><td class="monto">{{ $total }}</td></tr>
    </table>

    <div class="pie">
        @if ($marcaSimulacion)
            {{ $marcaSimulacion }}: documento de prueba, no enviado a la administración tributaria. ·
        @endif
        Generado el {{ $generadoEn }}
    </div>
</body>
</html>
