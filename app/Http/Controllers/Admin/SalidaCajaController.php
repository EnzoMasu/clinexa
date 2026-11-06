<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\SalidaCaja;
use App\Support\Cobros\AnularSalida;
use App\Support\Cobros\Guaranies;
use App\Support\Cobros\RegistrarSalida;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Salidas de caja (docs/cobros-caja.md): el cajero registra retiros, gastos y pagos menores en efectivo desde su caja abierta de hoy
 * (Cobros\RegistrarSalida), y los anula dentro del plazo o, pasado, solicita la anulación (Cobros\AnularSalida). La revisión de las
 * solicitudes es la de las reversiones de cobro (CobroController). Todo vuelve al detalle de la caja.
 */
class SalidaCajaController extends Controller
{
    public function store(Request $request, Caja $caja): RedirectResponse
    {
        $datos = $request->validate([
            'categoria_gasto_id' => ['required', 'integer'],
            'beneficiario' => ['required', 'string', 'max:'.RegistrarSalida::BENEFICIARIO_MAXIMO],
            'comprobante' => ['nullable', 'string', 'max:'.RegistrarSalida::COMPROBANTE_MAXIMO],
            'concepto' => ['required', 'string', 'min:'.RegistrarSalida::CONCEPTO_MINIMO, 'max:'.RegistrarSalida::CONCEPTO_MAXIMO],
            'monto' => Guaranies::regla(),
        ], [
            'monto.regex' => Guaranies::MENSAJE,
            'categoria_gasto_id.required' => RegistrarSalida::SIN_CATEGORIA,
            'concepto.min' => 'Indique el concepto de la salida (al menos '.RegistrarSalida::CONCEPTO_MINIMO.' caracteres).',
        ], ['categoria_gasto_id' => 'categoría', 'beneficiario' => 'beneficiario', 'comprobante' => 'comprobante', 'concepto' => 'concepto', 'monto' => 'monto']);

        try {
            $salida = RegistrarSalida::ejecutar($request->user(), $caja, [...$datos, 'monto' => Guaranies::entero($datos['monto'])]);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(route('admin.cajas.show', $caja).'#salidas-caja')
            ->with('status', 'Salida registrada: '.$caja->moneda->formatear($salida->monto).' en efectivo.');
    }

    public function anular(Request $request, SalidaCaja $salida): RedirectResponse
    {
        try {
            AnularSalida::anular($request->user(), $salida, $this->motivo($request));
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->to(route('admin.cajas.show', $salida->caja_id).'#salidas-caja')
            ->with('status', 'Salida anulada: su monto volvió al efectivo de la caja.');
    }

    public function solicitarAnulacion(Request $request, SalidaCaja $salida): RedirectResponse
    {
        try {
            AnularSalida::solicitar($request->user(), $salida, $this->motivo($request));
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->to(route('admin.cajas.show', $salida->caja_id).'#salidas-caja')
            ->with('status', 'Solicitud de anulación enviada a Gerencia.');
    }

    private function motivo(Request $request): string
    {
        // Un error por salida (cada fila tiene su formulario).
        return $request->validateWithBag('salida'.$request->route('salida')->id,
            ['motivo' => ['required', 'string', 'min:'.AnularSalida::MOTIVO_MINIMO, 'max:'.AnularSalida::MOTIVO_MAXIMO]],
            ['motivo.required' => 'Indique el motivo de la anulación.', 'motivo.min' => 'Indique el motivo de la anulación (al menos '.AnularSalida::MOTIVO_MINIMO.' caracteres).'],
            ['motivo' => 'motivo'],
        )['motivo'];
    }
}
