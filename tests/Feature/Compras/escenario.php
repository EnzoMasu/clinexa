<?php

use App\Enums\CondicionCompra;
use App\Models\CategoriaGasto;
use App\Models\Compra;
use App\Models\Moneda;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\TipoComprobanteCompra;
use App\Models\User;

/*
 * Ayudas de los tests de Compras (docs/compras-gastos.md). Datos inventados. Prefijo "comprasEscenario".
 */

function comprasEscenarioProveedor(): Proveedor
{
    return Proveedor::create(['persona_id' => Persona::factory()->create()->id]);
}

function comprasEscenarioTipo(string $nombre): TipoComprobanteCompra
{
    return TipoComprobanteCompra::where('nombre', $nombre)->sole();
}

/** Una compra en borrador de un ticket al contado, con lo que se pase encima. */
function comprasEscenarioCompra(User $registradaPor, array $datos = []): Compra
{
    return Compra::create([
        'proveedor_id' => comprasEscenarioProveedor()->id,
        'categoria_gasto_id' => CategoriaGasto::firstOrCreate(['nombre' => 'Insumos inventados'])->id,
        'fecha' => '2026-10-05',
        'tipo_comprobante_id' => comprasEscenarioTipo('Ticket')->id,
        'numero_comprobante' => 'T-0001',
        'fecha_comprobante' => '2026-10-05',
        'moneda_id' => Moneda::guarani()->id,
        'total' => 25000,
        'condicion' => CondicionCompra::CONTADO,
        'anticipo' => 0,
        'registrada_por' => $registradaPor->id,
        ...$datos,
    ]);
}
