<?php

use App\Models\CatalogoCIE10;
use App\Models\CategoriaGasto;
use App\Models\Especialidad;
use App\Models\MedioPago;
use App\Models\Procedimiento;
use App\Models\Sucursal;
use App\Models\TipoDocumento;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
});

dataset('catalogos', [
    'especialidades' => ['especialidades', 'especialidades', Especialidad::class,
        ['nombre' => 'Cardiología', 'descripcion' => 'Corazón'], ['nombre' => 'Cardiología infantil']],
    'sucursales' => ['sucursales', 'sucursales', Sucursal::class,
        ['nombre' => 'Centro', 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '021 555 000'],
        ['nombre' => 'Centro II', 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '021 555 000', 'estado' => 'ACTIVO']],
    'tipos de documento' => ['tipos-documento', 'tipos_documento', TipoDocumento::class,
        ['codigo' => 'PAS', 'nombre' => 'Pasaporte', 'aplica_a' => 'FISICA'],
        ['codigo' => 'PAS', 'nombre' => 'Pasaporte extranjero', 'aplica_a' => 'FISICA', 'estado' => 'ACTIVO']],
    'cie10' => ['cie10', 'catalogo_cie10', CatalogoCIE10::class,
        ['codigo' => 'J06.9', 'descripcion' => 'Infección aguda de vías respiratorias superiores', 'capitulo' => 'X'],
        ['descripcion' => 'IVRS aguda, no especificada', 'capitulo' => 'X']],
    'medios de pago' => ['medios-pago', 'medios_pago', MedioPago::class,
        ['nombre' => 'Efectivo'], ['nombre' => 'Efectivo (Gs.)']],
    'categorias de gasto' => ['categorias-gasto', 'categorias_gasto', CategoriaGasto::class,
        ['nombre' => 'Insumos'], ['nombre' => 'Insumos médicos']],
    'procedimientos' => ['procedimientos', 'procedimientos', Procedimiento::class,
        ['codigo' => 'CONS-01', 'nombre' => 'Consulta general', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20],
        ['codigo' => 'CONS-01', 'nombre' => 'Consulta general', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 30, 'estado' => 'ACTIVO']],
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

test('los catálogos exigen login', function (string $ruta) {
    auth()->logout();

    $this->get(route("admin.$ruta.index"))->assertRedirect(route('login'));
})->with('catalogos');

test('desactivar pasa el estado a INACTIVO sin borrar', function (string $ruta, string $modelo, array $datos) {
    $registro = $modelo::create($datos);

    $this->patch(route("admin.$ruta.desactivar", $registro))
        ->assertRedirect(route("admin.$ruta.index"));

    expect($registro->fresh())->not->toBeNull()
        ->estado->toBe('INACTIVO');
})->with([
    'sucursales' => ['sucursales', Sucursal::class, ['nombre' => 'Centro', 'direccion' => 'X', 'telefono' => '1']],
    'tipos de documento' => ['tipos-documento', TipoDocumento::class, ['codigo' => 'RUC', 'nombre' => 'RUC', 'aplica_a' => 'AMBOS']],
    'procedimientos' => ['procedimientos', Procedimiento::class, ['codigo' => 'ECO', 'nombre' => 'Ecografía', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]],
]);

test('no existe ruta de borrado real en los catálogos', function () {
    $delete = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.') && in_array('DELETE', $route->methods()));

    expect($delete)->toBeEmpty();
});

test('el código CIE-10 no se puede cambiar al editar', function () {
    $cie10 = CatalogoCIE10::create(['codigo' => 'A09', 'descripcion' => 'Diarrea', 'capitulo' => 'I']);

    $this->put(route('admin.cie10.update', $cie10), ['codigo' => 'ZZZ', 'descripcion' => 'Diarrea y gastroenteritis', 'capitulo' => 'I']);

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
