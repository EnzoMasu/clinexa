{{--
    PDF de una receta con indicación de uso (App\Support\PdfClinico\PdfReceta). A4 vertical en dos mitades iguales con una
    línea de corte: arriba la RECETA (medicamento y cantidad, sin indicaciones; queda en la farmacia), abajo las
    INDICACIONES DE USO de cada medicamento (las lleva el paciente). Cada medicamento tiene el mismo número de orden en
    las dos mitades y su nombre encabeza su indicación.

    Se dibuja SOLO desde $hoja (snapshot o datos de hoy del borrador). Todo escapado. $marca: "VISTA PREVIA - NO VÁLIDA",
    "ANULADA" o null. Lo que entra en cada mitad lo controla MediaHoja antes de emitir.
--}}
@php
    use App\Support\Fecha;

    $paciente = $hoja['paciente'];
    $profesional = $hoja['profesional'];
    $fecha = Fecha::mostrar($hoja['fecha']);
    $numero = $hoja['numero'] ?? null;
    $edad = $paciente['edad'] === null ? null : $paciente['edad'].' '.($paciente['edad'] === 1 ? 'año' : 'años');
    $especialidades = implode(', ', $profesional['especialidades'] ?? []);
    $util = $papel['ancho_mm'] - 2 * $relleno;
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Receta {{ $numero ?? '(borrador)' }}</title>
    <style>
        @page { size: {{ $papel['nombre'] }} portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; line-height: 1.35; color: #111; }
        .mitad { position: absolute; left: 0; width: {{ $papel['ancho_mm'] }}mm; height: {{ $mitad }}mm; overflow: visible; }
        .mitad.arriba { top: 0; }
        .mitad.abajo { top: {{ $mitad }}mm; }
        .contenido { position: absolute; top: {{ $relleno }}mm; left: {{ $relleno }}mm; width: {{ $util }}mm; word-wrap: break-word; }
        .firma, .pie { position: absolute; bottom: {{ $relleno }}mm; left: {{ $relleno }}mm; width: {{ $util }}mm; text-align: right; }
        .linea-firma { width: 60mm; margin: 0 0 1mm auto; border-top: 0.75pt solid #111; }
        .corte { position: absolute; left: 0; top: {{ $mitad }}mm; width: {{ $papel['ancho_mm'] }}mm; border-top: 0.75pt dashed #6b7280; }
        .corte span { position: absolute; right: {{ $relleno }}mm; top: -2.2mm; padding: 0 2mm; background: #fff; color: #6b7280; font-size: 7pt; }
        .marca { position: absolute; left: 0; width: {{ $papel['ancho_mm'] }}mm; text-align: center; }
        .marca span { display: inline-block; transform: rotate(-30deg); font-size: 30pt; font-weight: bold; color: #f2b8b8; border: 2pt solid #f2b8b8; padding: 3mm 6mm; }
        table.encabezado { border-collapse: collapse; }
        table.encabezado td { vertical-align: middle; padding: 0 4mm 0 0; }
        .logo { height: 14mm; }
        .clinica { font-size: 12pt; font-weight: bold; }
        .pequeno { font-size: 8.5pt; color: #374151; }
        table.titulo { width: 100%; border-collapse: collapse; margin-top: 3mm; border-bottom: 0.75pt solid #9ca3af; font-weight: bold; font-size: 11pt; }
        table.titulo td { padding: 0 0 1mm 0; }
        table.titulo td.derecha { text-align: right; }
        .paciente { margin-top: 2mm; }
        ol.medicamentos { margin: 3mm 0 0 0; padding-left: 6mm; }
        ol.medicamentos li { margin-bottom: 1mm; }
        .texto { margin: 2mm 0 0 0; }
        .subtitulo { margin: 3mm 0 1mm 0; font-size: 10.5pt; font-weight: bold; }
        .uso { margin-bottom: 1.5mm; }
        .texto-libre { white-space: pre-line; }
    </style>
</head>
<body>
    @if ($marca)
        @foreach ([$mitad / 2 - 8, $mitad + $mitad / 2 - 8] as $alto)
            <div class="marca" style="top: {{ $alto }}mm"><span>{{ $marca }}</span></div>
        @endforeach
    @endif

    {{-- Mitad de arriba: la receta (queda en la farmacia). Sin indicaciones de uso. --}}
    <div class="mitad arriba">
        <div class="contenido">
            <table class="encabezado">
                <tr>
                    @if (is_file($logo))
                        <td><img src="{{ $logo }}" alt="" class="logo"></td>
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

            <table class="titulo"><tr>
                <td>Receta N° {{ $numero ?? '(se asigna al emitir)' }}</td>
                <td class="derecha">Fecha: {{ $fecha }}</td>
            </tr></table>
            @if ($hoja['reemplaza_a'] ?? null)
                <div class="pequeno">Reemplaza a {{ $hoja['reemplaza_a'] }}</div>
            @endif

            <div class="paciente">
                <strong>Paciente:</strong> {{ $paciente['nombre'] }}
                · {{ $paciente['documento'] }} · Ficha {{ $paciente['ficha'] }}@if ($edad) · {{ $edad }}@endif
            </div>

            <ol class="medicamentos" data-mitad="receta">
                @foreach ($hoja['medicamentos'] as $medicamento)
                    <li><strong>{{ $medicamento['medicamento'] }}</strong>@if (filled($medicamento['cantidad'])) — Cantidad: {{ $medicamento['cantidad'] }}@endif</li>
                @endforeach
            </ol>

            @if (filled($hoja['observaciones']))
                <p class="texto"><strong>Observaciones:</strong> <span class="texto-libre">{{ $hoja['observaciones'] }}</span></p>
            @endif
        </div>

        <div class="firma">
            <div class="linea-firma"></div>
            <div><strong>{{ $profesional['nombre'] }}</strong> · Mat. {{ $profesional['matricula'] }}</div>
            @if ($especialidades !== '')
                <div class="pequeno">{{ $especialidades }}</div>
            @endif
        </div>
    </div>

    <div class="corte"><span>Cortar por la línea</span></div>

    {{-- Mitad de abajo: la indicación de uso de cada medicamento (la lleva el paciente). --}}
    <div class="mitad abajo">
        <div class="contenido" data-mitad="indicaciones">
            <table class="titulo"><tr>
                <td>Indicaciones para el paciente</td>
                <td class="derecha">Receta N° {{ $numero ?? '(se asigna al emitir)' }} · {{ $fecha }}</td>
            </tr></table>
            <div class="paciente"><strong>Paciente:</strong> {{ $paciente['nombre'] }}</div>

            <div class="subtitulo">Cómo tomar su medicación</div>
            @foreach ($hoja['medicamentos'] as $medicamento)
                <div class="uso">
                    <div><strong>{{ $loop->iteration }}. {{ $medicamento['medicamento'] }}</strong></div>
                    <div class="texto-libre">{{ $medicamento['indicacion_uso'] ?? '' }}</div>
                </div>
            @endforeach
        </div>

        <div class="pie pequeno">
            {{ $profesional['nombre'] }} · Mat. {{ $profesional['matricula'] }}@if ($especialidades !== '') · {{ $especialidades }}@endif
        </div>
    </div>
</body>
</html>
