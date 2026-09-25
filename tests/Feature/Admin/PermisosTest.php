<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Todas las rutas de /admin con sus parámetros de ruta resueltos a un registro de prueba.
 */
function rutasAdmin(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($ruta) => str_starts_with((string) $ruta->getName(), 'admin.'))
        ->map(fn ($ruta) => [
            'metodo' => $ruta->methods()[0],
            'uri' => '/'.preg_replace('/\{[^}]+\}/', '1', $ruta->uri()),
            'nombre' => $ruta->getName(),
        ])
        ->values()
        ->all();
}

test('un usuario con un perfil sin permisos no entra a ninguna sección de /admin', function () {
    $usuario = User::factory()->create(['perfil_acceso_id' => PerfilAcceso::create(['nombre' => 'Sin permisos'])->id]);
    $this->actingAs($usuario);

    $rutas = rutasAdmin();
    expect($rutas)->toHaveCount(56);

    foreach ($rutas as $ruta) {
        $this->call($ruta['metodo'], $ruta['uri'])
            ->assertForbidden();
    }

    $this->get('/dashboard')->assertOk()->assertDontSee('Administración');
});

test('un usuario sin perfil asignado tampoco entra', function () {
    $this->actingAs(User::factory()->create(['perfil_acceso_id' => null]));

    $this->get(route('admin.personas.index'))->assertForbidden();
    $this->get(route('admin.usuarios.index'))->assertForbidden();
});

test('la vista 403 explica el motivo', function () {
    $this->actingAs(User::factory()->conPermisos([])->create());

    $this->get(route('admin.personas.index'))
        ->assertForbidden()
        ->assertSee('No tiene permiso para acceder a esta sección.');
});

test('con VER pero sin CREAR ve el listado pero no el formulario de creación', function () {
    $this->actingAs(User::factory()->conPermisos(['PERSONAS' => ['VER']])->create());
    $personas = Persona::count(); // la del propio usuario

    $this->get(route('admin.personas.index'))
        ->assertOk()
        ->assertDontSee('Nueva persona');

    $this->get(route('admin.personas.create'))->assertForbidden();
    $this->post(route('admin.personas.store'), [])->assertForbidden();
    expect(Persona::count())->toBe($personas);
});

test('cada acción exige su propio permiso', function () {
    $tipo = TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula', 'aplica_a' => 'FISICA']);
    $persona = Persona::create([
        'tipo_persona' => 'FISICA', 'tipo_documento_id' => $tipo->id, 'nro_documento' => '1',
        'apellidos' => 'Pérez', 'nombres' => 'Ana', 'fecha_nacimiento' => '1990-01-01',
        'email' => 'ana@example.com', 'telefono' => '1', 'direccion' => 'X',
    ]);

    $this->actingAs(User::factory()->conPermisos(['PERSONAS' => ['VER', 'EDITAR']])->create());

    $this->get(route('admin.personas.index'))->assertOk()
        ->assertSee(route('admin.personas.edit', $persona))
        ->assertDontSee(route('admin.personas.desactivar', $persona));
    $this->get(route('admin.personas.edit', $persona))->assertOk();
    $this->patch(route('admin.personas.desactivar', $persona))->assertForbidden();

    expect($persona->fresh()->estado)->toBe('ACTIVO');
});

test('el menú de Administración muestra solo las secciones con permiso VER', function () {
    $this->actingAs(User::factory()->conPermisos([
        'PERSONAS' => ['VER'],
        'SUCURSALES' => ['CREAR'], // sin VER: no aparece
    ])->create());

    $this->get('/dashboard')->assertOk()
        ->assertSee('Administración')
        ->assertSee(route('admin.personas.index'))
        ->assertDontSee(route('admin.sucursales.index'))
        ->assertDontSee(route('admin.usuarios.index'));
});

test('el perfil Administrador tiene las 5 acciones en los 10 módulos y entra a todo', function () {
    $admin = User::factory()->administrador()->create();

    expect(ModuloSistema::count())->toBe(10)
        ->and($admin->perfilAcceso->permisos()->count())->toBe(50);

    $this->actingAs($admin);
    foreach (collect(rutasAdmin())->where('metodo', 'GET')->reject(fn ($ruta) => str_contains($ruta['uri'], '/1')) as $ruta) {
        $this->get($ruta['uri'])->assertOk();
    }
});

test('un módulo INACTIVO no da acceso aunque el perfil tenga el permiso', function () {
    $this->actingAs(User::factory()->administrador()->create());
    ModuloSistema::where('codigo', 'PERSONAS')->update(['estado' => 'INACTIVO']);

    // Nuevo request con el usuario recién leído (los permisos se cargan una vez por request).
    $this->actingAs(User::latest('id')->first());
    $this->get(route('admin.personas.index'))->assertForbidden();
    $this->get(route('admin.sucursales.index'))->assertOk();
});
