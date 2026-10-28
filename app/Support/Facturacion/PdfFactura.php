<?php

namespace App\Support\Facturacion;

use App\Enums\AccionAuditoria;
use App\Models\Clinica;
use App\Models\DetalleFactura;
use App\Models\Factura;
use App\Support\Auditoria;
use App\Support\Fecha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * El PDF de la factura (docs/facturacion.md): clínica y logotipo, RUC, timbrado y su vigencia, número, fecha y hora, cliente, líneas,
 * totales por tasa, condición CONTADO y, si es simulada, "SIMULACIÓN, SIN VALIDEZ FISCAL". Todo sale del snapshot de la factura (el
 * logotipo, de la clínica actual). Sin CDC ni QR. Sin nada clínico (solo el nombre del procedimiento).
 *
 * Al vuelo, como los demás PDF: nada se guarda (no-store) y cada generación queda en Auditoría (EXPORTAR sobre `facturas`, sin montos
 * en el detalle). Se genera de nuevo (copia) mientras la factura exista; la anulada sale marcada. El permiso (EXPORTAR sobre FACTURAS)
 * lo controla la ruta.
 */
final class PdfFactura
{
    /** El HTML que dompdf convierte. Separado para poder verificar el contenido. */
    public static function html(Factura $factura, bool $copia = false): string
    {
        $factura->loadMissing(['detalles', 'moneda', 'estado']);
        $clinica = Clinica::actual();

        return view('pdf-factura.factura', [
            'factura' => $factura,
            'logo' => Clinica::rutaLogotipo($clinica),
            'marcaSimulacion' => $factura->simulada ? EmisorSimulado::MARCA : null,
            'anulada' => $factura->anulada(),
            'copia' => $copia,
            'fecha' => Fecha::mostrar($factura->emitida_en, conHora: true),
            'vigencia' => Fecha::mostrar($factura->timbrado_desde).' al '.Fecha::mostrar($factura->timbrado_hasta),
            'lineas' => $factura->detalles->sortBy('id')->map(fn (DetalleFactura $detalle) => [
                'descripcion' => $detalle->descripcion,
                'cantidad' => $detalle->cantidad,
                'precio' => $factura->formatear($detalle->precio_unitario),
                'exenta' => $detalle->tasa_iva === 0 ? $factura->formatear($detalle->monto) : '',
                'cinco' => $detalle->tasa_iva === 5 ? $factura->formatear($detalle->monto) : '',
                'diez' => $detalle->tasa_iva === 10 ? $factura->formatear($detalle->monto) : '',
            ])->values()->all(),
            'totales' => collect(Factura::TOTALES)->map(fn (string $texto, string $columna) => [$texto, $factura->formatear($factura->{$columna})])->values()->all(),
            'subtotales' => [
                'exenta' => $factura->formatear($factura->exento),
                'cinco' => $factura->formatear($factura->entero('gravado_5') + $factura->entero('iva_5')),
                'diez' => $factura->formatear($factura->entero('gravado_10') + $factura->entero('iva_10')),
            ],
            'ivaTotal' => $factura->formatear($factura->entero('iva_5') + $factura->entero('iva_10')),
            'total' => $factura->totalTexto(),
            'generadoEn' => Fecha::mostrar(Fecha::ahora(), conHora: true),
        ])->render();
    }

    public static function ejecutar(Factura $factura): Response
    {
        $copia = DB::table('logs_auditoria')->where('tabla_afectada', 'facturas')->where('registro_afectado_id', (string) $factura->id)
            ->where('accion', AccionAuditoria::EXPORTAR->value)->exists();
        $vista = self::html($factura, $copia);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        Auditoria::registrar(AccionAuditoria::EXPORTAR, 'facturas', $factura->id,
            detalle: "PDF de la factura {$factura->numero_completo}".($copia ? ' (copia)' : ''));

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper('a4')->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="factura-'.$factura->numero_completo.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
