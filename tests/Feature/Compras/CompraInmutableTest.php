<?php

/*
 * La compra solo se edita en borrador (docs/compras-gastos.md): confirmada o anulada es de solo lectura, solo avanza por su flujo
 * (confirmar, anular, descartar) y nada se borra; tampoco un tipo de comprobante. Datos inventados. Prefijo "compraInmutable".
 * (En tests/Feature y no en tests/Unit: necesita la base.)
 */

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Compra;
use App\Models\Estado;
use App\Models\User;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(ModuloSistemaSeeder::class);
    $this->compras = User::factory()->create();
    $this->gerente = User::factory()->create();
});

function compraInmutableConfirmar(Compra $compra, User $usuario): void
{
    $compra->forceFill(['estado_id' => Estado::idDe(Estado::CONFIRMADO), 'confirmada_por' => $usuario->id, 'confirmada_en' => now()])->save();
}

function compraInmutableAnular(Compra $compra, User $usuario): void
{
    $compra->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_por' => $usuario->id, 'anulada_en' => now(), 'motivo_anulacion' => 'Cargada por error'])->save();
}

test('nace en borrador y en borrador se edita', function () {
    $compra = comprasEscenarioCompra($this->compras);

    expect($compra->fresh()->tieneEstado(Estado::PENDIENTE))->toBeTrue()
        ->and($compra->etiquetaEstado())->toBe('Borrador');

    $compra->update(['total' => 30000, 'observaciones' => 'Corregido']);
    expect($compra->fresh()->total)->toBe(30000);
});

test('confirmada no se edita, solo se anula; anulada no cambia más', function () {
    $compra = comprasEscenarioCompra($this->compras);
    compraInmutableConfirmar($compra, $this->gerente);

    expect(fn () => $compra->update(['total' => 1]))->toThrow(RegistroEconomicoInmutable::class);
    $compra->refresh();

    compraInmutableAnular($compra, $this->gerente);
    expect($compra->fresh()->tieneEstado(Estado::ANULADO))->toBeTrue();

    expect(fn () => $compra->fresh()->update(['observaciones' => 'Otra']))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => compraInmutableConfirmar($compra->fresh(), $this->gerente))->toThrow(RegistroEconomicoInmutable::class);
});

test('el borrador se descarta (anulada con motivo) y la confirmación no cambia otros campos a la vez', function () {
    $descartada = comprasEscenarioCompra($this->compras);
    compraInmutableAnular($descartada, $this->compras);
    expect($descartada->fresh()->tieneEstado(Estado::ANULADO))->toBeTrue();

    $otra = comprasEscenarioCompra($this->compras, ['numero_comprobante' => 'T-0002']);
    expect(fn () => $otra->forceFill(['estado_id' => Estado::idDe(Estado::CONFIRMADO), 'confirmada_por' => $this->gerente->id,
        'confirmada_en' => now(), 'total' => 1])->save())->toThrow(RegistroEconomicoInmutable::class);
});

test('ni la compra ni el tipo de comprobante se borran', function () {
    $compra = comprasEscenarioCompra($this->compras);

    expect(fn () => $compra->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $compra->tipoComprobante->delete())->toThrow(RegistroEconomicoInmutable::class);
    $this->assertDatabaseHas('compras', ['id' => $compra->id]);
});

test('el comprobante no se repite entre las no anuladas; anulada la original, se puede volver a cargar; sin número no choca', function () {
    $original = comprasEscenarioCompra($this->compras);
    $proveedor = $original->proveedor_id;

    expect(fn () => comprasEscenarioCompra($this->compras, ['proveedor_id' => $proveedor]))->toThrow(QueryException::class);

    compraInmutableAnular($original, $this->compras);
    expect(comprasEscenarioCompra($this->compras, ['proveedor_id' => $proveedor])->exists)->toBeTrue();

    $sin = comprasEscenarioTipo('Sin comprobante')->id;
    comprasEscenarioCompra($this->compras, ['proveedor_id' => $proveedor, 'tipo_comprobante_id' => $sin, 'numero_comprobante' => null, 'fecha_comprobante' => null]);
    expect(comprasEscenarioCompra($this->compras, ['proveedor_id' => $proveedor, 'tipo_comprobante_id' => $sin, 'numero_comprobante' => null, 'fecha_comprobante' => null])->exists)->toBeTrue();
});
