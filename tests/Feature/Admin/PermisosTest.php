<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
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
    expect($rutas)->toHaveCount(142); // 21 secciones × 6 rutas (todas con baja) + auditoría (listado y detalle, solo lectura) + invitación + 5 buscadores de personas (usuarios y los 4 roles) + buscador de profesionales de disponibilidades + turnos (listado, alta, guardar, horarios libres, 2 buscadores, cambio de estado)

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
    $this->post(route('admin.personas.store'), enFormulario([]))->assertForbidden();
    expect(Persona::count())->toBe($personas);
});

test('cada acción exige su propio permiso', function () {
    $tipo = TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
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

    expect($persona->fresh()->estado->codigo)->toBe('ACTIVO');
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

test('el Administrador ve los 4 grupos del menú, en orden y con sus secciones, en escritorio y en mobile', function () {
    $this->actingAs(User::factory()->administrador()->create());

    $grupos = [
        'Seguridad' => ['usuarios', 'perfiles-acceso', 'auditoria'],
        'Personas y Roles' => ['personas', 'pacientes', 'profesionales', 'proveedores', 'categorias-proveedor', 'responsables-pago'],
        'Agenda' => ['turnos', 'disponibilidades', 'consultorios', 'origenes-turno'],
        'Catálogos' => ['especialidades', 'sucursales', 'tipos-documento', 'procedimientos', 'medios-pago', 'categorias-gasto', 'cie10', 'paises', 'departamentos', 'ciudades'],
    ];

    // Cada encabezado seguido de sus links, en el desplegable de escritorio y en el menú de mobile.
    foreach (['data-grupo-menu', 'data-grupo-menu-movil'] as $atributo) {
        $esperado = collect($grupos)->flatMap(fn (array $secciones, string $grupo) => [
            $atributo.'="'.$grupo.'"',
            ...array_map(fn (string $seccion) => 'href="'.route("admin.{$seccion}.index").'"', $secciones),
        ])->all();

        $this->get('/dashboard')->assertOk()->assertSeeInOrder($esperado, false);
    }

    // No queda ninguna sección de /admin con listado fuera del menú.
    $enMenu = collect($grupos)->flatten()->map(fn (string $seccion) => "admin.{$seccion}.index")->sort()->values()->all();
    $conListado = collect(rutasAdmin())->pluck('nombre')->filter(fn (string $nombre) => str_ends_with($nombre, '.index'))->sort()->values()->all();
    expect($enMenu)->toBe($conListado);
});

test('un perfil con permisos en un solo grupo no ve los encabezados de los grupos vacíos', function () {
    $this->actingAs(User::factory()->conPermisos([
        'TURNOS' => ['VER'],
        'CONSULTORIOS' => ['VER'],
        'ESPECIALIDADES' => ['CREAR'], // sin VER: no cuenta para Catálogos
    ])->create());

    $html = $this->get('/dashboard')->assertOk()
        ->assertSee(route('admin.turnos.index'))->assertSee(route('admin.consultorios.index'))
        ->assertDontSee(route('admin.disponibilidades.index'))
        ->getContent();

    expect($html)->toContain('data-grupo-menu="Agenda"')->toContain('data-grupo-menu-movil="Agenda"');
    foreach (['Seguridad', 'Personas y Roles', 'Catálogos'] as $grupo) {
        expect($html)->not->toContain('data-grupo-menu="'.$grupo.'"')->not->toContain('data-grupo-menu-movil="'.$grupo.'"');
    }
});

test('el perfil Administrador tiene las 5 acciones en todos los módulos y entra a todo', function () {
    $admin = User::factory()->administrador()->create();

    expect(ModuloSistema::count())->toBe(22)
        ->and($admin->perfilAcceso->permisos()->count())->toBe(ModuloSistema::count() * count(Permiso::ACCIONES));

    $this->actingAs($admin);
    foreach (collect(rutasAdmin())->where('metodo', 'GET')->reject(fn ($ruta) => str_contains($ruta['uri'], '/1')) as $ruta) {
        $this->get($ruta['uri'])->assertOk();
    }
});

test('un módulo INACTIVO no da acceso aunque el perfil tenga el permiso', function () {
    $this->actingAs(User::factory()->administrador()->create());
    ModuloSistema::where('codigo', 'PERSONAS')->update(['estado_id' => estadoId('INACTIVO')]);

    // Nuevo request con el usuario recién leído (los permisos se cargan una vez por request).
    $this->actingAs(User::latest('id')->first());
    $this->get(route('admin.personas.index'))->assertForbidden();
    $this->get(route('admin.sucursales.index'))->assertOk();
});
