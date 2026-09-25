<?php

use App\Models\User;

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
    'bloqueado' => ['BLOQUEADO', 'Su usuario está bloqueado. Contacte al administrador.'],
    'inactivo' => ['INACTIVO', 'Su usuario está inactivo. Contacte al administrador.'],
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

test('si la persona del usuario está inactiva, el login lo rechaza aunque el usuario esté ACTIVO', function () {
    $user = User::factory()->create();
    $user->persona->update(['estado' => 'INACTIVO']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Su usuario está inactivo. Contacte al administrador.']);
    $this->assertGuest();
});

test('si desactivan la persona con la sesión abierta, lo saca en el siguiente request', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->persona->update(['estado' => 'INACTIVO']);

    $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});
