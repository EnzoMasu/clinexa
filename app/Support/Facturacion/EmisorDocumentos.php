<?php

namespace App\Support\Facturacion;

use App\Models\Factura;

/**
 * Quién emite los documentos fiscales (docs/facturacion.md). Hoy, la SIMULACIÓN (EmisorSimulado): la factura funciona igual, pero no
 * se envía a ningún lado ni tiene validez fiscal. Más adelante, un proveedor o el SIFEN implementan esta misma interfaz sin tocar el
 * resto (la numeración, el IVA, el cobro y la anulación no cambian). Se elige en AppServiceProvider con
 * `config('clinexa.facturacion.simulada')`.
 *
 * Los dos métodos corren dentro de la transacción de la emisión o de la anulación: si fallan (lanzan), no queda nada a medias.
 */
interface EmisorDocumentos
{
    /** Código que queda en la factura (`facturas.emisor_documentos`), p. ej. SIMULADO. */
    public function codigo(): string;

    /** Si sus documentos son una simulación sin validez fiscal (la factura, su PDF y la pantalla lo muestran). */
    public function simulado(): bool;

    /** Entrega la factura ya armada (con su número y sus líneas). */
    public function emitir(Factura $factura): void;

    /** Comunica la anulación de la factura. */
    public function anular(Factura $factura): void;
}
