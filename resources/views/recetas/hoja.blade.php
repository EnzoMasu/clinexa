{{--
    Página de la hoja de una receta (vista previa de un borrador, o receta emitida/anulada). Layout
    propio: sin menú y siempre en colores claros, aunque el sistema esté en modo oscuro. La barra de
    arriba y los avisos solo se ven en pantalla. El tamaño del papel sale de config/clinexa.php.
--}}
@php
    $papel = config('clinexa.recetas.papel');
    $mitad = $papel['alto_mm'] / 2;
    $relleno = config('clinexa.recetas.relleno_mm');
    $titulo = $receta->numero ? "Receta {$receta->numero}" : 'Vista previa de la receta';
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
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; }

        .barra { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 10px 16px; background: #fff; border-bottom: 1px solid #d1d5db; font-size: 14px; }
        .barra .acciones { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .boton { display: inline-block; padding: 6px 12px; border-radius: 6px; border: 1px solid #9ca3af; background: #fff; color: #111827; font-size: 13px; text-decoration: none; cursor: pointer; }
        .boton.principal { background: #1f2937; border-color: #1f2937; color: #fff; }
        .boton.peligro { border-color: #dc2626; color: #b91c1c; }
        .mensaje { margin: 12px auto 0; max-width: {{ $papel['ancho_mm'] }}mm; padding: 10px 12px; border-radius: 6px; font-size: 14px; }
        .mensaje.ok { background: #ecfdf5; color: #065f46; }
        .mensaje.aviso { background: #fffbeb; color: #92400e; }
        .mensaje.error, .aviso-desborde { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .aviso-desborde { display: none; margin: 12px auto 0; max-width: {{ $papel['ancho_mm'] }}mm; padding: 10px 12px; border-radius: 6px; font-size: 14px; }
        details.anular { position: relative; }
        details.anular[open] .panel { position: absolute; right: 0; z-index: 10; width: 340px; margin-top: 6px; padding: 12px; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.15); }
        .panel textarea { width: 100%; margin: 6px 0 8px; font: inherit; font-size: 13px; }

        .hoja { position: relative; width: {{ $papel['ancho_mm'] }}mm; height: {{ $papel['alto_mm'] }}mm; margin: 12mm auto; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.2); }
        /* Columna: la firma y el pie van al final; si el contenido no entra, la mitad crece (y se mide), nunca se superpone. */
        .mitad { position: relative; display: flex; flex-direction: column; height: {{ $mitad }}mm; padding: {{ $relleno }}mm; }
        /* Textos libres sin espacios (un nombre de 150 caracteres pegado): se parten dentro de la hoja, en cualquier punto,
           en lugar de salirse por el borde derecho. min-width: 0 deja que los hijos flex se achiquen para partir. */
        .mitad, .mitad * { overflow-wrap: anywhere; word-break: break-word; min-width: 0; }
        .corte { position: absolute; left: 0; right: 0; top: {{ $mitad }}mm; border-top: 1px dashed #6b7280; height: 0; }
        .corte span { position: absolute; right: {{ $relleno }}mm; top: -2.2mm; padding: 0 2mm; background: #fff; color: #6b7280; font-size: 7pt; }
        .marca { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 5; }
        .marca span { transform: rotate(-30deg); font-size: 34pt; font-weight: bold; letter-spacing: 2px; color: rgba(220, 38, 38, .28); border: 3px solid rgba(220, 38, 38, .28); padding: 4mm 8mm; white-space: nowrap; }

        .encabezado { display: flex; gap: 4mm; align-items: center; }
        .logo { height: 14mm; width: auto; }
        .clinica { font-size: 12pt; font-weight: bold; }
        .pequeno { font-size: 8.5pt; color: #374151; }
        .titulo { display: flex; justify-content: space-between; gap: 4mm; margin-top: 3mm; padding-bottom: 1mm; border-bottom: 1px solid #9ca3af; font-weight: bold; font-size: 11pt; }
        .paciente { margin-top: 2mm; }
        .medicamentos { margin: 3mm 0 0; padding-left: 6mm; }
        .medicamentos li { margin-bottom: 1mm; }
        .texto, .texto-libre { white-space: pre-line; }
        .texto { margin: 2mm 0 0; }
        .firma { margin-top: auto; padding-top: 4mm; text-align: right; }
        .linea-firma { width: 60mm; margin: 0 0 1mm auto; border-top: 1px solid #111827; }
        .subtitulo { margin: 3mm 0 1mm; font-size: 10.5pt; }
        .toma { margin-bottom: 1.5mm; }
        .rotulo { font-weight: bold; }
        .sep { color: #6b7280; }
        .indicaciones { margin: 0; padding-left: 5mm; }
        .pie { margin-top: auto; padding-top: 2mm; font-size: 8.5pt; color: #374151; text-align: right; }

        @media print {
            html, body { background: #fff; }
            .barra, .mensaje, .aviso-desborde { display: none !important; }
            .hoja { margin: 0; box-shadow: none; }
            .marca span { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <nav class="barra">
        <strong>{{ $titulo }} · {{ $receta->etiquetaEstado() }}</strong>
        <div class="acciones">
            @if ($receta->esBorrador())
                @if ($puedeEditar)
                    <a class="boton" href="{{ route('admin.recetas.edit', $receta) }}">Volver a editar</a>
                @endif
                @if ($puedeEmitir && ! $excede)
                    <form method="POST" action="{{ route('admin.recetas.emitir', $receta) }}" onsubmit="this.querySelector('button').disabled = true">
                        @csrf
                        <input type="hidden" name="version" value="{{ $receta->version() }}">
                        <button type="submit" class="boton principal">Emitir e imprimir</button>
                    </form>
                @endif
            @elseif ($receta->estaEmitida())
                <button type="button" class="boton principal" onclick="window.print()">Imprimir</button>
            @endif
            <a class="boton" href="{{ route('admin.consultas.show', $receta->consulta_id) }}">Volver a la consulta</a>
            @if ($puedeAnular)
                <details class="anular">
                    <summary class="boton peligro">Anular</summary>
                    <form method="POST" action="{{ route('admin.recetas.anular', $receta) }}" class="panel">
                        @csrf
                        <label for="motivo">Motivo de la anulación (obligatorio)</label>
                        <textarea id="motivo" name="motivo" rows="3" minlength="5" maxlength="300" required>{{ old('motivo') }}</textarea>
                        @error('motivo')<div class="mensaje error" style="margin:0 0 8px">{{ $message }}</div>@enderror
                        <button type="submit" class="boton peligro">Anular</button>
                        @if ($puedeCorregir ?? false)
                            <button type="submit" class="boton" formaction="{{ route('admin.recetas.corregir', $receta) }}">Anular y corregir</button>
                        @endif
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
    @if ($excede)
        <div class="mensaje error" role="alert">{{ \App\Support\MediaHoja::EXCEDE }}</div>
    @endif
    @if ($receta->estaAnulada())
        <div class="mensaje aviso" role="status">
            Receta anulada el {{ \App\Support\Fecha::mostrar($receta->anulada_en, conHora: true) }}. Motivo: {{ $receta->motivo_anulacion }}
        </div>
    @endif
    <div class="aviso-desborde" id="aviso-desborde" role="alert">
        El contenido no entra en una de las mitades de la hoja: al imprimir se pasaría a la otra mitad. No imprima esta hoja; acorte los textos o divida la receta.
    </div>

    @include('recetas._hoja', ['hoja' => $hoja, 'marca' => $marca])

    <script>
        // Solo en pantalla: si alguna mitad se desborda, hacia abajo o hacia la derecha (texto más largo de lo que
        // estimó el servidor), avisar. No toca el contenido: solo muestra el aviso, que no se imprime.
        (function () {
            const desbordada = [...document.querySelectorAll('.mitad')].some((mitad) => mitad.scrollHeight > mitad.clientHeight + 1 || mitad.scrollWidth > mitad.clientWidth + 1);
            if (desbordada) {
                document.getElementById('aviso-desborde').style.display = 'block';
            }
            @if (session('imprimir') && $receta->estaEmitida())
                // Recién emitida con "Emitir e imprimir": abrir el diálogo de impresión.
                if (! desbordada) { window.addEventListener('load', () => window.print()); }
            @endif
        })();
    </script>
</body>
</html>
