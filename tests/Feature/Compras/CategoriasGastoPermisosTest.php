<?php

/*
 * Categorías de gasto (docs/compras-gastos.md): quién las crea, edita y desactiva, la migración que suma esos permisos sin tocar los
 * demás, y el aviso del formulario de la compra cuando no hay ninguna activa. Datos inventados. Prefijo "categoriasGasto".
 */

use App\Models\CategoriaGasto;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;

beforeEach(function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
});

function categoriasGastoUsuario(string $codigo): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

function categoriasGastoMigracion(): object
{
    return require base_path('database/migrations/2026_11_07_100001_categorias_gasto_permisos.php');
}

test('Compras y tesorería, Gerencia y el Administrador crean, editan y desactivan categorías', function (string $codigo) {
    $usuario = categoriasGastoUsuario($codigo);

    $this->actingAs($usuario)->post(route('admin.categorias-gasto.store'), ['nombre' => 'Papelería de prueba'])->assertSessionHasNoErrors();
    $categoria = CategoriaGasto::where('nombre', 'Papelería de prueba')->sole();

    $this->actingAs($usuario)->put(route('admin.categorias-gasto.update', $categoria), ['nombre' => 'Papelería chica', 'estado_id' => $categoria->estado_id])->assertSessionHasNoErrors();
    expect($categoria->fresh()->nombre)->toBe('Papelería chica');

    $this->actingAs($usuario)->patch(route('admin.categorias-gasto.desactivar', $categoria))->assertSessionHasNoErrors();
    expect(CategoriaGasto::activos()->whereKey($categoria->id)->exists())->toBeFalse();
})->with([PerfilesPredefinidos::COMPRAS_TESORERIA, PerfilesPredefinidos::GERENCIA, PerfilAcceso::ADMINISTRADOR]);

test('un perfil sin el permiso no puede crear, editar ni desactivar', function (string $codigo) {
    $usuario = categoriasGastoUsuario($codigo);
    $categoria = CategoriaGasto::create(['nombre' => 'Existente de prueba']);

    $this->actingAs($usuario)->post(route('admin.categorias-gasto.store'), ['nombre' => 'Nueva de prueba'])->assertForbidden();
    $this->actingAs($usuario)->put(route('admin.categorias-gasto.update', $categoria), ['nombre' => 'Cambiada'])->assertForbidden();
    $this->actingAs($usuario)->patch(route('admin.categorias-gasto.desactivar', $categoria))->assertForbidden();
    expect(CategoriaGasto::count())->toBe(1)->and($categoria->fresh()->nombre)->toBe('Existente de prueba');
})->with([PerfilesPredefinidos::CAJA, PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::AUDITOR]);

test('la migración suma los permisos sin quitar ni cambiar otros, y es repetible', function () {
    $quitar = ['CREAR', 'EDITAR', 'DESACTIVAR'];
    foreach ([PerfilesPredefinidos::COMPRAS_TESORERIA, PerfilesPredefinidos::GERENCIA] as $codigo) {
        $perfil = PerfilAcceso::where('codigo', $codigo)->sole();
        $perfil->permisos()->detach($perfil->permisos()->whereHas('moduloSistema', fn ($q) => $q->where('codigo', 'CATEGORIAS_GASTO'))
            ->whereIn('accion', $quitar)->pluck('permisos.id'));
    }
    $antes = PerfilAcceso::whereIn('codigo', ['COMPRAS_TESORERIA', 'GERENCIA', 'CAJA'])->get()
        ->mapWithKeys(fn ($p) => [$p->codigo => $p->clavesPermisos()])->all();
    $esperadoNuevo = ['CATEGORIAS_GASTO:CREAR', 'CATEGORIAS_GASTO:DESACTIVAR', 'CATEGORIAS_GASTO:EDITAR'];

    categoriasGastoMigracion()->up();
    categoriasGastoMigracion()->up();

    foreach (['COMPRAS_TESORERIA', 'GERENCIA'] as $codigo) {
        $despues = PerfilAcceso::where('codigo', $codigo)->sole()->clavesPermisos();
        expect(collect($despues)->diff($antes[$codigo])->sort()->values()->all())->toBe($esperadoNuevo)
            ->and(collect($antes[$codigo])->diff($despues)->all())->toBe([]);
    }
    expect(PerfilAcceso::where('codigo', 'CAJA')->sole()->clavesPermisos())->toBe($antes['CAJA']);
});

test('no hay categorías base: nada las crea', function () {
    categoriasGastoMigracion()->up();

    expect(CategoriaGasto::count())->toBe(0);
});

test('sin categorías activas, el formulario de la compra avisa y enlaza al alta si hay permiso', function () {
    $this->actingAs(categoriasGastoUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA))->get(route('admin.compras.create'))
        ->assertOk()->assertSee('No hay ninguna categoría de gasto activa')
        ->assertSee(route('admin.categorias-gasto.create'), false);
});

test('sin categorías activas ni permiso de alta, el aviso no trae enlace', function () {
    $perfil = PerfilAcceso::where('codigo', PerfilesPredefinidos::COMPRAS_TESORERIA)->sole();
    $perfil->permisos()->detach($perfil->permisos()->whereHas('moduloSistema', fn ($q) => $q->where('codigo', 'CATEGORIAS_GASTO'))
        ->whereIn('accion', ['CREAR', 'EDITAR', 'DESACTIVAR'])->pluck('permisos.id'));

    $this->actingAs(categoriasGastoUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA))->get(route('admin.compras.create'))
        ->assertOk()->assertSee('No hay ninguna categoría de gasto activa')
        ->assertDontSee(route('admin.categorias-gasto.create'), false);
});

test('con una categoría activa se muestra la lista y no el aviso', function () {
    CategoriaGasto::create(['nombre' => 'Limpieza de prueba']);

    $this->actingAs(categoriasGastoUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA))->get(route('admin.compras.create'))
        ->assertOk()->assertDontSee('No hay ninguna categoría de gasto activa')->assertSee('Limpieza de prueba');
});
