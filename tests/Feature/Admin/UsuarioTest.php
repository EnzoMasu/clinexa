<?php

use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Notifications\InvitacionUsuario;
use App\Support\BuscadorPersonas;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->perfil = PerfilAcceso::create(['nombre' => 'Recepción']);
    $this->admin = User::factory()->administrador()->create();
    $this->actingAs($this->admin);
});

test('el listado muestra nombre y documento de la persona', function () {
    $usuario = User::factory()->conPersona(['apellidos' => 'Ruiz', 'nombres' => 'Liz', 'nro_documento' => '4567890'])
        ->conPerfiles([$this->perfil])->create();

    $this->get(route('admin.usuarios.index'))->assertOk()
        ->assertSeeInOrder(['Ruiz, Liz', '4567890', $usuario->email, 'Recepción']);
});

test('el buscador de usuarios busca por nombre, documento y email de la persona', function () {
    User::factory()->conPersona(['apellidos' => 'Ruiz', 'nombres' => 'Liz', 'nro_documento' => '4567890'])->create();
    User::factory()->conPersona(['apellidos' => 'Ferreira', 'nombres' => 'Diana', 'nro_documento' => '5678901'])->create(['email' => 'diana@clinexa.test']);

    $this->get(route('admin.usuarios.index', ['q' => 'liz']))->assertSee('Ruiz, Liz')->assertDontSee('Ferreira');
    $this->get(route('admin.usuarios.index', ['q' => '5678']))->assertSee('Ferreira, Diana')->assertDontSee('Ruiz');
    $this->get(route('admin.usuarios.index', ['q' => 'diana@']))->assertSee('Ferreira, Diana')->assertDontSee('Ruiz');
});

test('crear usa el selector de personas común, sin listar personas en la página', function () {
    Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Carmen']);

    $html = $this->get(route('admin.usuarios.create'))->assertOk()
        ->assertSee('Escriba al menos 2 caracteres del nombre o del documento para buscar.')
        ->assertSee('Solo aparecen personas físicas activas que todavía no tienen usuario.')
        ->assertSee('hasta 15 resultados')
        ->assertSee('name="persona_id"', false)
        ->assertDontSee('Duarte, Carmen') // no se precarga: se busca al escribir
        ->assertDontSee('<select name="persona_id"', false)
        ->assertDontSee('name="email"', false)
        ->assertDontSee('name="name"', false)
        ->assertDontSee('name="password"', false)
        ->getContent();

    expect($html)
        ->toContain(str_replace('/', '\/', route('admin.usuarios.personas-disponibles')))
        ->toContain('x-on:input.debounce.350ms="buscar()"')
        ->toContain('minimo: 2');
});

test('el buscador de personas del alta devuelve solo personas físicas, activas y sin usuario', function () {
    $disponible = Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Carmen', 'nro_documento' => '5234567']);
    Persona::factory()->inactiva()->create(['apellidos' => 'Duarte', 'nombres' => 'Inactiva']);
    User::factory()->conPersona(['apellidos' => 'Duarte', 'nombres' => 'ConUsuario'])->create();
    Persona::factory()->create([
        'tipo_persona' => 'JURIDICA', 'apellidos' => null, 'nombres' => null, 'razon_social' => 'Duarte S.A.',
        'tipo_documento_id' => TipoDocumento::create(['codigo' => 'RUC', 'nombre' => 'RUC'])->id,
    ]);

    $this->getJson(route('admin.usuarios.personas-disponibles', ['q' => 'Duarte']))->assertOk()
        ->assertExactJson([['id' => $disponible->id, 'texto' => BuscadorPersonas::texto($disponible, conEmail: true)]]);

    // También por documento.
    expect($this->getJson(route('admin.usuarios.personas-disponibles', ['q' => '52345']))->json('*.id'))->toBe([$disponible->id]);
});

test('el buscador de personas del alta muestra nombre, documento y email', function () {
    Persona::factory()->create([
        'apellidos' => 'Ruiz', 'nombres' => 'Liz', 'nro_documento' => '4567890',
        'tipo_documento_id' => TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula'])->id,
        'email' => 'liz@clinexa.test',
    ]);

    expect($this->getJson(route('admin.usuarios.personas-disponibles', ['q' => 'Ruiz']))->json('0.texto'))
        ->toBe('Ruiz, Liz — CI 4567890 (liz@clinexa.test)');
});

test('el buscador de personas del alta nunca devuelve más de 15 resultados', function () {
    Persona::factory()->count(40)->sequence(fn ($s) => ['apellidos' => 'Gómez', 'nombres' => "Persona {$s->index}"])->create();

    expect($this->getJson(route('admin.usuarios.personas-disponibles', ['q' => 'Gómez']))->assertOk()->json())
        ->toHaveCount(BuscadorPersonas::LIMITE);
});

test('con menos de 2 caracteres el buscador del alta no devuelve nada', function () {
    Persona::factory()->count(3)->create(['nombres' => 'Ana']);

    foreach (['', ' ', 'A', ' a '] as $q) {
        $this->getJson(route('admin.usuarios.personas-disponibles', ['q' => $q]))->assertOk()->assertExactJson([]);
    }

    expect($this->getJson(route('admin.usuarios.personas-disponibles', ['q' => 'An']))->json())->not->toBeEmpty();
});

test('el buscador de personas del alta exige permiso de crear usuarios', function () {
    $this->actingAs(User::factory()->conPermisos(['USUARIOS' => ['VER', 'EDITAR']])->create());

    $this->getJson(route('admin.usuarios.personas-disponibles', ['q' => 'Ana']))->assertForbidden();
});

test('tras un error de validación el selector conserva la persona elegida', function () {
    $persona = Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Carmen']);

    $this->from(route('admin.usuarios.create'))
        ->post(route('admin.usuarios.store'), ['persona_id' => $persona->id])
        ->assertSessionHasErrors('perfiles');

    // Se ve igual que en el buscador: con el email.
    $this->get(route('admin.usuarios.create'))
        ->assertSee(BuscadorPersonas::texto($persona, conEmail: true))
        ->assertSee("({$persona->email})")
        ->assertSee('value="'.$persona->id.'"', false);
});

test('sin personas disponibles, crear avisa y ofrece cargar una persona', function () {
    // La única persona física activa es la del admin, que ya tiene usuario.
    $this->get(route('admin.usuarios.create'))->assertOk()
        ->assertSee('No hay personas disponibles para crear un usuario.')
        ->assertSee(route('admin.personas.create'))
        ->assertDontSee('name="persona_id"', false);
});

test('crear un usuario toma el email de la persona y le envía la invitación', function () {
    $persona = Persona::factory()->create(['email' => 'Ana.Recepcion@Clinexa.test']);

    $this->post(route('admin.usuarios.store'), [
        'persona_id' => $persona->id,
        'perfiles' => [$this->perfil->id],
        'email' => 'otro@ignorado.test', // no se usa: el email sale de la persona
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.usuarios.index'));

    $usuario = User::where('persona_id', $persona->id)->sole();
    expect($usuario)
        ->email->toBe('ana.recepcion@clinexa.test')
        ->estado->codigo->toBe('ACTIVO')
        ->perfiles->modelKeys()->toBe([$this->perfil->id])
        ->password->not->toBeEmpty();

    Notification::assertSentTo($usuario, InvitacionUsuario::class);
});

test('no se puede crear un usuario para una persona no disponible', function (Closure $persona) {
    $this->post(route('admin.usuarios.store'), ['persona_id' => $persona()->id, 'perfiles' => [$this->perfil->id]])
        ->assertSessionHasErrors('persona_id');
})->with([
    'que ya tiene usuario' => [fn () => User::factory()->create()->persona],
    'inactiva' => [fn () => Persona::factory()->inactiva()->create()],
    'jurídica' => [fn () => Persona::factory()->create([
        'tipo_persona' => 'JURIDICA', 'razon_social' => 'Laboratorio S.A.',
        'tipo_documento_id' => TipoDocumento::create(['codigo' => 'RUC', 'nombre' => 'RUC'])->id,
    ])],
]);

test('no se puede crear un usuario si el email de la persona ya lo usa otro usuario', function () {
    $persona = Persona::factory()->create(['email' => $this->admin->email]);

    $this->post(route('admin.usuarios.store'), ['persona_id' => $persona->id, 'perfiles' => [$this->perfil->id]])
        ->assertSessionHasErrors('persona_id');
    expect(User::where('persona_id', $persona->id)->exists())->toBeFalse();
});

test('crear exige persona y perfil de acceso', function () {
    $this->post(route('admin.usuarios.store'), [])->assertSessionHasErrors(['persona_id', 'perfiles']);
});

test('el flujo completo: el usuario invitado define su contraseña y entra', function () {
    $persona = Persona::factory()->create(['email' => 'ana@clinexa.test']);
    $this->post(route('admin.usuarios.store'), ['persona_id' => $persona->id, 'perfiles' => [$this->perfil->id]]);
    auth()->logout();

    $usuario = User::where('email', 'ana@clinexa.test')->sole();
    Notification::assertSentTo($usuario, InvitacionUsuario::class, function (InvitacionUsuario $notificacion) use ($usuario) {
        $this->get(route('password.reset', $notificacion->token))->assertOk();

        $this->post(route('password.store'), [
            'token' => $notificacion->token,
            'email' => $usuario->email,
            'password' => 'MiClave$egura123',
            'password_confirmation' => 'MiClave$egura123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return true;
    });

    $this->post(route('login'), ['email' => 'ana@clinexa.test', 'password' => 'MiClave$egura123'])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($usuario);
    expect($usuario->fresh()->ultimo_acceso)->not->toBeNull();
});

test('la edición muestra la persona como solo lectura y no permite cambiarla', function () {
    $usuario = User::factory()->conPersona(['apellidos' => 'Ruiz', 'nombres' => 'Liz', 'nro_documento' => '4567890'])->create();

    $this->get(route('admin.usuarios.edit', $usuario))->assertOk()
        ->assertSeeInOrder(['Ruiz, Liz', 'CI 4567890', $usuario->email])
        ->assertDontSee('name="persona_id"', false)
        ->assertDontSee('name="email"', false)
        ->assertDontSee('name="password"', false);
});

test('editar cambia solo perfil y estado: persona, email y contraseña no se tocan', function () {
    $otroPerfil = PerfilAcceso::create(['nombre' => 'Médicos']);
    $usuario = User::factory()->create();
    [$personaAnterior, $emailAnterior, $hashAnterior] = [$usuario->persona_id, $usuario->email, $usuario->password];

    $this->put(route('admin.usuarios.update', $usuario), [
        'perfiles' => [$otroPerfil->id],
        'estado_id' => estadoId('BLOQUEADO'),
        'persona_id' => Persona::factory()->create()->id,
        'email' => 'nuevo@clinexa.test',
        'password' => 'intento-de-cambio',
    ])->assertSessionHasNoErrors();

    expect($usuario->fresh())
        ->perfiles->modelKeys()->toBe([$otroPerfil->id])
        ->estado->codigo->toBe('BLOQUEADO')
        ->persona_id->toBe($personaAnterior)
        ->email->toBe($emailAnterior)
        ->password->toBe($hashAnterior);
});

test('reenviar invitación manda de nuevo el email', function () {
    $usuario = User::factory()->create();

    $this->post(route('admin.usuarios.invitacion', $usuario))
        ->assertRedirect(route('admin.usuarios.index'))
        ->assertSessionHas('status');

    Notification::assertSentTo($usuario, InvitacionUsuario::class);
});

test('reenviar a un usuario que ya entró al sistema manda el email de restablecer contraseña', function () {
    $usuario = User::factory()->create(['ultimo_acceso' => now()]);

    $this->post(route('admin.usuarios.invitacion', $usuario))->assertSessionHas('status');

    Notification::assertSentTo($usuario, ResetPassword::class);
    Notification::assertNotSentTo($usuario, InvitacionUsuario::class);
});

test('desactivar pasa el usuario a INACTIVO sin borrarlo', function () {
    $usuario = User::factory()->create();

    $this->patch(route('admin.usuarios.desactivar', $usuario))->assertRedirect(route('admin.usuarios.index'));

    expect($usuario->fresh())->not->toBeNull()->estado->codigo->toBe('INACTIVO');
});

test('un admin no puede desactivarse ni bloquearse a sí mismo', function () {
    $this->patch(route('admin.usuarios.desactivar', $this->admin))->assertSessionHas('error');

    $perfilesPropios = $this->admin->perfiles->modelKeys();
    $this->put(route('admin.usuarios.update', $this->admin), ['perfiles' => $perfilesPropios, 'estado_id' => estadoId('BLOQUEADO')])->assertSessionHas('error');

    expect($this->admin->fresh()->estado->codigo)->toBe('ACTIVO');
});

test('un usuario no puede cambiarse su propio perfil de acceso: ni quitarse ni sumarse uno', function () {
    $perfilAdmin = $this->admin->perfiles->sole();

    foreach ([[$this->perfil->id], [$perfilAdmin->id, $this->perfil->id], []] as $perfiles) {
        $this->put(route('admin.usuarios.update', $this->admin), ['perfiles' => $perfiles, 'estado_id' => estadoId('ACTIVO')])
            ->assertSessionHas('error', 'No puede cambiar su propio perfil de acceso.');
    }

    expect($this->admin->fresh()->perfiles->modelKeys())->toBe([$perfilAdmin->id]);
});

test('editarse a uno mismo sin mandar los perfiles (casillas deshabilitadas) los conserva', function () {
    $perfilAdmin = $this->admin->perfiles->sole();

    $this->put(route('admin.usuarios.update', $this->admin), ['estado_id' => estadoId('ACTIVO')])
        ->assertSessionHasNoErrors()->assertSessionMissing('error');

    expect($this->admin->fresh()->perfiles->modelKeys())->toBe([$perfilAdmin->id]);
});

test('tampoco por el modelo: en un pedido web, asignarse o quitarse un perfil propio se rechaza', function () {
    $perfilAdmin = $this->admin->perfiles->sole();
    // Una ruta cualquiera que, dentro del pedido, intenta cambiar los perfiles del usuario logueado.
    \Illuminate\Support\Facades\Route::middleware('web')->get('/prueba-perfiles-propios/{caso}', function (string $caso) use ($perfilAdmin) {
        $usuario = auth()->user();
        match ($caso) {
            'attach' => $usuario->perfiles()->attach(test()->perfil->id),
            'detach' => $usuario->perfiles()->detach($perfilAdmin->id),
            'sync' => $usuario->perfiles()->sync([test()->perfil->id]),
        };

        return 'cambiado';
    });

    foreach (['attach', 'detach', 'sync'] as $caso) {
        $this->from('/dashboard')->get("/prueba-perfiles-propios/{$caso}")->assertRedirect('/dashboard')
            ->assertSessionHas('error', \App\Exceptions\PerfilesPropios::MENSAJE);
    }
    expect($this->admin->fresh()->perfiles->modelKeys())->toBe([$perfilAdmin->id]);
});

test('el formulario muestra los perfiles propios deshabilitados con la nota, y los de otros editables', function () {
    $html = $this->get(route('admin.usuarios.edit', $this->admin))->assertOk()
        ->assertSee('No puede cambiar su propio perfil de acceso')
        ->getContent();
    // El atributo disabled (no la clase disabled:...).
    expect($html)->toMatch('/<fieldset[^>]*\sdisabled[\s>]/')->toMatch('/name="perfiles\[\]"[^>]*\sdisabled[\s>]/');

    $otro = User::factory()->conPerfiles([$this->perfil])->create();
    $html = $this->get(route('admin.usuarios.edit', $otro))->assertOk()
        ->assertDontSee('No puede cambiar su propio perfil de acceso')
        ->getContent();
    expect($html)->not->toMatch('/name="perfiles\[\]"[^>]*\sdisabled[\s>]/')->not->toMatch('/<fieldset[^>]*\sdisabled[\s>]/');
});

test('el formulario muestra una casilla por perfil activo, con los del usuario tildados', function () {
    $otro = User::factory()->conPerfiles([$this->perfil])->create();
    $inactivo = PerfilAcceso::create(['nombre' => 'Perfil viejo', 'estado_id' => estadoId('INACTIVO')]);

    $html = $this->get(route('admin.usuarios.edit', $otro))->assertOk()
        ->assertSee('Perfiles de acceso')->assertSee('Recepción')->assertDontSee('Perfil viejo')
        ->getContent();
    expect($html)->toMatch('/name="perfiles\[\]" value="'.$this->perfil->id.'"\s+checked/')
        ->not->toContain('name="perfil_acceso_id"');

    // Un perfil inactivo que el usuario ya tiene se muestra (con su estado), tildado.
    $otro->perfiles()->attach($inactivo->id);
    $this->get(route('admin.usuarios.edit', $otro))->assertSee('Perfil viejo');
});

test('otro administrador sí puede cambiarle el perfil a un usuario', function () {
    $otro = User::factory()->administrador()->create();

    $this->put(route('admin.usuarios.update', $otro), ['perfiles' => [$this->perfil->id], 'estado_id' => estadoId('ACTIVO')])
        ->assertSessionHasNoErrors();

    expect($otro->fresh()->perfiles->modelKeys())->toBe([$this->perfil->id]);
});

test('el email de invitación está en castellano y saluda con el nombre de la persona', function () {
    $usuario = User::factory()->conPersona(['apellidos' => 'Ruiz', 'nombres' => 'Liz'])->create(['email' => 'liz@clinexa.test']);
    $mail = (new InvitacionUsuario('token-de-prueba'))->toMail($usuario);

    expect($mail->subject)->toBe('Bienvenido/a a Clinexa - Defina su contraseña')
        ->and($mail->greeting)->toBe('Estimado/a Liz Ruiz:')
        ->and($mail->actionText)->toBe('Definir mi contraseña')
        ->and($mail->actionUrl)->toContain('/reset-password/token-de-prueba')->toContain('email=liz%40clinexa.test')
        ->and($mail->introLines)->toContain('Se le creó un usuario en el sistema Clinexa.');

    // El layout del email (saludo, pie, texto del link alternativo) también sale traducido.
    $html = (string) $mail->render();
    expect($html)->toContain('Saludos')->not->toContain('Regards')
        ->not->toContain('All rights reserved')
        ->not->toContain("If you're having trouble");
});

test('sin apellido real (migración con "SIN DATO"), el saludo usa solo los nombres', function () {
    $usuario = User::factory()->conPersona(['apellidos' => 'SIN DATO', 'nombres' => 'Administrador'])->create();

    expect((new InvitacionUsuario('token'))->toMail($usuario)->greeting)->toBe('Estimado/a Administrador:');
});

test('sin persona cargada en memoria, el saludo queda en "Estimado/a:"', function () {
    expect((new InvitacionUsuario('token'))->toMail(new User(['email' => 'x@clinexa.test']))->greeting)->toBe('Estimado/a:');
});
