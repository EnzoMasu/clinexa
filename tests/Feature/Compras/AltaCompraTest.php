<?php

/*
 * Alta y edición del borrador de una compra (docs/compras-gastos.md, US1): auditoría, duplicado (con enlace; las anuladas no cuentan),
 * proveedor, categoría y tipo activos, el comprobante según las casillas del tipo (nunca por nombre), IVA solo con "discrimina IVA",
 * condición, fechas e importes, y los permisos. Datos inventados. Prefijo "altaCompra".
 */

use App\Enums\AccionAuditoria;
use App\Enums\CondicionCompra;
use App\Models\CategoriaGasto;
use App\Models\Compra;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\PerfilAcceso;
use App\Models\TipoComprobanteCompra;
use App\Models\User;
use App\Support\Fecha;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;

beforeEach(function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    $this->compras = altaCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $this->proveedor = comprasEscenarioProveedor();
    $this->categoria = CategoriaGasto::create(['nombre' => 'Insumos de prueba']);
});

function altaCompraUsuario(string $codigoPerfil): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigoPerfil)->sole()])->create();
}

/** Lo que manda el formulario para un ticket al contado, con lo que se pase encima. */
function altaCompraDatos(array $encima = []): array
{
    $prueba = test();

    return [
        'proveedor_id' => $prueba->proveedor->id,
        'categoria_gasto_id' => $prueba->categoria->id,
        'fecha' => Fecha::mostrar(Fecha::hoy()->subDays(2)),
        'tipo_comprobante_id' => comprasEscenarioTipo('Ticket')->id,
        'numero_comprobante' => 'T-100',
        'fecha_comprobante' => Fecha::mostrar(Fecha::hoy()->subDays(2)),
        'total' => '25.000',
        'condicion' => 'CONTADO',
        ...$encima,
    ];
}

function altaCompraFactura(array $encima = []): array
{
    return altaCompraDatos([
        'tipo_comprobante_id' => comprasEscenarioTipo('Factura legal')->id,
        'numero_comprobante' => '001-001-0000123', 'timbrado' => '12345678',
        'monto_10' => '110000', 'monto_5' => '21000', 'monto_exento' => '5000', 'total' => null,
        ...$encima,
    ]);
}

function altaCompraGuardar(array $datos)
{
    return test()->actingAs(test()->compras)->post(route('admin.compras.store'), $datos);
}

test('el alta correcta queda en borrador, en guaraníes y auditada', function () {
    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors()->assertRedirect();

    $compra = Compra::sole();
    expect($compra->tieneEstado(Estado::PENDIENTE))->toBeTrue()
        ->and($compra->total)->toBe(25000)->and($compra->registrada_por)->toBe($this->compras->id)
        ->and($compra->moneda->codigo)->toBe('PYG')->and($compra->condicion)->toBe(CondicionCompra::CONTADO)
        ->and(LogAuditoria::where('tabla_afectada', 'compras')->where('registro_afectado_id', $compra->id)->where('accion', AccionAuditoria::CREAR)->exists())->toBeTrue();

    $this->get(route('admin.compras.show', $compra))->assertOk()->assertSee('Borrador')->assertSee('T-100');
});

test('un comprobante repetido del mismo proveedor se rechaza con el enlace a la compra existente', function () {
    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors();
    $original = Compra::sole();

    altaCompraGuardar(altaCompraDatos())->assertSessionHasErrors('numero_comprobante')->assertSessionHas('compra_existente', $original->id);
    expect(Compra::count())->toBe(1);

    $this->get(route('admin.compras.create'))->assertOk();
    $this->withSession(['compra_existente' => $original->id])->get(route('admin.compras.create'))->assertSee(route('admin.compras.show', $original), false);
});

test('el mismo número de otro proveedor o de otro tipo no es duplicado', function () {
    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors();
    altaCompraGuardar(altaCompraDatos(['proveedor_id' => comprasEscenarioProveedor()->id]))->assertSessionHasNoErrors();
    altaCompraGuardar(altaCompraDatos(['tipo_comprobante_id' => comprasEscenarioTipo('Recibo')->id]))->assertSessionHasNoErrors();

    expect(Compra::count())->toBe(3);
});

test('el duplicado se permite si la original está anulada', function () {
    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors();
    Compra::sole()->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_por' => $this->compras->id, 'anulada_en' => now(), 'motivo_anulacion' => 'Cargada por error'])->save();

    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors();
    expect(Compra::count())->toBe(2);
});

test('editar un borrador no lo toma por duplicado de sí mismo, pero sí de otra compra', function () {
    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors();
    altaCompraGuardar(altaCompraDatos(['numero_comprobante' => 'T-200']))->assertSessionHasNoErrors();
    [$primera, $segunda] = [Compra::orderBy('id')->first(), Compra::orderBy('id')->skip(1)->first()];

    $this->put(route('admin.compras.update', $primera), altaCompraDatos(['total' => '30000']))->assertSessionHasNoErrors();
    expect($primera->fresh()->total)->toBe(30000);

    $this->put(route('admin.compras.update', $segunda), altaCompraDatos())->assertSessionHasErrors('numero_comprobante');
    expect($segunda->fresh()->numero_comprobante)->toBe('T-200');
});

test('un proveedor, una categoría o un tipo inactivos no se eligen, y la compra antigua se sigue viendo', function () {
    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors();
    $compra = Compra::sole();

    $this->proveedor->desactivar();
    $this->categoria->desactivar();
    comprasEscenarioTipo('Ticket')->desactivar();

    altaCompraGuardar(altaCompraDatos(['numero_comprobante' => 'T-300']))->assertSessionHasErrors(['proveedor_id', 'categoria_gasto_id', 'tipo_comprobante_id']);
    $this->get(route('admin.compras.create'))->assertOk()->assertDontSee('Insumos de prueba');
    $this->get(route('admin.compras.show', $compra))->assertOk()->assertSee('Insumos de prueba')->assertSee('Ticket');
});

test('una factura legal exige número con formato, timbrado y fecha del comprobante', function () {
    altaCompraGuardar(altaCompraFactura(['timbrado' => '']))->assertSessionHasErrors('timbrado');
    altaCompraGuardar(altaCompraFactura(['numero_comprobante' => '123']))->assertSessionHasErrors('numero_comprobante');
    altaCompraGuardar(altaCompraFactura(['numero_comprobante' => '']))->assertSessionHasErrors('numero_comprobante');
    altaCompraGuardar(altaCompraFactura(['fecha_comprobante' => '']))->assertSessionHasErrors('fecha_comprobante');
    expect(Compra::count())->toBe(0);

    altaCompraGuardar(altaCompraFactura())->assertSessionHasNoErrors();
    expect(Compra::count())->toBe(1);
});

test('la factura legal desglosa el IVA por tasa y el total es la suma', function () {
    altaCompraGuardar(altaCompraFactura())->assertSessionHasNoErrors();

    $compra = Compra::sole();
    expect([$compra->monto_10, $compra->monto_5, $compra->monto_exento])->toBe([110000, 21000, 5000])
        ->and([$compra->iva_10, $compra->iva_5, $compra->total])->toBe([10000, 1000, 136000]);
});

test('ticket, recibo y sin comprobante guardan solo el total, sin IVA', function () {
    foreach (['Ticket' => 'T-1', 'Recibo' => 'R-1', 'Sin comprobante' => null] as $nombre => $numero) {
        altaCompraGuardar(altaCompraDatos([
            'tipo_comprobante_id' => comprasEscenarioTipo($nombre)->id, 'numero_comprobante' => $numero,
            'monto_10' => '999', 'timbrado' => '777',
        ]))->assertSessionHasNoErrors();
    }

    expect(Compra::count())->toBe(3);
    foreach (Compra::all() as $compra) {
        expect([$compra->monto_10, $compra->monto_5, $compra->monto_exento, $compra->iva_10, $compra->iva_5, $compra->timbrado])->each->toBeNull()
            ->and($compra->total)->toBe(25000);
    }
});

test('sin comprobante no lleva número aunque se lo escriba, y queda fuera del control de duplicados', function () {
    $datos = altaCompraDatos(['tipo_comprobante_id' => comprasEscenarioTipo('Sin comprobante')->id, 'numero_comprobante' => 'X-1', 'fecha_comprobante' => '']);
    altaCompraGuardar($datos)->assertSessionHasNoErrors();
    altaCompraGuardar($datos)->assertSessionHasNoErrors();

    expect(Compra::count())->toBe(2)->and(Compra::whereNotNull('numero_comprobante')->count())->toBe(0);
});

test('el control no depende del nombre del tipo: uno nuevo con las mismas casillas se comporta igual', function () {
    $factura = TipoComprobanteCompra::create(['nombre' => 'Documento inventado', 'requiere_numero' => true, 'numero_formato_legal' => true,
        'requiere_timbrado' => true, 'requiere_fecha_comprobante' => true, 'discrimina_iva' => true]);
    $ticket = TipoComprobanteCompra::create(['nombre' => 'Vale inventado', 'requiere_numero' => true, 'requiere_fecha_comprobante' => true]);

    altaCompraGuardar(altaCompraFactura(['tipo_comprobante_id' => $factura->id, 'timbrado' => '']))->assertSessionHasErrors('timbrado');
    altaCompraGuardar(altaCompraFactura(['tipo_comprobante_id' => $factura->id]))->assertSessionHasNoErrors();
    expect(Compra::sole()->iva_10)->toBe(10000);

    altaCompraGuardar(altaCompraDatos(['tipo_comprobante_id' => $ticket->id, 'numero_comprobante' => '']))->assertSessionHasErrors('numero_comprobante');
    altaCompraGuardar(altaCompraDatos(['tipo_comprobante_id' => $ticket->id]))->assertSessionHasNoErrors();
    expect(Compra::latest('id')->first()->iva_10)->toBeNull();
});

test('el crédito exige vencimiento; el contado no lleva vencimiento ni anticipo', function () {
    altaCompraGuardar(altaCompraDatos(['condicion' => 'CREDITO']))->assertSessionHasErrors('vencimiento');
    altaCompraGuardar(altaCompraDatos(['vencimiento' => Fecha::mostrar(Fecha::hoy()->addDays(30))]))->assertSessionHasErrors('vencimiento');
    altaCompraGuardar(altaCompraDatos(['anticipo' => '1000']))->assertSessionHasErrors('anticipo');
    expect(Compra::count())->toBe(0);

    altaCompraGuardar(altaCompraDatos(['condicion' => 'CREDITO', 'vencimiento' => Fecha::mostrar(Fecha::hoy()->addDays(30)), 'anticipo' => '5000']))->assertSessionHasNoErrors();
    $compra = Compra::sole();
    expect($compra->condicion)->toBe(CondicionCompra::CREDITO)->and($compra->anticipo)->toBe(5000)->and($compra->vencimiento->toDateString())->toBe(Fecha::hoy()->addDays(30)->toDateString());
});

test('fecha de compra futura, vencimiento anterior, total cero y anticipo igual al total se rechazan', function () {
    altaCompraGuardar(altaCompraDatos(['fecha' => Fecha::mostrar(Fecha::hoy()->addDay())]))->assertSessionHasErrors('fecha');
    altaCompraGuardar(altaCompraDatos(['condicion' => 'CREDITO', 'vencimiento' => Fecha::mostrar(Fecha::hoy()->subDays(5))]))->assertSessionHasErrors('vencimiento');
    altaCompraGuardar(altaCompraDatos(['total' => '0']))->assertSessionHasErrors('total');
    altaCompraGuardar(altaCompraDatos(['total' => '']))->assertSessionHasErrors('total');
    altaCompraGuardar(altaCompraDatos(['condicion' => 'CREDITO', 'vencimiento' => Fecha::mostrar(Fecha::hoy()->addDays(30)), 'anticipo' => '25000']))->assertSessionHasErrors('anticipo');
    altaCompraGuardar(altaCompraFactura(['monto_10' => '0', 'monto_5' => '0', 'monto_exento' => '0']))->assertSessionHasErrors('monto_10');

    expect(Compra::count())->toBe(0);
});

test('la fecha del comprobante futura se rechaza; una anterior, igual o posterior a la de compra (sin ser futura) se acepta', function () {
    altaCompraGuardar(altaCompraDatos(['fecha_comprobante' => Fecha::mostrar(Fecha::hoy()->addDay())]))->assertSessionHasErrors('fecha_comprobante');

    $fecha = Fecha::hoy()->subDays(5);
    foreach (['T-A' => $fecha->copy()->subDays(10), 'T-B' => $fecha, 'T-C' => Fecha::hoy()] as $numero => $delComprobante) {
        altaCompraGuardar(altaCompraDatos(['fecha' => Fecha::mostrar($fecha), 'numero_comprobante' => $numero, 'fecha_comprobante' => Fecha::mostrar($delComprobante)]))
            ->assertSessionHasNoErrors();
    }
    expect(Compra::count())->toBe(3);
});

test('una compra de días anteriores se acepta', function () {
    altaCompraGuardar(altaCompraDatos(['fecha' => Fecha::mostrar(Fecha::hoy()->subMonths(2)), 'fecha_comprobante' => Fecha::mostrar(Fecha::hoy()->subMonths(2))]))->assertSessionHasNoErrors();
    expect(Compra::count())->toBe(1);
});

test('solo el borrador se edita; confirmada o anulada, no', function () {
    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors();
    $compra = Compra::sole();

    $this->get(route('admin.compras.edit', $compra))->assertOk();
    $this->put(route('admin.compras.update', $compra), altaCompraDatos(['total' => '40000']))->assertRedirect(route('admin.compras.show', $compra));
    expect($compra->fresh()->total)->toBe(40000);

    $gerente = altaCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $compra->forceFill(['estado_id' => Estado::idDe(Estado::CONFIRMADO), 'confirmada_por' => $gerente->id, 'confirmada_en' => now()])->save();
    $this->get(route('admin.compras.edit', $compra))->assertForbidden();
    $this->put(route('admin.compras.update', $compra), altaCompraDatos(['total' => '50000']))->assertForbidden();
    expect($compra->fresh()->total)->toBe(40000);
    $this->get(route('admin.compras.show', $compra))->assertOk()->assertDontSee('Editar borrador');
});

test('la edición queda auditada', function () {
    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors();
    $compra = Compra::sole();
    $this->put(route('admin.compras.update', $compra), altaCompraDatos(['total' => '30000']))->assertSessionHasNoErrors();

    expect(LogAuditoria::where('tabla_afectada', 'compras')->where('registro_afectado_id', $compra->id)->where('accion', AccionAuditoria::EDITAR)->exists())->toBeTrue();
});

test('quien no tiene permiso sobre Compras no entra; Gerencia ve y carga', function () {
    $recepcion = altaCompraUsuario(PerfilesPredefinidos::RECEPCION);
    $this->actingAs($recepcion)->get(route('admin.compras.create'))->assertForbidden();
    $this->post(route('admin.compras.store'), altaCompraDatos())->assertForbidden();

    altaCompraGuardar(altaCompraDatos())->assertSessionHasNoErrors();
    $compra = Compra::sole();
    $this->actingAs($recepcion)->get(route('admin.compras.show', $compra))->assertForbidden();

    $gerente = altaCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $this->actingAs($gerente)->get(route('admin.compras.show', $compra))->assertOk();
    $this->get(route('admin.compras.create'))->assertOk();
    $this->get(route('admin.compras.edit', $compra))->assertOk();
    $this->post(route('admin.compras.store'), altaCompraDatos(['numero_comprobante' => 'T-9']))->assertSessionHasNoErrors();
});

test('los importes aceptan puntos de miles y los textos de error están en usted', function () {
    altaCompraGuardar(altaCompraDatos(['total' => '1.250.000']))->assertSessionHasNoErrors();
    expect(Compra::sole()->total)->toBe(1250000);

    altaCompraGuardar(altaCompraDatos(['total' => 'abc', 'numero_comprobante' => 'T-2']))->assertSessionHasErrors(['total' => 'Escriba el monto en guaraníes, sin decimales (por ejemplo, 150000 o 150.000).']);
});
