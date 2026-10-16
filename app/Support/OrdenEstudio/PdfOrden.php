<?php

namespace App\Support\OrdenEstudio;

use App\Enums\AccionAuditoria;
use App\Models\OrdenEstudio;
use App\Support\Auditoria;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF de la orden de estudio (docs/ordenes-estudio.md), dibujado solo desde su snapshot. Al vuelo, como el PDF clínico:
 * nada se guarda en el servidor ni en el navegador (no-store), y cada generación queda en Auditoría. Quien lo llama ya
 * verificó la huella: con una huella que no coincide no se genera.
 */
final class PdfOrden
{
    /** El HTML que dompdf convierte. Separado para poder verificar el contenido. */
    public static function html(OrdenEstudio $orden): string
    {
        return view('pdf-orden-estudio.hoja', [
            'hoja' => $orden->snapshot,
            'huella' => $orden->huella,
            'anulada' => $orden->estaAnulada(),
            'logo' => public_path(config('clinexa.clinica.logo')),
        ])->render();
    }

    public static function ejecutar(OrdenEstudio $orden): Response
    {
        $vista = self::html($orden);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        Auditoria::registrar(AccionAuditoria::EXPORTAR, 'ordenes_estudio', $orden->id, detalle: "PDF de la orden de estudio {$orden->numero}");

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper(strtolower(config('clinexa.recetas.papel.nombre')))->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="orden-estudio-'.$orden->numero.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
