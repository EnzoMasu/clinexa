<?php

use App\Models\PerfilAcceso;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('no existe registro público', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Intruso', 'email' => 'intruso@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    $this->get('/login')->assertOk()->assertDontSee('Register');
});

test('el login rechaza usuarios bloqueados o inactivos aunque la contraseña sea correcta', function (string $estado, string $mensaje) {
    $user = User::factory()->create(['estado' => $estado]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => $mensaje]);

    $this->assertGuest();
})->with([
    'bloqueado' => ['BLOQUEADO', 'Tu usuario está bloqueado. Contactá al administrador.'],
    'inactivo' => ['INACTIVO', 'Tu usuario está inactivo. Contactá al administrador.'],
]);

test('un usuario bloqueado con la contraseña incorrecta ve el error genérico', function () {
    $user = User::factory()->create(['estado' => 'BLOQUEADO']);

    $this->post('/login', ['email' => $user->email, 'password' => 'incorrecta'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
});

test('el login registra el último acceso', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->ultimo_acceso)->not->toBeNull();
});

test('si bloquean a un usuario con la sesión abierta, lo saca en el siguiente request', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->update(['estado' => 'BLOQUEADO']);

    $this->actingAs($user->fresh())->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('crear-admin crea el administrador con todos los permisos y le manda el link', function () {
    Notification::fake();

    $this->artisan('clinexa:crear-admin', ['email' => 'Admin@Clinexa.test'])->assertSuccessful();

    $admin = User::where('email', 'admin@clinexa.test')->sole();
    $perfil = PerfilAcceso::where('nombre', 'Administrador')->sole();

    expect($admin->perfil_acceso_id)->toBe($perfil->id)
        ->and($perfil->permisos()->count())->toBe(10 * 5);
    Notification::assertSentTo($admin, ResetPassword::class);

    $this->artisan('clinexa:crear-admin', ['email' => 'admin@clinexa.test'])->assertFailed();
});
