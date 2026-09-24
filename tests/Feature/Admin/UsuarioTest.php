<?php

use App\Models\PerfilAcceso;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->perfil = PerfilAcceso::create(['nombre' => 'Recepción']);
    $this->admin = User::factory()->administrador()->create();
    $this->actingAs($this->admin);
});

test('las pantallas de usuarios cargan', function () {
    User::factory()->create(['perfil_acceso_id' => $this->perfil->id]);

    $this->get(route('admin.usuarios.index'))->assertOk()->assertSee($this->admin->email)->assertSee('Recepción');
    $this->get(route('admin.usuarios.create'))->assertOk()->assertDontSee('name="password"', false);
    $this->get(route('admin.usuarios.edit', $this->admin))->assertOk()->assertDontSee('name="password"', false);
});

test('crear un usuario le envía el email para definir la contraseña', function () {
    $this->post(route('admin.usuarios.store'), [
        'name' => 'Ana Recepcionista',
        'email' => 'ana@clinexa.test',
        'perfil_acceso_id' => $this->perfil->id,
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.usuarios.index'));

    $usuario = User::where('email', 'ana@clinexa.test')->sole();
    expect($usuario)
        ->estado->toBe('ACTIVO')
        ->perfil_acceso_id->toBe($this->perfil->id)
        ->password->not->toBeEmpty();

    Notification::assertSentTo($usuario, ResetPassword::class);
});

test('el flujo completo: el usuario invitado define su contraseña y entra', function () {
    $this->post(route('admin.usuarios.store'), [
        'name' => 'Ana', 'email' => 'ana@clinexa.test', 'perfil_acceso_id' => $this->perfil->id,
    ]);
    auth()->logout();

    $usuario = User::where('email', 'ana@clinexa.test')->sole();
    Notification::assertSentTo($usuario, ResetPassword::class, function (ResetPassword $notificacion) use ($usuario) {
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

test('crear exige perfil de acceso y email único', function () {
    $this->post(route('admin.usuarios.store'), ['name' => 'X', 'email' => $this->admin->email])
        ->assertSessionHasErrors(['email', 'perfil_acceso_id']);
});

test('editar cambia nombre, email, perfil y estado sin tocar la contraseña', function () {
    $otroPerfil = PerfilAcceso::create(['nombre' => 'Médicos']);
    $usuario = User::factory()->create();
    $hashAnterior = $usuario->password;

    $this->put(route('admin.usuarios.update', $usuario), [
        'name' => 'Nuevo nombre',
        'email' => 'nuevo@clinexa.test',
        'perfil_acceso_id' => $otroPerfil->id,
        'estado' => 'BLOQUEADO',
        'password' => 'intento-de-cambio',
    ])->assertSessionHasNoErrors();

    expect($usuario->fresh())
        ->name->toBe('Nuevo nombre')
        ->email->toBe('nuevo@clinexa.test')
        ->perfil_acceso_id->toBe($otroPerfil->id)
        ->estado->toBe('BLOQUEADO')
        ->password->toBe($hashAnterior);
});

test('reenviar invitación manda de nuevo el email', function () {
    $usuario = User::factory()->create();

    $this->post(route('admin.usuarios.invitacion', $usuario))
        ->assertRedirect(route('admin.usuarios.index'))
        ->assertSessionHas('status');

    Notification::assertSentTo($usuario, ResetPassword::class);
});

test('desactivar pasa el usuario a INACTIVO sin borrarlo', function () {
    $usuario = User::factory()->create();

    $this->patch(route('admin.usuarios.desactivar', $usuario))->assertRedirect(route('admin.usuarios.index'));

    expect($usuario->fresh())->not->toBeNull()->estado->toBe('INACTIVO');
});

test('un admin no puede desactivarse ni bloquearse a sí mismo', function () {
    $this->patch(route('admin.usuarios.desactivar', $this->admin))->assertSessionHas('error');

    $this->put(route('admin.usuarios.update', $this->admin), [
        'name' => $this->admin->name, 'email' => $this->admin->email,
        'perfil_acceso_id' => $this->perfil->id, 'estado' => 'BLOQUEADO',
    ])->assertSessionHas('error');

    expect($this->admin->fresh()->estado)->toBe('ACTIVO');
});

test('un usuario no puede cambiarse su propio perfil de acceso', function () {
    $perfilOriginal = $this->admin->perfil_acceso_id;

    $this->put(route('admin.usuarios.update', $this->admin), [
        'name' => $this->admin->name, 'email' => $this->admin->email,
        'perfil_acceso_id' => $this->perfil->id, 'estado' => 'ACTIVO',
    ])->assertSessionHas('error', 'No podés cambiar tu propio perfil de acceso.');

    expect($this->admin->fresh()->perfil_acceso_id)->toBe($perfilOriginal);
});

test('editarse a uno mismo sin mandar el perfil (campo deshabilitado) conserva el perfil', function () {
    $perfilOriginal = $this->admin->perfil_acceso_id;

    $this->put(route('admin.usuarios.update', $this->admin), [
        'name' => 'Nombre nuevo', 'email' => $this->admin->email, 'estado' => 'ACTIVO',
    ])->assertSessionHasNoErrors()->assertSessionMissing('error');

    expect($this->admin->fresh())
        ->name->toBe('Nombre nuevo')
        ->perfil_acceso_id->toBe($perfilOriginal);
});

test('el formulario muestra el perfil propio deshabilitado con la nota, y el de otros editable', function () {
    $this->get(route('admin.usuarios.edit', $this->admin))->assertOk()
        ->assertSee('No podés cambiar tu propio perfil de acceso')
        ->assertSeeInOrder(['name="perfil_acceso_id"', 'disabled'], false);

    $otro = User::factory()->create(['perfil_acceso_id' => $this->perfil->id]);
    $html = $this->get(route('admin.usuarios.edit', $otro))->assertOk()
        ->assertDontSee('No podés cambiar tu propio perfil de acceso')
        ->getContent();
    expect($html)->not->toMatch('/<select[^>]*name="perfil_acceso_id"[^>]*\sdisabled\s/s');
});

test('otro administrador sí puede cambiarle el perfil a un usuario', function () {
    $otro = User::factory()->administrador()->create();

    $this->put(route('admin.usuarios.update', $otro), [
        'name' => $otro->name, 'email' => $otro->email,
        'perfil_acceso_id' => $this->perfil->id, 'estado' => 'ACTIVO',
    ])->assertSessionHasNoErrors();

    expect($otro->fresh()->perfil_acceso_id)->toBe($this->perfil->id);
});
