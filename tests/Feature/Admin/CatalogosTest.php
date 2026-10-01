<?php

use App\Models\CatalogoCIE10;
use App\Models\CategoriaGasto;
use App\Models\CategoriaProveedor;
use App\Models\Especialidad;
use App\Models\MedioPago;
use App\Models\PerfilAcceso;
use App\Models\Procedimiento;
use App\Models\Sucursal;
use App\Models\TipoDocumento;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
});

dataset('catalogos', [
    'especialidades' => ['especialidades', 'especialidades', Especialidad::class,
        ['nombre' => 'Cardiología', 'descripcion' => 'Corazón'], fn () => ['nombre' => 'Cardiología infantil', 'estado_id' => estadoId('ACTIVO')]],
    'sucursales' => ['sucursales', 'sucursales', Sucursal::class,
        ['nombre' => 'Centro', 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '021 555 000'],
        fn () => ['nombre' => 'Centro II', 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '021 555 000', 'estado_id' => estadoId('ACTIVO')]],
    'tipos de documento' => ['tipos-documento', 'tipos_documento', TipoDocumento::class,
        ['codigo' => 'PAS', 'nombre' => 'Pasaporte'],
        fn () => ['codigo' => 'PAS', 'nombre' => 'Pasaporte extranjero', 'estado_id' => estadoId('ACTIVO')]],
    'cie10' => ['cie10', 'catalogo_cie10', CatalogoCIE10::class,
        ['codigo' => 'J06.9', 'descripcion' => 'Infección aguda de vías respiratorias superiores', 'capitulo' => 'X'],
        fn () => ['descripcion' => 'IVRS aguda, no especificada', 'capitulo' => 'X', 'estado_id' => estadoId('ACTIVO')]],
    'medios de pago' => ['medios-pago', 'medios_pago', MedioPago::class,
        ['nombre' => 'Efectivo'], fn () => ['nombre' => 'Efectivo (Gs.)', 'estado_id' => estadoId('ACTIVO')]],
    'categorias de gasto' => ['categorias-gasto', 'categorias_gasto', CategoriaGasto::class,
        ['nombre' => 'Insumos'], fn () => ['nombre' => 'Insumos médicos', 'estado_id' => estadoId('ACTIVO')]],
    'categorias de proveedor' => ['categorias-proveedor', 'categorias_proveedor', CategoriaProveedor::class,
        ['nombre' => 'Equipos'], fn () => ['nombre' => 'Equipos médicos', 'estado_id' => estadoId('ACTIVO')]],
    'procedimientos' => ['procedimientos', 'procedimientos', Procedimiento::class,
        ['codigo' => 'CONS-01', 'nombre' => 'Consulta general', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20],
        fn () => ['codigo' => 'CONS-01', 'nombre' => 'Consulta general', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 30, 'estado_id' => estadoId('ACTIVO')]],
]);

test('el CRUD del catálogo funciona', function (string $ruta, string $tabla, string $modelo, array $alta, array $cambios) {
    $this->get(route("admin.$ruta.index"))->assertOk();
    $this->get(route("admin.$ruta.create"))->assertOk();

    $this->post(route("admin.$ruta.store"), $alta)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route("admin.$ruta.index"));
    $this->assertDatabaseHas($tabla, $alta);

    $registro = $modelo::where($alta)->sole(); // el recién creado (puede haber otros, como el CI del usuario de prueba)
    $this->get(route("admin.$ruta.edit", $registro))->assertOk();
    $this->get(route("admin.$ruta.index"))->assertSee(reset($alta));

    $this->put(route("admin.$ruta.update", $registro), $cambios)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route("admin.$ruta.index"));
    $this->assertDatabaseHas($tabla, $cambios);
})->with('catalogos');

// Firma completa: Pest resuelve la closure de $cambios según los parámetros declarados.
test('los catálogos exigen login', function (string $ruta, string $tabla, string $modelo, array $alta, array $cambios) {
    auth()->logout();

    $this->get(route("admin.$ruta.index"))->assertRedirect(route('login'));
})->with('catalogos');

dataset('catalogos con estado', [
    'perfiles de acceso' => ['perfiles-acceso', PerfilAcceso::class, ['nombre' => 'Recepción']],
    'especialidades' => ['especialidades', Especialidad::class, ['nombre' => 'Cardiología']],
    'sucursales' => ['sucursales', Sucursal::class, ['nombre' => 'Centro', 'direccion' => 'X', 'telefono' => '1']],
    'tipos de documento' => ['tipos-documento', TipoDocumento::class, ['codigo' => 'RUC', 'nombre' => 'RUC']],
    'cie10' => ['cie10', CatalogoCIE10::class, ['codigo' => 'J06.9', 'descripcion' => 'IVRS aguda', 'capitulo' => 'X']],
    'medios de pago' => ['medios-pago', MedioPago::class, ['nombre' => 'Efectivo']],
    'categorias de gasto' => ['categorias-gasto', CategoriaGasto::class, ['nombre' => 'Insumos']],
    'categorias de proveedor' => ['categorias-proveedor', CategoriaProveedor::class, ['nombre' => 'Equipos médicos']],
    'procedimientos' => ['procedimientos', Procedimiento::class, ['codigo' => 'ECO', 'nombre' => 'Ecografía', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]],
]);

test('un registro nuevo entra ACTIVO', function (string $ruta, string $modelo, array $datos) {
    $this->post(route("admin.$ruta.store"), $datos)->assertSessionHasNoErrors();

    expect($modelo::where($datos)->sole()->estado->codigo)->toBe('ACTIVO');
})->with('catalogos con estado');

test('desactivar pasa el estado a INACTIVO sin borrar', function (string $ruta, string $modelo, array $datos) {
    $registro = $modelo::create($datos);

    $this->patch(route("admin.$ruta.desactivar", $registro))
        ->assertRedirect(route("admin.$ruta.index"))
        ->assertSessionHas('status');

    expect($registro->fresh())->not->toBeNull()
        ->estado->codigo->toBe('INACTIVO');
})->with('catalogos con estado');

test('el listado muestra el estado y el botón Desactivar solo en los activos', function (string $ruta, string $modelo, array $datos) {
    $registro = $modelo::create($datos);
    $desactivar = route("admin.$ruta.desactivar", $registro);

    $this->get(route("admin.$ruta.index"))->assertOk()->assertSee('ACTIVO')->assertSee($desactivar);

    $registro->update(['estado_id' => estadoId('INACTIVO')]);
    $this->get(route("admin.$ruta.index"))->assertOk()->assertSee('INACTIVO')->assertDontSee($desactivar);
})->with('catalogos con estado');

test('un registro INACTIVO se reactiva desde la edición', function (string $ruta, string $modelo, array $datos) {
    $registro = $modelo::create([...$datos, 'estado_id' => estadoId('INACTIVO')]);

    $this->get(route("admin.$ruta.edit", $registro))->assertOk()->assertSee('name="estado_id"', false);

    $campos = collect($datos)->except('codigo')->all(); // el código del CIE-10 no se reenvía al editar
    $this->put(route("admin.$ruta.update", $registro), [...$datos, ...$campos, 'estado_id' => estadoId('ACTIVO')])
        ->assertSessionHasNoErrors();

    expect($registro->fresh()->estado->codigo)->toBe('ACTIVO');
})->with('catalogos con estado');

test('editar exige el estado y solo acepta ACTIVO o INACTIVO', function (string $ruta, string $modelo, array $datos) {
    $registro = $modelo::create($datos);

    $this->put(route("admin.$ruta.update", $registro), [...$datos, 'estado_id' => estadoId('PAGADO')])
        ->assertSessionHasErrors('estado_id');
    $this->put(route("admin.$ruta.update", $registro), collect($datos)->except('estado_id')->all())
        ->assertSessionHasErrors('estado_id');

    expect($registro->fresh()->estado->codigo)->toBe('ACTIVO');
})->with('catalogos con estado');

test('buscar "activo" no filtra por estado: el estado no es criterio de búsqueda', function () {
    Especialidad::create(['nombre' => 'Cardiología']);
    Especialidad::create(['nombre' => 'Pediatría', 'estado_id' => estadoId('INACTIVO')]);

    $this->get(route('admin.especialidades.index', ['q' => 'activo']))
        ->assertSee('No hay resultados para «activo».');
});

test('no existe ruta de borrado real en los catálogos', function () {
    $delete = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.') && in_array('DELETE', $route->methods()));

    expect($delete)->toBeEmpty();
});

test('el código CIE-10 no se puede cambiar al editar', function () {
    $cie10 = CatalogoCIE10::create(['codigo' => 'A09', 'descripcion' => 'Diarrea', 'capitulo' => 'I']);

    $this->put(route('admin.cie10.update', $cie10), ['codigo' => 'ZZZ', 'descripcion' => 'Diarrea y gastroenteritis', 'capitulo' => 'I', 'estado_id' => estadoId('ACTIVO')]);

    $this->assertDatabaseHas('catalogo_cie10', ['codigo' => 'A09', 'descripcion' => 'Diarrea y gastroenteritis']);
    $this->assertDatabaseMissing('catalogo_cie10', ['codigo' => 'ZZZ']);
});

test('el buscador del CIE-10 filtra por código y descripción', function () {
    CatalogoCIE10::create(['codigo' => 'J06.9', 'descripcion' => 'Infección respiratoria', 'capitulo' => 'X']);
    CatalogoCIE10::create(['codigo' => 'A09', 'descripcion' => 'Diarrea', 'capitulo' => 'I']);

    $this->get(route('admin.cie10.index', ['q' => 'J06']))->assertSee('J06.9')->assertDontSee('A09');
    $this->get(route('admin.cie10.index', ['q' => 'diarrea']))->assertSee('A09')->assertDontSee('J06.9');
});

test('los códigos únicos se validan', function () {
    Procedimiento::create(['codigo' => 'ECO', 'nombre' => 'Ecografía', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);

    $this->post(route('admin.procedimientos.store'), ['codigo' => 'ECO', 'nombre' => 'Otra', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 10])
        ->assertSessionHasErrors('codigo');
});
