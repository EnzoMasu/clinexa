<?php

namespace App\Enums;

/**
 * Condición de una compra o gasto (compras.condicion; docs/compras-gastos.md). Lista cerrada atada a la lógica de Compras
 * (constitución 1.0.1, principio IV): CONTADO se considera pagada al registrarla (sin vencimiento ni anticipo, no pasa a Cuentas por
 * Pagar); CREDITO lleva un vencimiento y puede llevar un anticipo menor al total.
 */
enum CondicionCompra: string
{
    case CONTADO = 'CONTADO';
    case CREDITO = 'CREDITO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::CONTADO => 'Contado',
            self::CREDITO => 'Crédito',
        };
    }

    public function esCredito(): bool
    {
        return $this === self::CREDITO;
    }
}
