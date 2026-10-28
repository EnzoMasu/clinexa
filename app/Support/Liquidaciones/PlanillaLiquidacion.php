<?php

namespace App\Support\Liquidaciones;

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Models\Convenio;
use App\Models\LineaLiquidacion;
use App\Models\Liquidacion;
use App\Support\Auditoria;
use App\Support\Fecha;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * La planilla de la liquidación (docs/liquidaciones-aseguradoras.md): la que se presenta a la aseguradora con la factura. Clínica y
 * logotipo, aseguradora y convenio, fecha de corte y período, una fila por prestación (paciente, documento, número de asegurado, fecha,
 * procedimiento, número de visación y monto), el total y el espacio de firma. SIN diagnóstico ni nada clínico.
 *
 * Al vuelo, como los demás PDF: nada se guarda (no-store) y cada generación queda en Auditoría (EXPORTAR sobre `liquidaciones`). El
 * permiso (EXPORTAR sobre LIQUIDACIONES: Facturación y convenios, Gerencia y el Administrador) lo controla la ruta.
 */
final class PlanillaLiquidacion
{
    /** El HTML que dompdf convierte. Separado para poder verificar el contenido. */
    public static function html(Liquidacion $liquidacion): string
    {
        $liquidacion->loadMissing(['responsablePago.persona', 'convenio', 'moneda']);
        $lineas = $liquidacion->lineasVigentes()->with('prestacion:id,convenio_id')->orderBy('fecha_servicio')->orderBy('id')->get();
        $convenios = $liquidacion->convenio
            ? $liquidacion->convenio->nombre
            : Convenio::query()->whereIn('id', $lineas->pluck('prestacion.convenio_id')->unique())->orderBy('nombre')->pluck('nombre')->join(', ');

        return view('pdf-liquidacion.planilla', [
            'clinica' => config('clinexa.clinica.nombre'),
            'logo' => public_path(config('clinexa.clinica.logo')),
            'numero' => $liquidacion->id,
            'estado' => $liquidacion->etiquetaEstado(),
            'aseguradora' => $liquidacion->aseguradoraTexto(),
            'documentoAseguradora' => $liquidacion->responsablePago->persona->documentoPrincipal(),
            'convenio' => $convenios,
            'corte' => Fecha::mostrar($liquidacion->fecha_corte),
            'periodo' => $lineas->isEmpty() ? '—' : Fecha::mostrar($lineas->min('fecha_servicio')).' al '.Fecha::mostrar($liquidacion->fecha_corte),
            'filas' => $lineas->map(fn (LineaLiquidacion $linea) => [
                'paciente' => $linea->paciente,
                'documento' => $linea->documento,
                'asegurado' => $linea->numero_asegurado,
                'fecha' => Fecha::mostrar($linea->fecha_servicio),
                'procedimiento' => $linea->procedimiento,
                'visacion' => $linea->numero_visacion,
                'monto' => $liquidacion->formatear($linea->montoEntero()),
            ])->all(),
            'total' => $liquidacion->formatear($liquidacion->totalEntero()),
            'generadoEn' => Fecha::mostrar(Fecha::ahora(), conHora: true),
        ])->render();
    }

    public static function ejecutar(Liquidacion $liquidacion): Response
    {
        if ($liquidacion->anulada()) {
            throw new AccionRechazada('Esta liquidación está anulada: no hay planilla para imprimir.');
        }
        $vista = self::html($liquidacion);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        Auditoria::registrar(AccionAuditoria::EXPORTAR, 'liquidaciones', $liquidacion->id,
            detalle: "Planilla de la liquidación N.º {$liquidacion->id} ({$liquidacion->aseguradoraTexto()}, corte al ".Fecha::mostrar($liquidacion->fecha_corte).')');

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper('a4', 'landscape')->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="liquidacion-'.$liquidacion->id.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
