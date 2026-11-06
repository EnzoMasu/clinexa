<?php

namespace App\Support\LiquidacionesBeneficiarios;

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Admin\LiquidacionBeneficiarioController;
use App\Models\LineaLiquidacionBeneficiario;
use App\Models\LiquidacionBeneficiario;
use App\Models\PagoBeneficiario;
use App\Support\Auditoria;
use App\Support\Fecha;
use App\Support\Repartos\Porcentaje;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * La planilla de la liquidación a un beneficiario (docs/liquidacion-beneficiarios.md): clínica y logotipo, beneficiario (con su documento),
 * corte y período, estado, una fila por línea (concepto, N.º de prestación, fecha, procedimiento, base, porcentaje y monto; la paciente
 * solo en la del profesional, el equipo en la del dueño), el total, los pagos con el saldo y el espacio de firma. SIN nada clínico.
 *
 * Al vuelo (no se guarda; no-store) y cada generación queda en Auditoría (EXPORTAR sobre `liquidaciones_beneficiario`). Los permisos
 * (EXPORTAR sobre LIQUIDACIONES_BENEFICIARIOS, o sobre MIS_LIQUIDACIONES para la propia) los controla la ruta.
 */
final class PlanillaLiquidacionBeneficiario
{
    public static function html(LiquidacionBeneficiario $liquidacion): string
    {
        $liquidacion->loadMissing(['profesional.persona.tipoDocumento', 'proveedor.persona.tipoDocumento', 'moneda']);
        $lineas = LiquidacionBeneficiarioController::ordenarLineas($liquidacion->lineasVigentes()->get());
        $conPaciente = $liquidacion->deProfesional();
        $persona = $conPaciente ? $liquidacion->profesional->persona : $liquidacion->proveedor->persona;
        $fechas = $lineas->pluck('fecha_servicio')->filter();
        $pagos = $liquidacion->pagosVigentes()->with('medioPago')->orderBy('fecha')->orderBy('id')->get();

        return view('pdf-liquidacion.planilla-beneficiario', [
            'clinica' => config('clinexa.clinica.nombre'),
            'logo' => public_path(config('clinexa.clinica.logo')),
            'numero' => $liquidacion->id,
            'tipo' => $liquidacion->tipoTexto(),
            'estado' => $liquidacion->etiquetaEstado(),
            'beneficiario' => $persona->nombre_completo,
            'documento' => $persona->documentoPrincipal(),
            'corte' => Fecha::mostrar($liquidacion->fecha_corte),
            'periodo' => $fechas->isEmpty() ? '—' : Fecha::mostrar($fechas->min()).' al '.Fecha::mostrar($liquidacion->fecha_corte),
            'conPaciente' => $conPaciente,
            'filas' => $lineas->map(fn (LineaLiquidacionBeneficiario $linea) => [
                'concepto' => $linea->tipoTexto().($linea->tipo === LineaLiquidacionBeneficiario::SALDO_ANTERIOR ? " (liquidación N.º {$linea->saldo_de_liquidacion_id})" : ''),
                'prestacion' => $linea->prestacion_id ? "N.º {$linea->prestacion_id}" : '—',
                'fecha' => $linea->fecha_servicio ? Fecha::mostrar($linea->fecha_servicio) : '—',
                'paciente' => $conPaciente ? (string) ($linea->paciente ?? '—') : null,
                'procedimiento' => $linea->procedimiento ?? '—',
                'equipo' => $linea->equipo ?? '—',
                'base' => $linea->monto_base !== null ? $liquidacion->formatear($linea->monto_base) : '—',
                'porcentaje' => $linea->porcentaje !== null ? Porcentaje::texto($linea->porcentaje) : '—',
                'monto' => $liquidacion->formatear($linea->montoEntero()),
            ])->all(),
            'total' => $liquidacion->formatear($liquidacion->totalEntero()),
            'pagos' => $pagos->map(fn (PagoBeneficiario $pago) => [
                'fecha' => Fecha::mostrar($pago->fecha), 'medio' => $pago->medioPago->nombre, 'comprobante' => $pago->referencia ?? '—',
                'monto' => $liquidacion->formatear($pago->montoEntero()),
            ])->all(),
            'pagado' => $liquidacion->formatear($liquidacion->pagado()),
            'saldo' => $liquidacion->formatear($liquidacion->saldo()),
            'enContra' => $liquidacion->saldoEnContra() > 0 ? $liquidacion->formatear($liquidacion->saldoEnContra()) : null,
            'generadoEn' => Fecha::mostrar(Fecha::ahora(), conHora: true),
        ])->render();
    }

    public static function ejecutar(LiquidacionBeneficiario $liquidacion): Response
    {
        if ($liquidacion->anulada()) {
            throw new AccionRechazada('Esta liquidación está anulada: no hay planilla para imprimir.');
        }
        $vista = self::html($liquidacion);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        Auditoria::registrar(AccionAuditoria::EXPORTAR, 'liquidaciones_beneficiario', $liquidacion->id,
            detalle: "Planilla de la liquidación a beneficiario N.º {$liquidacion->id} ({$liquidacion->beneficiarioTexto()}, corte al ".Fecha::mostrar($liquidacion->fecha_corte).')');

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper('a4', 'landscape')->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="liquidacion-beneficiario-'.$liquidacion->id.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
