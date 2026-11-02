<?php

/*
 * El IVA de una compra (docs/compras-gastos.md): el mismo cálculo que la factura emitida, al guaraní entero, y el total como suma de
 * las tasas. Solo con un tipo que discrimina IVA; los demás, solo el total. Montos inventados. Prefijo "ivaCompra".
 */

use App\Models\TipoComprobanteCompra;
use App\Support\Compras\IvaCompra;

function ivaCompraTipo(bool $discrimina): TipoComprobanteCompra
{
    return (new TipoComprobanteCompra)->forceFill(['nombre' => 'Tipo inventado', 'discrimina_iva' => $discrimina]);
}

test('desglosa el 10 % y el 5 % con IVA incluido y suma el total', function () {
    expect(IvaCompra::desglose(110000, 21000, 0))->toBe([
        'monto_10' => 110000, 'monto_5' => 21000, 'monto_exento' => 0, 'iva_10' => 10000, 'iva_5' => 1000, 'total' => 131000,
    ]);
});

test('redondea al guaraní entero, la mitad hacia arriba, y el exento no genera IVA', function () {
    // 55 / 11 = 5; 16 / 11 = 1,45 -> 1; 17 / 11 = 1,55 -> 2; 32 / 21 = 1,52 -> 2.
    expect(IvaCompra::desglose(55, 0, 0)['iva_10'])->toBe(5)
        ->and(IvaCompra::desglose(16, 0, 0)['iva_10'])->toBe(1)
        ->and(IvaCompra::desglose(17, 0, 0)['iva_10'])->toBe(2)
        ->and(IvaCompra::desglose(0, 32, 0)['iva_5'])->toBe(2)
        ->and(IvaCompra::desglose(0, 0, 50000))->toMatchArray(['iva_10' => 0, 'iva_5' => 0, 'total' => 50000]);
});

test('rechaza montos negativos', function () {
    IvaCompra::desglose(-1, 0, 0);
})->throws(InvalidArgumentException::class);

test('con un tipo que discrimina IVA desglosa; sin la casilla guarda solo el total, sin IVA', function () {
    expect(IvaCompra::importes(ivaCompraTipo(true), 110000, 21000, 0, 999))->toMatchArray(['iva_10' => 10000, 'iva_5' => 1000, 'total' => 131000])
        ->and(IvaCompra::importes(ivaCompraTipo(false), 110000, 21000, 0, 25000))->toBe([
            'monto_10' => null, 'monto_5' => null, 'monto_exento' => null, 'iva_10' => null, 'iva_5' => null, 'total' => 25000,
        ]);
});
