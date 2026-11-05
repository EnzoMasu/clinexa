<?php

/*
 * Listado, confirmar y anular una compra (docs/compras-gastos.md): quién puede cada cosa, el motivo obligatorio, la auditoría con el
 * valor anterior y el nuevo, que nada se borra y que la anulada libera el número del comprobante. Datos inventados. Prefijo "flujoCompra".
 */

use App\Models\Compra;
use App\Models\Estado;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Support\Compras\FlujoCompra;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    PerfilAcceso::asegurarAdministrador();
});

function flujoCompraUsuario(string $codigo): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

test('Gerencia ve, carga, edita y anula compras; Compras y tesorería no anula', function () {
    $gerencia = flujoCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);

    foreach (['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'] as $accion) {
        expect($gerencia->tienePermiso('COMPRAS', $accion))->toBeTrue();
    }
    expect($compras->tienePermiso('COMPRAS', 'DESACTIVAR'))->toBeFalse()
        ->and($compras->tienePermiso('CONFIRMACION_COMPRAS', 'EDITAR'))->toBeFalse();
});

test('el listado lo ven los perfiles con VER y filtra por categoría', function () {
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $uno = comprasEscenarioCompra($compras, ['numero_comprobante' => 'T-1']);
    $otraCategoria = App\Models\CategoriaGasto::create(['nombre' => 'Limpieza inventada']);
    $dos = comprasEscenarioCompra($compras, ['numero_comprobante' => 'T-2', 'categoria_gasto_id' => $otraCategoria->id]);

    $this->actingAs($compras)->get(route('admin.compras.index'))->assertOk()->assertSee('Insumos inventados')->assertSee('Limpieza inventada');
    $filtrado = $this->actingAs($compras)->get(route('admin.compras.index', ['f' => ['categoria' => (string) $otraCategoria->id]]))->assertOk();
    expect($filtrado->viewData('registros')->pluck('id')->all())->toBe([$dos->id]);
    $this->actingAs(flujoCompraUsuario(PerfilesPredefinidos::MEDICO))->get(route('admin.compras.index'))->assertForbidden();
});

test('Gerencia confirma lo que cargó otra persona y queda quién y cuándo', function () {
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $gerencia = flujoCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $compra = comprasEscenarioCompra($compras);

    $this->actingAs($gerencia)->patch(route('admin.compras.confirmar', $compra))->assertSessionHas('status');

    $compra->refresh();
    expect($compra->tieneEstado(Estado::CONFIRMADO))->toBeTrue()->and($compra->confirmada_por)->toBe($gerencia->id)->and($compra->confirmada_en)->not->toBeNull();
    $log = DB::table('logs_auditoria')->where('tabla_afectada', 'compras')->where('registro_afectado_id', (string) $compra->id)->where('detalle', 'Confirmación de la compra')->first();
    expect($log)->not->toBeNull()->and($log->usuario_id)->toBe($gerencia->id);
});

test('nadie confirma lo que cargó, tampoco Gerencia ni el Administrador; el Administrador sí confirma lo de Gerencia', function () {
    $gerencia = flujoCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $admin = flujoCompraUsuario(PerfilAcceso::ADMINISTRADOR);
    $deGerencia = comprasEscenarioCompra($gerencia, ['numero_comprobante' => 'G-1']);
    $deAdmin = comprasEscenarioCompra($admin, ['numero_comprobante' => 'A-1']);

    $this->actingAs($gerencia)->patch(route('admin.compras.confirmar', $deGerencia))->assertForbidden();
    $this->actingAs($admin)->patch(route('admin.compras.confirmar', $deAdmin))->assertForbidden();
    expect($deGerencia->fresh()->esBorrador())->toBeTrue()->and($deAdmin->fresh()->esBorrador())->toBeTrue();

    $this->actingAs($admin)->patch(route('admin.compras.confirmar', $deGerencia))->assertSessionHas('status');
    expect($deGerencia->fresh()->tieneEstado(Estado::CONFIRMADO))->toBeTrue();
});

test('Compras y tesorería no confirma; una confirmada no se confirma de nuevo', function () {
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $gerencia = flujoCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $compra = comprasEscenarioCompra($compras);

    $this->actingAs($compras)->patch(route('admin.compras.confirmar', $compra))->assertForbidden();
    $this->actingAs($gerencia)->patch(route('admin.compras.confirmar', $compra));
    $this->actingAs($gerencia)->patch(route('admin.compras.confirmar', $compra))->assertSessionHas('error', FlujoCompra::SOLO_BORRADOR);
});

test('la confirmada es de solo lectura: no se edita', function () {
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $compra = comprasEscenarioCompra($compras);
    $this->actingAs(flujoCompraUsuario(PerfilesPredefinidos::GERENCIA))->patch(route('admin.compras.confirmar', $compra));

    $this->actingAs($compras)->get(route('admin.compras.edit', $compra))->assertForbidden();
});

test('anular exige motivo de 5 a 300 caracteres y queda auditado con el valor anterior y el nuevo', function () {
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $gerencia = flujoCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $compra = comprasEscenarioCompra($compras);
    $this->actingAs($gerencia)->patch(route('admin.compras.confirmar', $compra));

    $this->actingAs($gerencia)->patch(route('admin.compras.anular', $compra))->assertSessionHasErrors('motivo');
    $this->actingAs($gerencia)->patch(route('admin.compras.anular', $compra), ['motivo' => 'abc'])->assertSessionHasErrors('motivo');
    $this->actingAs($gerencia)->patch(route('admin.compras.anular', $compra), ['motivo' => str_repeat('x', 301)])->assertSessionHasErrors('motivo');
    expect($compra->fresh()->tieneEstado(Estado::CONFIRMADO))->toBeTrue();

    $this->actingAs($gerencia)->patch(route('admin.compras.anular', $compra), ['motivo' => 'Factura duplicada'])->assertSessionHas('status');

    $compra->refresh();
    expect($compra->tieneEstado(Estado::ANULADO))->toBeTrue()->and($compra->anulada_por)->toBe($gerencia->id)
        ->and($compra->anulada_en)->not->toBeNull()->and($compra->motivo_anulacion)->toBe('Factura duplicada');
    $log = DB::table('logs_auditoria')->where('tabla_afectada', 'compras')->where('registro_afectado_id', (string) $compra->id)->where('accion', 'ANULAR')->sole();
    expect($log->usuario_id)->toBe($gerencia->id)->and($log->detalle)->toBe('Motivo: Factura duplicada')
        ->and(json_decode($log->valor_anterior, true)['estado_id'])->toBe(Estado::idDe(Estado::CONFIRMADO))
        ->and(json_decode($log->valor_nuevo, true)['estado_id'])->toBe(Estado::idDe(Estado::ANULADO))
        ->and(json_decode($log->valor_nuevo, true)['motivo_anulacion'])->toBe('Factura duplicada');
    expect(Compra::count())->toBe(1);
});

test('Compras y tesorería no anula una confirmada ni lo ajeno, pero descarta su propio borrador con motivo', function () {
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $otra = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $gerencia = flujoCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $confirmada = comprasEscenarioCompra($compras, ['numero_comprobante' => 'C-1']);
    $this->actingAs($gerencia)->patch(route('admin.compras.confirmar', $confirmada));
    $ajena = comprasEscenarioCompra($otra, ['numero_comprobante' => 'C-2']);
    $propia = comprasEscenarioCompra($compras, ['numero_comprobante' => 'C-3']);

    $this->actingAs($compras)->patch(route('admin.compras.anular', $confirmada), ['motivo' => 'Cargada por error'])->assertForbidden();
    $this->actingAs($compras)->patch(route('admin.compras.anular', $ajena), ['motivo' => 'Cargada por error'])->assertForbidden();
    $this->actingAs($compras)->patch(route('admin.compras.anular', $propia), ['motivo' => 'Cargada por error'])->assertSessionHas('status');

    expect($propia->fresh()->tieneEstado(Estado::ANULADO))->toBeTrue()->and($confirmada->fresh()->tieneEstado(Estado::CONFIRMADO))->toBeTrue()
        ->and($ajena->fresh()->esBorrador())->toBeTrue();
    expect(DB::table('logs_auditoria')->where('tabla_afectada', 'compras')->where('registro_afectado_id', (string) $propia->id)->where('accion', 'ANULAR')
        ->value('detalle'))->toBe('Borrador descartado. Motivo: Cargada por error');
});

test('Gerencia descarta un borrador ajeno; una anulada no se anula de nuevo ni cambia', function () {
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $gerencia = flujoCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $compra = comprasEscenarioCompra($compras);

    $this->actingAs($gerencia)->patch(route('admin.compras.anular', $compra), ['motivo' => 'Ya no corresponde'])->assertSessionHas('status');
    $this->actingAs($gerencia)->patch(route('admin.compras.anular', $compra), ['motivo' => 'Otra vez'])->assertSessionHas('error', FlujoCompra::YA_ANULADA);
    $this->actingAs($gerencia)->patch(route('admin.compras.confirmar', $compra))->assertSessionHas('error');
    expect($compra->fresh()->motivo_anulacion)->toBe('Ya no corresponde');
});

test('la anulada libera el número del comprobante', function () {
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $gerencia = flujoCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $compra = comprasEscenarioCompra($compras, ['numero_comprobante' => 'LIBRE-1']);
    $this->actingAs($gerencia)->patch(route('admin.compras.anular', $compra), ['motivo' => 'Corregir y cargar de nuevo']);

    $nueva = comprasEscenarioCompra($compras, ['proveedor_id' => $compra->proveedor_id, 'tipo_comprobante_id' => $compra->tipo_comprobante_id, 'numero_comprobante' => 'LIBRE-1']);
    expect($nueva->exists)->toBeTrue()->and(Compra::count())->toBe(2);
});

test('el detalle muestra solo lo que cada perfil puede hacer', function () {
    $compras = flujoCompraUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $gerencia = flujoCompraUsuario(PerfilesPredefinidos::GERENCIA);
    $compra = comprasEscenarioCompra($compras);

    $this->actingAs($gerencia)->get(route('admin.compras.show', $compra))->assertSee('Confirmar compra')->assertSee('Descartar el borrador');
    $this->actingAs($compras)->get(route('admin.compras.show', $compra))->assertDontSee('Confirmar compra')->assertSee('Descartar el borrador');
    $this->actingAs($gerencia)->patch(route('admin.compras.confirmar', $compra));
    $this->actingAs($compras)->get(route('admin.compras.show', $compra))->assertDontSee('Anular la compra');
    $this->actingAs($gerencia)->get(route('admin.compras.show', $compra))->assertSee('Anular la compra')->assertDontSee('Confirmar compra');
});

test('la migración suma CREAR y EDITAR a Gerencia sin tocar lo demás y se repite sin efecto', function () {
    $gerencia = PerfilAcceso::where('codigo', PerfilesPredefinidos::GERENCIA)->sole();
    $quitar = $gerencia->permisos()->whereHas('moduloSistema', fn ($q) => $q->where('codigo', 'COMPRAS'))->whereIn('accion', ['CREAR', 'EDITAR'])->pluck('permisos.id');
    $gerencia->permisos()->detach($quitar);
    $antes = $gerencia->permisos()->count();
    $migracion = require base_path('database/migrations/2026_11_08_100001_gerencia_carga_compras.php');

    $migracion->up();
    $migracion->up();

    expect($gerencia->permisos()->count())->toBe($antes + 2)
        ->and(flujoCompraUsuario(PerfilesPredefinidos::GERENCIA)->tienePermiso('COMPRAS', 'CREAR'))->toBeTrue();
});
