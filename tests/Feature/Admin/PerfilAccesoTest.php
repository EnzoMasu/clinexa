<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\User;

beforeEach(function () {
    // administrador() siembra los módulos del sistema y el perfil Administrador con sus 50 permisos.
    $this->actingAs(User::factory()->administrador()->create());

    $this->personas = ModuloSistema::where('codigo', 'PERSONAS')->sole();
    $this->usuarios = ModuloSistema::where('codigo', 'USUARIOS')->sole();
    $this->usuarios->update(['es_sensible' => true]);
});

function permisosDe(PerfilAcceso $perfil): array
{
    return $perfil->fresh()->permisos()->with('moduloSistema')->get()
        ->map(fn (Permiso $permiso) => "{$permiso->moduloSistema->codigo}:{$permiso->accion}")
        ->sort()->values()->all();
}

test('las pantallas de perfiles cargan con la matriz de permisos', function () {
    $perfil = PerfilAcceso::create(['nombre' => 'Recepción']);

    $this->get(route('admin.perfiles-acceso.index'))->assertOk()->assertSee('Recepción');
    $this->get(route('admin.perfiles-acceso.create'))->assertOk();
    $this->get(route('admin.perfiles-acceso.edit', $perfil))->assertOk()
        ->assertSee('Personas')->assertSee('DESACTIVAR')->assertSee('Sensible')
        ->assertSee("permisos[{$this->personas->id}][]", false);
});

test('crear un perfil con permisos crea los permisos que falten', function () {
    // Módulo nuevo, sin ningún permiso creado todavía.
    $agenda = ModuloSistema::create(['codigo' => 'AGENDA', 'nombre' => 'Agenda']);

    $this->post(route('admin.perfiles-acceso.store'), [
        'nombre' => 'Recepción',
        'descripcion' => 'Atención al público',
        'permisos' => [$agenda->id => ['VER', 'CREAR'], $this->personas->id => ['VER']],
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.perfiles-acceso.index'));

    $perfil = PerfilAcceso::where('nombre', 'Recepción')->sole();
    expect($perfil->descripcion)->toBe('Atención al público')
        ->and(permisosDe($perfil))->toBe(['AGENDA:CREAR', 'AGENDA:VER', 'PERSONAS:VER'])
        ->and(Permiso::where('modulo_sistema_id', $agenda->id)->count())->toBe(2);
});

test('destildar quita el permiso del perfil pero no lo borra de permisos', function () {
    $otro = PerfilAcceso::create(['nombre' => 'Médicos']);
    $perfil = PerfilAcceso::create(['nombre' => 'Recepción']);
    $ver = Permiso::where(['modulo_sistema_id' => $this->personas->id, 'accion' => 'VER'])->sole();
    $perfil->permisos()->attach($ver);
    $otro->permisos()->attach($ver);

    $this->put(route('admin.perfiles-acceso.update', $perfil), [
        'nombre' => 'Recepción',
        'permisos' => [$this->usuarios->id => ['EXPORTAR']],
    ])->assertSessionHasNoErrors();

    expect(permisosDe($perfil))->toBe(['USUARIOS:EXPORTAR'])
        ->and($ver->fresh())->not->toBeNull()
        ->and(permisosDe($otro))->toBe(['PERSONAS:VER']);
});

test('reutiliza el permiso existente en vez de duplicarlo', function () {
    $antes = Permiso::count();

    $this->post(route('admin.perfiles-acceso.store'), [
        'nombre' => 'Recepción',
        'permisos' => [$this->personas->id => ['VER']],
    ])->assertSessionHasNoErrors();

    expect(Permiso::count())->toBe($antes);
});

test('guardar sin ningún tilde deja el perfil sin permisos', function () {
    $perfil = PerfilAcceso::create(['nombre' => 'Recepción']);
    $perfil->permisos()->attach(Permiso::where(['modulo_sistema_id' => $this->personas->id, 'accion' => 'VER'])->sole());

    $this->put(route('admin.perfiles-acceso.update', $perfil), ['nombre' => 'Recepción'])->assertSessionHasNoErrors();

    expect(permisosDe($perfil))->toBeEmpty();
});

test('rechaza acciones o módulos inválidos y nombres repetidos', function () {
    PerfilAcceso::create(['nombre' => 'Recepción']);
    $antes = Permiso::count();

    $this->post(route('admin.perfiles-acceso.store'), [
        'nombre' => 'Recepción',
        'permisos' => [$this->personas->id => ['BORRAR'], 9999 => ['VER']],
    ])->assertSessionHasErrors(['nombre', 'permisos', "permisos.{$this->personas->id}.0"]);

    expect(Permiso::count())->toBe($antes);
});

test('la pantalla del perfil Administrador muestra la matriz completa y bloqueada', function () {
    $admin = PerfilAcceso::where('nombre', PerfilAcceso::ADMINISTRADOR)->sole();

    $respuesta = $this->get(route('admin.perfiles-acceso.edit', $admin))->assertOk()
        ->assertSee('tiene siempre todos los permisos');

    $html = $respuesta->getContent();
    expect(substr_count($html, 'type="checkbox"'))->toBe(50)
        ->and(preg_match_all('/type="checkbox"[^>]*\schecked\s[^>]*\sdisabled\s/s', $html))->toBe(50)
        ->and($html)->toMatch('/name="nombre"[^>]*readonly/s');
});

test('editar el Administrador cambia la descripción pero no sus permisos', function () {
    $admin = PerfilAcceso::where('nombre', PerfilAcceso::ADMINISTRADOR)->sole();

    // Aunque alguien mande la matriz a mano (sin pasar por el formulario), se ignora.
    $this->put(route('admin.perfiles-acceso.update', $admin), [
        'nombre' => PerfilAcceso::ADMINISTRADOR,
        'descripcion' => 'Dirección de la clínica',
        'permisos' => [$this->personas->id => ['VER']],
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.perfiles-acceso.index'));

    expect($admin->fresh()->descripcion)->toBe('Dirección de la clínica')
        ->and($admin->permisos()->count())->toBe(50);
});

test('guardar el Administrador completa los permisos si le faltaba alguno', function () {
    $admin = PerfilAcceso::where('nombre', PerfilAcceso::ADMINISTRADOR)->sole();
    $admin->permisos()->detach($admin->permisos()->limit(7)->pluck('permisos.id'));
    $nuevo = ModuloSistema::create(['codigo' => 'AGENDA', 'nombre' => 'Agenda']);

    $this->put(route('admin.perfiles-acceso.update', $admin), ['nombre' => PerfilAcceso::ADMINISTRADOR])
        ->assertSessionHasNoErrors();

    expect($admin->permisos()->count())->toBe(55)
        ->and($admin->permisos()->where('modulo_sistema_id', $nuevo->id)->count())->toBe(5);
});

test('el nombre del perfil Administrador no se puede cambiar', function () {
    $admin = PerfilAcceso::where('nombre', PerfilAcceso::ADMINISTRADOR)->sole();

    $this->put(route('admin.perfiles-acceso.update', $admin), ['nombre' => 'Superusuario'])
        ->assertSessionHasErrors(['nombre' => 'El nombre del perfil Administrador no se puede cambiar.']);

    expect($admin->fresh()->nombre)->toBe(PerfilAcceso::ADMINISTRADOR);
});

test('los demás perfiles siguen teniendo la matriz editable', function () {
    $perfil = PerfilAcceso::create(['nombre' => 'Recepción']);

    $html = $this->get(route('admin.perfiles-acceso.edit', $perfil))->assertOk()
        ->assertDontSee('tiene siempre todos los permisos')
        ->getContent();

    // \sdisabled\s: el atributo, no la clase CSS "disabled:opacity-60".
    expect(preg_match_all('/type="checkbox"[^>]*\sdisabled\s/s', $html))->toBe(0);
});
