<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('el perfil muestra los datos de la persona como solo lectura', function () {
    $user = User::factory()->conPersona(['apellidos' => 'Ruiz', 'nombres' => 'Liz', 'nro_documento' => '4567890'])->create();

    $html = $this->actingAs($user)->get('/profile')->assertOk()
        ->assertSeeInOrder(['Ruiz, Liz', 'CI 4567890', $user->email])
        ->assertSee('Estos datos los administra su institución. Si necesita corregir algo, contacte a un administrador.')
        ->getContent();

    // No hay campos editables de nombre ni email; siguen las secciones de contraseña y desactivar la cuenta.
    expect($html)->not->toContain('name="name"')->not->toContain('name="email"')
        ->toContain('name="current_password"')
        ->toContain(route('profile.desactivar'))
        ->toContain('Desactivar mi cuenta');
});

test('el usuario ya no puede editar su nombre ni su email desde el perfil', function () {
    $user = User::factory()->create(['email' => 'original@example.com']);

    $this->actingAs($user)->patch('/profile', ['name' => 'Otro', 'email' => 'otro@example.com'])
        ->assertMethodNotAllowed();

    expect($user->fresh()->email)->toBe('original@example.com');
});

test('desactivar mi cuenta pasa el usuario a INACTIVO sin borrarlo ni tocar su persona', function () {
    $user = User::factory()->create();
    $persona = $user->persona->only(['estado', 'email', 'apellidos', 'nombres']);

    $this->actingAs($user)
        ->patch(route('profile.desactivar'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Su cuenta fue desactivada. Para volver a usarla, solicite a un administrador que la reactive.');

    $this->assertGuest();
    expect($user->fresh())->not->toBeNull()->estado->toBe('INACTIVO')
        ->and($user->persona->fresh()->only(['estado', 'email', 'apellidos', 'nombres']))->toBe($persona);

    // Ya no puede volver a entrar.
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Su usuario está inactivo. Contacte al administrador.']);
    $this->assertGuest();
});

test('desactivar una cuenta que ya está INACTIVA la deja inactiva, sin errores', function () {
    $user = User::factory()->create(['estado' => 'INACTIVO']);

    $this->actingAs($user)
        ->patch(route('profile.desactivar'), ['password' => 'password'])
        ->assertRedirect(route('login'));

    expect($user->fresh())->not->toBeNull()->estado->toBe('INACTIVO');
});

test('para desactivar la cuenta hay que confirmar con la contraseña correcta', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/profile')
        ->patch(route('profile.desactivar'), ['password' => 'incorrecta'])
        ->assertSessionHasErrorsIn('desactivarCuenta', 'password')
        ->assertRedirect('/profile');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->estado)->toBe('ACTIVO');
});

test('ya no existe la ruta que borraba la cuenta', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertMethodNotAllowed();

    expect($user->fresh())->not->toBeNull()->estado->toBe('ACTIVO');
});

test('el único administrador activo no puede desactivar su propia cuenta', function () {
    $admin = User::factory()->administrador()->create();

    $this->actingAs($admin)
        ->from('/profile')
        ->patch(route('profile.desactivar'), ['password' => 'password'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrorsIn('desactivarCuenta', ['cuenta' => 'No puede desactivar su cuenta: es el único administrador activo del sistema.']);

    $this->assertAuthenticatedAs($admin);
    expect($admin->fresh()->estado)->toBe('ACTIVO');

    // El mensaje se ve en el modal de confirmación.
    $this->get('/profile')->assertSee('No puede desactivar su cuenta: es el único administrador activo del sistema.');
});

test('un administrador puede desactivar su cuenta si hay otro administrador activo', function () {
    $admin = User::factory()->administrador()->create();
    User::factory()->administrador()->create();

    $this->actingAs($admin)
        ->patch(route('profile.desactivar'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));

    expect($admin->fresh()->estado)->toBe('INACTIVO');
});

test('otros administradores que no pueden entrar no cuentan como administrador activo', function (Closure $otroAdmin) {
    $admin = User::factory()->administrador()->create();
    $otroAdmin();

    $this->actingAs($admin)
        ->patch(route('profile.desactivar'), ['password' => 'password'])
        ->assertSessionHasErrorsIn('desactivarCuenta', 'cuenta');

    expect($admin->fresh()->estado)->toBe('ACTIVO');
})->with([
    'otro admin BLOQUEADO' => [fn () => User::factory()->administrador()->create(['estado' => 'BLOQUEADO'])],
    'otro admin INACTIVO' => [fn () => User::factory()->administrador()->create(['estado' => 'INACTIVO'])],
    'otro admin con la persona inactiva' => [fn () => User::factory()->administrador()->create()->persona->update(['estado' => 'INACTIVO'])],
]);

test('un usuario que no es administrador puede desactivar su cuenta aunque haya un solo admin', function () {
    User::factory()->administrador()->create();
    $usuario = User::factory()->conPermisos(['PERSONAS' => ['VER']])->create();

    $this->actingAs($usuario)
        ->patch(route('profile.desactivar'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($usuario->fresh()->estado)->toBe('INACTIVO');
});
