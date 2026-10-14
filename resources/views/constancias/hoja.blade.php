{{--
    Hoja de una constancia (retiro o estado del turno), emitida o anulada. Se dibuja SOLO desde su snapshot ($hoja): sale
    idéntica aunque cambien los datos del paciente o de la clínica. Layout propio, sin menú y siempre en colores claros;
    la barra y los avisos solo se ven en pantalla. Imprime el navegador (window.print), sin librerías.
    Todo escapado con {{ }}. Una constancia marcada borrador lleva el cartel en la parte de arriba, el pie y en diagonal.
--}}
@php
    use App\Support\Fecha;

    $papel = config('clinexa.recetas.papel');
    $borrador = (bool) ($hoja['borrador'] ?? false);
    $anulada = $constancia->estaAnulada();
    $titulo = "{$hoja['titulo']} {$constancia->numero}";
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $titulo }} · {{ config('app.name') }}</title>
    <style>
        @page { size: {{ $papel['nombre'] }} portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #e5e7eb; color: #111827; color-scheme: light; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.45; }

        .barra { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 10px 16px; background: #fff; border-bottom: 1px solid #d1d5db; font-size: 14px; }
        .barra .acciones { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .boton { display: inline-block; padding: 6px 12px; border-radius: 6px; border: 1px solid #9ca3af; background: #fff; color: #111827; font-size: 13px; text-decoration: none; cursor: pointer; }
        .boton.principal { background: #1f2937; border-color: #1f2937; color: #fff; }
        .boton.peligro { border-color: #dc2626; color: #b91c1c; }
        .mensaje { margin: 12px auto 0; max-width: {{ $papel['ancho_mm'] }}mm; padding: 10px 12px; border-radius: 6px; font-size: 14px; }
        .mensaje.ok { background: #ecfdf5; color: #065f46; }
        .mensaje.aviso { background: #fffbeb; color: #92400e; }
        .mensaje.error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        details.anular { position: relative; }
        details.anular[open] .panel { position: absolute; right: 0; z-index: 10; width: 340px; margin-top: 6px; padding: 12px; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.15); }
        .panel textarea { width: 100%; margin: 6px 0 8px; font: inherit; font-size: 13px; }

        .hoja { position: relative; width: {{ $papel['ancho_mm'] }}mm; min-height: {{ $papel['alto_mm'] }}mm; margin: 12mm auto; padding: 14mm; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.2); }
        .hoja, .hoja * { overflow-wrap: anywhere; word-break: break-word; min-width: 0; }
        .marca { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 5; }
        .marca span { transform: rotate(-30deg); font-size: 34pt; font-weight: bold; letter-spacing: 2px; color: rgba(220, 38, 38, .28); border: 3px solid rgba(220, 38, 38, .28); padding: 4mm 8mm; text-align: center; }
        .marca.borrador span { font-size: 26pt; color: rgba(217, 119, 6, .30); border-color: rgba(217, 119, 6, .30); }
        .cartel { margin: 0 0 6mm; padding: 2mm 3mm; border: 2px solid #b45309; color: #92400e; background: #fffbeb; font-weight: bold; text-align: center; letter-spacing: 1px; font-size: 10pt; }

        .encabezado { display: flex; gap: 4mm; align-items: center; }
        .logo { height: 14mm; width: auto; }
        .clinica { font-size: 13pt; font-weight: bold; }
        .pequeno { font-size: 9pt; color: #374151; }
        .titulo { display: flex; justify-content: space-between; gap: 4mm; margin-top: 8mm; padding-bottom: 1.5mm; border-bottom: 1px solid #9ca3af; font-weight: bold; font-size: 13pt; }
        .cuerpo { margin-top: 8mm; }
        .cuerpo p { margin: 0 0 4mm; }
        .firma { margin-top: 30mm; text-align: right; }
        .linea-firma { width: 60mm; margin: 0 0 1mm auto; border-top: 1px solid #111827; }
        .pie { margin-top: 12mm; padding-top: 2mm; border-top: 1px solid #d1d5db; font-size: 8.5pt; color: #374151; }
        .huella { font-family: Consolas, 'Courier New', monospace; font-size: 7.5pt; word-break: break-all; }

        @media print {
            html, body { background: #fff; }
            .barra, .mensaje { display: none !important; }
            .hoja { margin: 0; box-shadow: none; }
            .marca span, .cartel { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <nav class="barra">
        <strong>{{ $titulo }} · {{ $constancia->etiquetaEstado() }}</strong>
        <div class="acciones">
            @if ($constancia->estaEmitida())
                <button type="button" class="boton principal" onclick="window.print()">Imprimir</button>
            @endif
            @if (\App\Support\Permisos::puedeRuta('admin.turnos.index'))
                <a class="boton" href="{{ route('admin.turnos.index') }}">Volver a Turnos</a>
            @endif
            @if ($puedeAnular)
                <details class="anular">
                    <summary class="boton peligro">Anular</summary>
                    <form method="POST" action="{{ route("admin.constancias-{$tipo}.anular", $constancia->id) }}" class="panel">
                        @csrf
                        <label for="motivo">Motivo de la anulación (obligatorio)</label>
                        <textarea id="motivo" name="motivo" rows="3" minlength="5" maxlength="300" required>{{ old('motivo') }}</textarea>
                        @error('motivo')<div class="mensaje error" style="margin:0 0 8px">{{ $message }}</div>@enderror
                        <button type="submit" class="boton peligro">Anular</button>
                    </form>
                </details>
            @endif
        </div>
    </nav>

    @foreach (['status' => 'ok', 'aviso' => 'aviso', 'error' => 'error'] as $clave => $clase)
        @if (session($clave))
            <div class="mensaje {{ $clase }}" role="status">{{ session($clave) }}</div>
        @endif
    @endforeach
    @if ($anulada)
        <div class="mensaje aviso" role="status">
            Constancia anulada el {{ Fecha::mostrar($constancia->anulada_en, conHora: true) }}. Motivo: {{ $constancia->motivo_anulacion }}
        </div>
    @endif
    @unless ($integra)
        <div class="mensaje error" role="alert">La huella de esta constancia no coincide con su contenido. No la imprima ni la entregue: avise a Administración.</div>
    @endunless

    <div class="hoja">
        @if ($anulada)
            <div class="marca" aria-hidden="true"><span>ANULADA</span></div>
        @elseif ($borrador)
            <div class="marca borrador" aria-hidden="true"><span>{{ $hoja['marca_borrador'] }}</span></div>
        @endif

        @if ($borrador)
            <div class="cartel">{{ $hoja['marca_borrador'] }}</div>
        @endif

        <header class="encabezado">
            <img src="{{ asset(config('clinexa.clinica.logo')) }}" alt="" class="logo">
            <div>
                <div class="clinica">{{ $hoja['clinica']['nombre'] }}</div>
                @if ($hoja['clinica']['direccion'])
                    <div class="pequeno">{{ $hoja['clinica']['direccion'] }}</div>
                @endif
                @if ($hoja['clinica']['telefono'])
                    <div class="pequeno">Tel. {{ $hoja['clinica']['telefono'] }}</div>
                @endif
            </div>
        </header>

        <div class="titulo">
            <span>{{ $hoja['titulo'] }} N° {{ $hoja['numero'] }}</span>
            <span>Fecha: {{ Fecha::mostrar($hoja['fecha']) }}</span>
        </div>

        <section class="cuerpo">
            <p>{{ $hoja['hecho'] }}</p>
            <p>{{ $hoja['turno'] }}</p>
        </section>

        @if (isset($hoja['profesional']))
            <div class="firma">
                <div class="linea-firma"></div>
                <div><strong>{{ $hoja['profesional']['nombre'] }}</strong> · Mat. {{ $hoja['profesional']['matricula'] }}</div>
                <div class="pequeno">Profesional del turno</div>
            </div>
        @else
            <div class="firma">
                <div class="linea-firma"></div>
                <div class="pequeno">Recepción · {{ $hoja['clinica']['nombre'] }}</div>
            </div>
        @endif

        <footer class="pie">
            <div>{{ $hoja['pie'] }}</div>
            <div>Emitida por {{ $hoja['emitida_por'] }} · Huella sha256: <span class="huella">{{ $constancia->huella }}</span></div>
            @if ($borrador)
                <div><strong>{{ $hoja['marca_borrador'] }}</strong></div>
            @endif
        </footer>
    </div>

    @if (session('imprimir') && $constancia->estaEmitida() && $integra)
        <script>
            // Recién emitida: abrir el diálogo de impresión.
            window.addEventListener('load', () => window.print());
        </script>
    @endif
</body>
</html>
