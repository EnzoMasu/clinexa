<?php

namespace App\Support\Facturacion;

use App\Models\Factura;

/**
 * La emisión SIMULADA (docs/facturacion.md): la factura queda registrada en la base como cualquier otra, pero no se envía a ningún
 * lado, no tiene CDC ni QR y no tiene validez fiscal.
 */
final class EmisorSimulado implements EmisorDocumentos
{
    public const CODIGO = 'SIMULADO';

    public const MARCA = 'SIMULACIÓN, SIN VALIDEZ FISCAL';

    public function codigo(): string
    {
        return self::CODIGO;
    }

    public function simulado(): bool
    {
        return true;
    }

    public function emitir(Factura $factura): void
    {
        // Nada que enviar: la simulación termina con la factura guardada.
    }

    public function anular(Factura $factura): void
    {
        // Nada que comunicar.
    }
}
