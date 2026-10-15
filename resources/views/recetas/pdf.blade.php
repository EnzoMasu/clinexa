{{--
    Página de una receta con indicación de uso (vista previa de un borrador, o receta emitida/anulada): la barra de
    acciones y el PDF (App\Support\PdfClinico\PdfReceta) incrustado. El PDF es lo único que se imprime; cada vez que se
    abre, se genera de nuevo y queda en Auditoría. Las recetas anteriores siguen en recetas/hoja.
--}}
@php
    $papel = config('clinexa.recetas.papel');
    $titulo = $receta->numero ? "Receta {$receta->numero}" : 'Vista previa de la receta';
    $pdf = route('admin.recetas.pdf', $receta);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $titulo }} · {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #e5e7eb; color: #111827; color-scheme: light; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; }
        @include('recetas._estilos-barra')
        .visor { display: block; width: 100%; max-width: 900px; height: calc(100vh - 140px); min-height: 480px; margin: 12px auto; border: 1px solid #d1d5db; background: #fff; }
        .enlace { max-width: 900px; margin: 0 auto 16px; padding: 0 16px; font-size: 13px; }
    </style>
</head>
<body>
    @include('recetas._barra', ['pdf' => $pdf])

    <iframe class="visor" src="{{ $pdf }}" title="{{ $titulo }} (PDF)"></iframe>
    <p class="enlace">Si el PDF no se ve, <a href="{{ $pdf }}" target="_blank" rel="noopener">ábralo en otra pestaña</a>.</p>
</body>
</html>
