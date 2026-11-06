{{--
    Planilla de aceptación de consulta en formato ticket de 80 mm (impresora térmica): los mismos datos que la hoja A5
    (App\Support\Convenios\TicketVisacion), en una sola columna. El alto lo fija TicketVisacion según el contenido ($alto, en puntos).
    Sin montos ni nada clínico. Todo escapado.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Aceptación de consulta</title>
    <style>
        @page { margin: 3mm 4mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.3; color: #000; }
        .centro { text-align: center; }
        .clinica { font-size: 10.5pt; font-weight: bold; }
        .logo { height: 10mm; }
        h1 { margin: 2mm 0 1mm; font-size: 10pt; text-align: center; border-top: 0.75pt solid #000; border-bottom: 0.75pt solid #000; padding: 1mm 0; }
        .dato { margin: 0.6mm 0; }
        .dato b { display: block; font-size: 7.5pt; font-weight: normal; }
        .aviso { margin: 2mm 0 0; padding: 1mm; border: 1pt solid #000; font-weight: bold; text-align: center; }
        ul { margin: 1mm 0 0; padding-left: 4mm; }
        li { margin-bottom: 1mm; }
        .conformidad { margin-top: 3mm; font-size: 8.5pt; text-align: justify; }
        .firma { margin-top: 14mm; text-align: center; }
        .linea { border-top: 0.75pt solid #000; margin-bottom: 1mm; }
        .sello { margin-top: 4mm; height: 22mm; border: 0.5pt dashed #000; text-align: center; font-size: 7pt; padding-top: 1mm; }
        .pie { margin-top: 3mm; font-size: 7pt; text-align: center; }
    </style>
</head>
<body>
    <div class="centro">
        @if (is_file($logo))<img src="{{ $logo }}" alt="" class="logo"><br>@endif
        <span class="clinica">{{ $clinica }}</span>
    </div>
    <h1>Aceptación de consulta</h1>
    @if ($avisos)
        <p class="aviso">{{ implode(' · ', $avisos) }}</p>
    @endif

    <div class="dato"><b>Paciente</b>{{ $paciente }}</div>
    <div class="dato"><b>Cédula</b>{{ $documento }}</div>
    <div class="dato"><b>Aseguradora</b>{{ $aseguradora }}</div>
    <div class="dato"><b>Plan</b>{{ $plan }}</div>
    <div class="dato"><b>N.º de asegurado</b>{{ $asegurado }} ({{ $tipoAfiliado }})</div>
    <div class="dato"><b>Vencimiento de la cobertura</b>{{ $vencimiento }}</div>
    <div class="dato"><b>Profesional</b>{{ $profesional }}</div>
    <div class="dato"><b>Fecha y hora</b>{{ $fechaHora }}</div>

    <div class="dato"><b>Procedimiento o estudio realizado (N.º de visación)</b></div>
    <ul>
        @foreach ($filas as $fila)
            <li>{{ $fila['procedimiento'] }} ({{ $fila['visacion'] ?? 'Sin visación' }})</li>
        @endforeach
    </ul>

    <p class="conformidad">Declaro haber sido atendido/a en {{ $clinica }}, en la fecha indicada y por el profesional mencionado, por los
        procedimientos detallados arriba, y que los datos de mi cobertura son correctos.</p>

    <div class="firma">
        <div class="linea"></div>
        Firma de la paciente
        <div style="margin-top: 5mm; text-align: left;">Aclaración: ...........................</div>
    </div>
    <div class="sello">Sello de la clínica</div>
    <div class="pie">Generado el {{ $generadoEn }}</div>
</body>
</html>
