<?php

/*
 * El catálogo de tipos de comprobante de compra (docs/compras-gastos.md): la siembra inicial con sus casillas, el alta, la edición y la
 * desactivación (solo el Administrador), la lectura para los demás perfiles, el formato legal solo con número y la auditoría. Nada se
 * borra. Datos inventados. Prefijo "tiposComprobanteCompra".
 */

use App\Enums\AccionAuditoria;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\PerfilAcceso;
use App\Models\TipoComprobanteCompra;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;

beforeEach(function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    $this->admin = tiposComprobanteCompraUsuario(PerfilAcceso::ADMINISTRADOR);
});

function tiposComprobanteCompraUsuario(string $codigoPerfil): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigoPerfil)->sole()])->create();
}

test('la migración siembra factura legal, ticket, recibo y sin comprobante con sus casillas, activos', function () {
    $casillas = fn (string $nombre) => TipoComprobanteCompra::where('nombre', $nombre)->sole()->only(array_keys(TipoComprobanteCompra::CASILLAS));

    expect($casillas('Factura legal'))->toBe(['requiere_numero' => true, 'numero_formato_legal' => true, 'requiere_timbrado' => true, 'requiere_fecha_comprobante' => true, 'discrimina_iva' => true])
        ->and($casillas('Ticket'))->toBe(['requiere_numero' => true, 'numero_formato_legal' => false, 'requiere_timbrado' => false, 'requiere_fecha_comprobante' => true, 'discrimina_iva' => false])
        ->and($casillas('Recibo'))->toBe($casillas('Ticket'))
        ->and(array_filter($casillas('Sin comprobante')))->toBe([])
        ->and(TipoComprobanteCompra::activos()->count())->toBe(TipoComprobanteCompra::count());
});

test('el Administrador crea, edita y desactiva un tipo; queda auditado y no se borra', function () {
    $this->actingAs($this->admin)->post(route('admin.tipos-comprobante-compra.store'), [
        'nombre' => 'Boleta inventada', 'requiere_numero' => '1', 'requiere_fecha_comprobante' => '1',
    ])->assertRedirect(route('admin.tipos-comprobante-compra.index'));

    $tipo = TipoComprobanteCompra::where('nombre', 'Boleta inventada')->sole();
    expect($tipo->requiere_numero)->toBeTrue()->and($tipo->discrimina_iva)->toBeFalse()->and($tipo->estaActivo())->toBeTrue();

    $this->put(route('admin.tipos-comprobante-compra.update', $tipo), [
        'nombre' => 'Boleta inventada', 'requiere_numero' => '1', 'discrimina_iva' => '1', 'estado_id' => $tipo->estado_id,
    ])->assertRedirect();
    expect($tipo->fresh()->discrimina_iva)->toBeTrue()->and($tipo->fresh()->requiere_fecha_comprobante)->toBeFalse();

    $this->patch(route('admin.tipos-comprobante-compra.desactivar', $tipo))->assertRedirect();
    expect($tipo->fresh()->tieneEstado(Estado::INACTIVO))->toBeTrue();

    $acciones = LogAuditoria::where('tabla_afectada', 'tipos_comprobante_compra')->where('registro_afectado_id', $tipo->id)->pluck('accion')
        ->map(fn ($accion) => $accion instanceof AccionAuditoria ? $accion : AccionAuditoria::from($accion))->all();
    expect($acciones)->toContain(AccionAuditoria::CREAR, AccionAuditoria::EDITAR, AccionAuditoria::DESACTIVAR);
});

test('el formato legal solo se exige con número, y el nombre no se repite', function () {
    $this->actingAs($this->admin)->from(route('admin.tipos-comprobante-compra.create'))
        ->post(route('admin.tipos-comprobante-compra.store'), ['nombre' => 'Sin número inventado', 'numero_formato_legal' => '1'])
        ->assertSessionHasErrors('numero_formato_legal');

    $this->post(route('admin.tipos-comprobante-compra.store'), ['nombre' => 'Ticket'])->assertSessionHasErrors('nombre');
    expect(TipoComprobanteCompra::where('nombre', 'Sin número inventado')->exists())->toBeFalse();
});

test('los demás perfiles ven el catálogo pero no lo cambian', function () {
    $tipo = TipoComprobanteCompra::where('nombre', 'Ticket')->sole();

    foreach ([PerfilesPredefinidos::COMPRAS_TESORERIA, PerfilesPredefinidos::GERENCIA, PerfilesPredefinidos::CAJA] as $perfil) {
        $this->actingAs(tiposComprobanteCompraUsuario($perfil));
        $this->get(route('admin.tipos-comprobante-compra.index'))->assertOk()->assertSee('Factura legal');
        $this->get(route('admin.tipos-comprobante-compra.create'))->assertForbidden();
        $this->post(route('admin.tipos-comprobante-compra.store'), ['nombre' => 'No debe quedar'])->assertForbidden();
        $this->get(route('admin.tipos-comprobante-compra.edit', $tipo))->assertForbidden();
        $this->patch(route('admin.tipos-comprobante-compra.desactivar', $tipo))->assertForbidden();
    }

    expect($tipo->fresh()->estaActivo())->toBeTrue()->and(TipoComprobanteCompra::where('nombre', 'No debe quedar')->exists())->toBeFalse();
});

test('el Administrador ve el formulario con las casillas', function () {
    $this->actingAs($this->admin)->get(route('admin.tipos-comprobante-compra.create'))->assertOk()
        ->assertSee('Requiere timbrado')->assertSee('Discrimina IVA');
});
