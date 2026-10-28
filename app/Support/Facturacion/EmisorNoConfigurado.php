<?php

namespace App\Support\Facturacion;

use App\Exceptions\AccionRechazada;
use App\Models\Factura;

/** Con la simulación apagada y sin un emisor real (todavía no hay): no se emite ni se anula, con el aviso. */
final class EmisorNoConfigurado implements EmisorDocumentos
{
    public const AVISO = 'La facturación real no está configurada: no se puede emitir ni anular facturas. Vuelva a activar la simulación (CLINEXA_FACTURACION_SIMULADA).';

    public function codigo(): string
    {
        return 'SIN_EMISOR';
    }

    public function simulado(): bool
    {
        return false;
    }

    public function emitir(Factura $factura): void
    {
        throw new AccionRechazada(self::AVISO);
    }

    public function anular(Factura $factura): void
    {
        throw new AccionRechazada(self::AVISO);
    }
}
