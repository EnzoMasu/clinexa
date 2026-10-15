<?php

namespace App\Support\PdfClinico;

use App\Enums\AccionAuditoria;
use App\Models\Receta;
use App\Support\Auditoria;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF de una receta con indicación de uso (docs/pdf-clinico.md, "Receta en PDF"): A4 en dos mitades, arriba la receta
 * (medicamento y cantidad) y abajo la indicación de uso de cada medicamento. Se dibuja solo desde la hoja (HojaReceta):
 * el snapshot de una emitida o anulada, o los datos de hoy en la vista previa de un borrador. Al vuelo, como el PDF
 * clínico: nada se guarda en el servidor ni en el navegador, y cada generación queda en Auditoría.
 */
final class PdfReceta
{
    /** El HTML que dompdf convierte. Separado para poder verificar el contenido. */
    public static function html(array $hoja, ?string $marca): string
    {
        $papel = config('clinexa.recetas.papel');

        return view('pdf-receta.hoja', [
            'hoja' => $hoja,
            'marca' => $marca,
            'logo' => public_path(config('clinexa.clinica.logo')),
            'papel' => $papel,
            'mitad' => $papel['alto_mm'] / 2,
            'relleno' => config('clinexa.recetas.relleno_mm'),
        ])->render();
    }

    public static function ejecutar(Receta $receta, array $hoja, ?string $marca): Response
    {
        $vista = self::html($hoja, $marca);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        Auditoria::registrar(
            AccionAuditoria::EXPORTAR,
            'recetas',
            $receta->id,
            detalle: $receta->numero ? "PDF de la receta {$receta->numero}" : "PDF de vista previa del borrador de receta {$receta->id}",
        );

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper(strtolower(config('clinexa.recetas.papel.nombre')))->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receta-'.($receta->numero ?? 'borrador-'.$receta->id).'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
