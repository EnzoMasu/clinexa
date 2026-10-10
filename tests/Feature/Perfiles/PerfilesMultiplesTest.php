<?php

/*
 * Varios perfiles por usuario (usuario_perfil): los permisos efectivos son la unión de los de sus perfiles
 * ACTIVOS (User::permisosEfectivos, un solo lugar); un perfil INACTIVO no aporta; sin ningún perfil activo
 * no se entra. Backfill de la migración desde users.perfil_acceso_id. Datos inventados.
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('los permisos son la unión de los de sus perfiles', function () {
    $turnos = UserFactory::perfilConPermisos(['TURNOS' => ['VER', 'CREAR']], 'Agenda');
    $personas = UserFactory::perfilConPermisos(['PERSONAS' => ['VER'], 'TURNOS' => ['VER']], 'Personas');
    $usuario = User::factory()->conPerfiles([$turnos, $personas])->create();

    expect(array_keys($usuario->permisosEfectivos()))->toEqualCanonicalizing(['TURNOS:VER', 'TURNOS:CREAR', 'PERSONAS:VER'])
        ->and($usuario->tienePermiso('PERSONAS', 'VER'))->toBeTrue()
        ->and($usuario->tienePermiso('PERSONAS', 'CREAR'))->toBeFalse();

    // Y de punta a punta: entra a las dos secciones.
    $this->actingAs($usuario);
    $this->get(route('admin.turnos.create'))->assertOk();
    $this->get(route('admin.personas.index'))->assertOk();
    $this->get(route('admin.personas.create'))->assertForbidden();
});

test('un perfil INACTIVO no aporta permisos; con otro activo el usuario sigue entrando', function () {
    $turnos = UserFactory::perfilConPermisos(['TURNOS' => ['VER']], 'Agenda');
    $personas = UserFactory::perfilConPermisos(['PERSONAS' => ['VER']], 'Personas');
    $usuario = User::factory()->conPerfiles([$turnos, $personas])->create();
    $personas->desactivar();

    $this->actingAs($usuario->fresh());
    $this->get(route('admin.turnos.index'))->assertOk();
    $this->get(route('admin.personas.index'))->assertForbidden();
    expect($usuario->fresh()->perfilesActivos()->modelKeys())->toBe([$turnos->id]);
});

test('con todos sus perfiles inactivos, o sin perfiles, no puede entrar', function (string $caso) {
    $perfil = UserFactory::perfilConPermisos(['TURNOS' => ['VER']], 'Agenda');
    $usuario = $caso === 'sin perfiles' ? User::factory()->sinPerfiles()->create() : User::factory()->conPerfiles([$perfil])->create();
    if ($caso === 'perfil inactivo') {
        $perfil->desactivar();
    }

    $this->post('/login', ['email' => $usuario->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Su usuario no tiene ningún perfil de acceso activo. Contacte al administrador.']);
    $this->assertGuest();
})->with(['perfil inactivo', 'sin perfiles']);

test('un usuario ACTIVO necesita al menos un perfil activo (formulario)', function () {
    $this->actingAs(User::factory()->administrador()->create());
    $inactivo = PerfilAcceso::create(['nombre' => 'Perfil viejo']);
    $usuario = User::factory()->conPerfiles([$inactivo])->create();
    $inactivo->desactivar();

    // Solo con el inactivo (que ya tenía): activo, no; inactivo (estado del usuario), sí.
    $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [$inactivo->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
        ->assertSessionHasErrors(['perfiles' => 'Un usuario activo necesita al menos un perfil de acceso activo.']);
    $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
        ->assertSessionHasErrors(['perfiles' => 'Elija al menos un perfil de acceso.']);
    $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [$inactivo->id], 'estado_id' => Estado::idDe(Estado::INACTIVO)])
        ->assertSessionHasNoErrors();
});

test('los perfiles y permisos se cargan una sola vez por pedido (sin N+1)', function () {
    $perfiles = collect(range(1, 4))->map(fn ($i) => UserFactory::perfilConPermisos(['TURNOS' => ['VER'], 'PERSONAS' => ['VER'], 'CONSULTORIOS' => ['VER']], "Perfil {$i}"));
    $this->actingAs(User::factory()->conPerfiles($perfiles->all())->create());
    $this->get('/dashboard')->assertOk(); // calentamiento

    $this->app['auth']->forgetGuards(); // el usuario se vuelve a leer, como en un pedido nuevo
    $this->actingAs(User::latest('id')->first());
    DB::enableQueryLog();
    $this->get('/dashboard')->assertOk(); // el menú consulta ~30 permisos
    $consultas = collect(DB::getQueryLog())->pluck('query');

    expect($consultas->filter(fn ($sql) => str_contains($sql, 'usuario_perfil'))->count())->toBe(1)
        ->and($consultas->filter(fn ($sql) => str_contains($sql, 'perfil_permiso'))->count())->toBe(1);
});

test('backfill: cada usuario recibe en usuario_perfil el perfil que tenía; la columna vieja queda', function () {
    $migracion = require database_path('migrations/2026_10_11_100001_perfiles_multiples_y_predefinidos.php');
    $usuarios = User::factory()->sinPerfiles()->count(4)->create();
    $migracion->down();
    expect(Schema::hasTable('usuario_perfil'))->toBeFalse();

    // Como en la base de antes: un perfil por usuario en users.perfil_acceso_id (uno sin perfil).
    $ahora = now();
    $perfil = fn (string $nombre) => DB::table('perfiles_acceso')->insertGetId(['nombre' => $nombre, 'estado_id' => Estado::idDe(Estado::ACTIVO), 'created_at' => $ahora, 'updated_at' => $ahora]);
    [$admin, $recepcion] = [$perfil('Administrador'), $perfil('Recepcionista')];
    foreach ($usuarios->zip([$admin, $recepcion, $recepcion, null]) as [$usuario, $perfilId]) {
        DB::table('users')->where('id', $usuario->id)->update(['perfil_acceso_id' => $perfilId]);
    }

    $migracion->up();

    // Usuario por usuario: su perfil, y nada más (sin perfil viejo, nada).
    foreach ($usuarios as $usuario) {
        $antes = DB::table('users')->where('id', $usuario->id)->value('perfil_acceso_id');
        expect(DB::table('usuario_perfil')->where('usuario_id', $usuario->id)->pluck('perfil_acceso_id')->all())->toBe($antes ? [$antes] : []);
    }
    expect(Schema::hasColumn('users', 'perfil_acceso_id'))->toBeTrue()
        ->and(DB::table('perfiles_acceso')->where('id', $admin)->value('codigo'))->toBe('ADMINISTRADOR')
        ->and(DB::table('perfiles_acceso')->where('id', $recepcion)->value('codigo'))->toBeNull(); // la adopción es del seeder
});

test('la pivote no admite el mismo par dos veces', function () {
    $perfil = PerfilAcceso::create(['nombre' => 'Agenda']);
    $usuario = User::factory()->conPerfiles([$perfil])->create();

    expect(fn () => DB::table('usuario_perfil')->insert(['usuario_id' => $usuario->id, 'perfil_acceso_id' => $perfil->id]))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('un módulo INACTIVO no da permisos aunque un perfil lo tenga', function () {
    $usuario = User::factory()->conPermisos(['PERSONAS' => ['VER'], 'TURNOS' => ['VER']])->create();
    ModuloSistema::where('codigo', 'PERSONAS')->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);

    expect($usuario->fresh()->tienePermiso('PERSONAS', 'VER'))->toBeFalse()
        ->and($usuario->fresh()->tienePermiso('TURNOS', 'VER'))->toBeTrue();
});
