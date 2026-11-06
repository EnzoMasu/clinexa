<?php

namespace App\Support\Cobros;

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\CierreCajaMedio;
use App\Models\Cobro;
use App\Models\LineaCobro;
use App\Models\MovimientoCaja;
use App\Models\SalidaCaja;
use App\Support\Auditoria;
use App\Support\Fecha;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * La impresión del arqueo de una caja (docs/cobros-caja.md): clínica, fecha, cajero, sucursal (la de la caja), apertura y cierre, las
 * facturas cobradas, cada transacción con su medio y su comprobante (los cobros revertidos, marcados), las devoluciones hechas desde
 * esta caja, las salidas de caja (las anuladas, marcadas y fuera del total), los totales por medio de pago del último arqueo (esperado, declarado, diferencia), la observación (en blanco si no hay)
 * y el espacio para la firma del cajero y del gerente. Sin nada clínico.
 *
 * Al vuelo, como los demás PDF: nada se guarda (no-store) y cada generación queda en Auditoría (EXPORTAR sobre `cajas`). Solo con
 * algún cierre: la caja abierta todavía no tiene arqueo. El permiso (VER sobre CAJAS; la propia o, con VER sobre CIERRES_CAJA, cualquiera)
 * lo controlan la ruta y CajaController.
 */
final class PdfArqueo
{
    public const SIN_ARQUEO = 'La caja sigue abierta: el arqueo se imprime al cerrarla.';

    /** El HTML que dompdf convierte. Separado para poder verificar el contenido. */
    public static function html(Caja $caja): string
    {
        $caja->loadMissing(['cajero.persona', 'sucursal', 'moneda', 'estado']);
        $cierre = $caja->cierres()->with(['medios.medioPago', 'revisadoPor.persona', 'estado'])->latest('id')->first();
        if ($cierre === null) {
            throw new AccionRechazada(self::SIN_ARQUEO);
        }
        $moneda = $caja->moneda;
        $cobros = $caja->cobros()->with(['paciente.persona', 'lineas.medioPago', 'factura', 'estado'])->orderBy('cobrado_en')->orderBy('id')->get();
        $medios = $cierre->medios->sortBy(fn (CierreCajaMedio $fila) => [$fila->es_efectivo ? 0 : 1, $fila->medioPago->nombre])->values();
        $nombre = fn ($usuario) => $usuario?->persona?->nombre_completo ?? $usuario?->email ?? '';

        return view('pdf-arqueo.arqueo', [
            'clinica' => config('clinexa.clinica.nombre'),
            'logo' => public_path(config('clinexa.clinica.logo')),
            'numero' => $caja->id,
            'fecha' => Fecha::mostrar($caja->fecha),
            'cajero' => $nombre($caja->cajero),
            'sucursal' => (string) $caja->sucursal?->nombre,
            'estado' => $caja->etiquetaEstado(),
            'apertura' => Fecha::mostrar($caja->abierta_en, conHora: true),
            'cierre' => Fecha::mostrar($cierre->cerrado_en, conHora: true),
            'montoInicial' => $caja->montoInicialTexto(),
            'facturas' => $cobros->filter(fn (Cobro $cobro) => $cobro->factura !== null)->map(fn (Cobro $cobro) => [
                'numero' => $cobro->factura->numero_completo,
                'paciente' => $cobro->paciente->persona->nombre_completo,
                'total' => $moneda->formatear($cobro->total),
                'revertido' => $cobro->revertido(),
            ])->values()->all(),
            'transacciones' => $cobros->flatMap(fn (Cobro $cobro) => $cobro->lineas->sortBy('id')->map(fn (LineaCobro $linea) => [
                'hora' => Fecha::mostrar($cobro->cobrado_en, conHora: true),
                'cobro' => $cobro->id,
                'factura' => $cobro->factura?->numero_completo ?? '—',
                'paciente' => $cobro->paciente->persona->nombre_completo,
                'medio' => $linea->medioPago->nombre,
                'comprobante' => $linea->referencia ?? ($linea->medioPago->esEfectivo() ? '—' : 'Sin comprobante'),
                'monto' => $moneda->formatear($linea->monto),
                'revertido' => $cobro->revertido(),
            ]))->values()->all(),
            'devoluciones' => $caja->movimientos()->where('tipo', MovimientoCaja::REVERSION)->with(['cobro.paciente.persona', 'medioPago'])->orderBy('id')->get()
                ->map(fn (MovimientoCaja $movimiento) => [
                    'hora' => Fecha::mostrar($movimiento->registrado_en, conHora: true),
                    'cobro' => $movimiento->cobro_id,
                    'paciente' => $movimiento->cobro->paciente->persona->nombre_completo,
                    'medio' => $movimiento->medioPago->nombre,
                    'monto' => $moneda->formatear($movimiento->monto),
                ])->all(),
            'salidas' => ($salidas = $caja->salidas()->with('categoria')->orderBy('id')->get())->map(fn (SalidaCaja $salida) => [
                'hora' => Fecha::mostrar($salida->registrada_en, conHora: true),
                'categoria' => $salida->categoria->nombre,
                'beneficiario' => $salida->beneficiario,
                'comprobante' => $salida->comprobante ?? '—',
                'concepto' => $salida->concepto,
                'monto' => $moneda->formatear(-1 * $salida->monto),
                'anulada' => $salida->anulada(),
                'motivoAnulacion' => (string) $salida->motivo_anulacion,
            ])->all(),
            'totalSalidas' => $moneda->formatear(-1 * $salidas->reject(fn (SalidaCaja $salida) => $salida->anulada())->sum('monto')),
            'totales' => $medios->map(fn (CierreCajaMedio $fila) => [
                'medio' => $fila->medioPago->nombre,
                'esperado' => $moneda->formatear($fila->esperado),
                'declarado' => $moneda->formatear($fila->contado),
                'diferencia' => $moneda->formatear($fila->diferencia),
                'conDiferencia' => (float) $fila->diferencia != 0,
            ])->all(),
            'total' => [
                'esperado' => $moneda->formatear((int) round($medios->sum(fn ($fila) => (float) $fila->esperado))),
                'declarado' => $moneda->formatear((int) round($medios->sum(fn ($fila) => (float) $fila->contado))),
                'diferencia' => $moneda->formatear((int) round($medios->sum(fn ($fila) => (float) $fila->diferencia))),
            ],
            'observacion' => (string) $cierre->motivo_diferencia,
            'revision' => $cierre->revisado_en ? [
                'estado' => $cierre->etiquetaEstado(),
                'por' => $nombre($cierre->revisadoPor),
                'en' => Fecha::mostrar($cierre->revisado_en, conHora: true),
                'nota' => (string) $cierre->nota_revision,
            ] : null,
            'generadoEn' => Fecha::mostrar(Fecha::ahora(), conHora: true),
        ])->render();
    }

    public static function ejecutar(Caja $caja): Response
    {
        $vista = self::html($caja);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        Auditoria::registrar(AccionAuditoria::EXPORTAR, 'cajas', $caja->id, detalle: "Arqueo de la caja {$caja->id} del ".Fecha::mostrar($caja->fecha));

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper('a4')->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="arqueo-caja-'.$caja->id.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
