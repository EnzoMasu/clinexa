<?php

use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\User;
use App\Notifications\InvitacionUsuario;
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
        ->create(['perfil_acceso_id' => $this->perfil->id]);

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

test('crear muestra solo personas físicas, activas y sin usuario', function () {
    $disponible = Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Carmen']);
    Persona::factory()->inactiva()->create(['apellidos' => 'Inactiva', 'nombres' => 'Persona']);
    User::factory()->conPersona(['apellidos' => 'ConUsuario', 'nombres' => 'Ya'])->create();

    $this->get(route('admin.usuarios.create'))->assertOk()
        ->assertSee('Duarte, Carmen')
        ->assertSee('value="'.$disponible->id.'"', false)
        ->assertDontSee('Inactiva, Persona')
        ->assertDontSee('ConUsuario, Ya')
        ->assertDontSee('name="email"', false)
        ->assertDontSee('name="name"', false)
        ->assertDontSee('name="password"', false);
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
        'perfil_acceso_id' => $this->perfil->id,
        'email' => 'otro@ignorado.test', // no se usa: el email sale de la persona
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.usuarios.index'));

    $usuario = User::where('persona_id', $persona->id)->sole();
    expect($usuario)
        ->email->toBe('ana.recepcion@clinexa.test')
        ->estado->toBe('ACTIVO')
        ->perfil_acceso_id->toBe($this->perfil->id)
        ->password->not->toBeEmpty();

    Notification::assertSentTo($usuario, InvitacionUsuario::class);
});

test('no se puede crear un usuario para una persona no disponible', function (Closure $persona) {
    $this->post(route('admin.usuarios.store'), ['persona_id' => $persona()->id, 'perfil_acceso_id' => $this->perfil->id])
        ->assertSessionHasErrors('persona_id');
})->with([
    'que ya tiene usuario' => [fn () => User::factory()->create()->persona],
    'inactiva' => [fn () => Persona::factory()->inactiva()->create()],
    'jurídica' => [fn () => Persona::factory()->create([
        'tipo_persona' => 'JURIDICA', 'razon_social' => 'Laboratorio S.A.',
        'tipo_documento_id' => App\Models\TipoDocumento::create(['codigo' => 'RUC', 'nombre' => 'RUC', 'aplica_a' => 'AMBOS'])->id,
    ])],
]);

test('no se puede crear un usuario si el email de la persona ya lo usa otro usuario', function () {
    $persona = Persona::factory()->create(['email' => $this->admin->email]);

    $this->post(route('admin.usuarios.store'), ['persona_id' => $persona->id, 'perfil_acceso_id' => $this->perfil->id])
        ->assertSessionHasErrors('persona_id');
    expect(User::where('persona_id', $persona->id)->exists())->toBeFalse();
});

test('crear exige persona y perfil de acceso', function () {
    $this->post(route('admin.usuarios.store'), [])->assertSessionHasErrors(['persona_id', 'perfil_acceso_id']);
});

test('el flujo completo: el usuario invitado define su contraseña y entra', function () {
    $persona = Persona::factory()->create(['email' => 'ana@clinexa.test']);
    $this->post(route('admin.usuarios.store'), ['persona_id' => $persona->id, 'perfil_acceso_id' => $this->perfil->id]);
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
        'perfil_acceso_id' => $otroPerfil->id,
        'estado' => 'BLOQUEADO',
        'persona_id' => Persona::factory()->create()->id,
        'email' => 'nuevo@clinexa.test',
        'password' => 'intento-de-cambio',
    ])->assertSessionHasNoErrors();

    expect($usuario->fresh())
        ->perfil_acceso_id->toBe($otroPerfil->id)
        ->estado->toBe('BLOQUEADO')
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

    expect($usuario->fresh())->not->toBeNull()->estado->toBe('INACTIVO');
});

test('un admin no puede desactivarse ni bloquearse a sí mismo', function () {
    $this->patch(route('admin.usuarios.desactivar', $this->admin))->assertSessionHas('error');

    $this->put(route('admin.usuarios.update', $this->admin), ['estado' => 'BLOQUEADO'])->assertSessionHas('error');

    expect($this->admin->fresh()->estado)->toBe('ACTIVO');
});

test('un usuario no puede cambiarse su propio perfil de acceso', function () {
    $perfilOriginal = $this->admin->perfil_acceso_id;

    $this->put(route('admin.usuarios.update', $this->admin), [
        'perfil_acceso_id' => $this->perfil->id, 'estado' => 'ACTIVO',
    ])->assertSessionHas('error', 'No puede cambiar su propio perfil de acceso.');

    expect($this->admin->fresh()->perfil_acceso_id)->toBe($perfilOriginal);
});

test('editarse a uno mismo sin mandar el perfil (campo deshabilitado) conserva el perfil', function () {
    $perfilOriginal = $this->admin->perfil_acceso_id;

    $this->put(route('admin.usuarios.update', $this->admin), ['estado' => 'ACTIVO'])
        ->assertSessionHasNoErrors()->assertSessionMissing('error');

    expect($this->admin->fresh()->perfil_acceso_id)->toBe($perfilOriginal);
});

test('el formulario muestra el perfil propio deshabilitado con la nota, y el de otros editable', function () {
    $this->get(route('admin.usuarios.edit', $this->admin))->assertOk()
        ->assertSee('No puede cambiar su propio perfil de acceso')
        ->assertSeeInOrder(['name="perfil_acceso_id"', 'disabled'], false);

    $otro = User::factory()->create(['perfil_acceso_id' => $this->perfil->id]);
    $html = $this->get(route('admin.usuarios.edit', $otro))->assertOk()
        ->assertDontSee('No puede cambiar su propio perfil de acceso')
        ->getContent();
    expect($html)->not->toMatch('/<select[^>]*name="perfil_acceso_id"[^>]*\sdisabled\s/s');
});

test('otro administrador sí puede cambiarle el perfil a un usuario', function () {
    $otro = User::factory()->administrador()->create();

    $this->put(route('admin.usuarios.update', $otro), ['perfil_acceso_id' => $this->perfil->id, 'estado' => 'ACTIVO'])
        ->assertSessionHasNoErrors();

    expect($otro->fresh()->perfil_acceso_id)->toBe($this->perfil->id);
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
