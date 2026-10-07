<?php

use App\Models\User;

/*
 * El login valida primero la contraseña y el estado de la cuenta, y recién después inicia la
 * sesión (antes usaba Auth::attempt y cerraba la sesión si la cuenta no podía entrar). Esto
 * comprueba que el comportamiento visible no cambió. (Lo demás está en AccesoUsuarioTest y
 * AuthenticationTest: mensajes específicos con contraseña correcta, último acceso, cierre forzado
 * por cuenta bloqueada o persona inactiva.)
 */

beforeEach(function () {
    $this->activo = User::factory()->create(['email' => 'activa@example.com']);
});

test('(a) con contraseña incorrecta el mensaje es siempre el genérico: no revela si la cuenta existe ni su estado', function () {
    $bloqueado = User::factory()->create(['email' => 'bloqueada@example.com', 'estado_id' => estadoId('BLOQUEADO')]);
    $inactivo = User::factory()->create(['email' => 'inactiva@example.com', 'estado_id' => estadoId('INACTIVO')]);

    $errores = collect(['activa@example.com', 'bloqueada@example.com', 'inactiva@example.com', 'no-existe@example.com'])
        ->map(function (string $correo) {
            $respuesta = $this->post('/login', ['email' => $correo, 'password' => 'incorrecta']);
            $this->assertGuest();

            return session('errors')->get('email');
        });

    // Los cuatro, idénticos al genérico; ninguno menciona bloqueo ni inactividad.
    expect($errores->unique()->values()->all())->toBe([[trans('auth.failed')]])
        ->and(trans('auth.failed'))->not->toContain('bloquead')->not->toContain('inactiv');
});

test('(b) el mensaje específico de cuenta bloqueada o inactiva aparece solo con la contraseña correcta', function () {
    User::factory()->create(['email' => 'bloqueada@example.com', 'estado_id' => estadoId('BLOQUEADO')]);

    $this->post('/login', ['email' => 'bloqueada@example.com', 'password' => 'incorrecta'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->post('/login', ['email' => 'bloqueada@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Su usuario está bloqueado. Contacte al administrador.']);
    $this->assertGuest();
});

test('(c) después de 5 intentos fallidos se bloquea el login un rato, aun con la contraseña correcta', function () {
    foreach (range(1, 5) as $intento) {
        $this->post('/login', ['email' => 'activa@example.com', 'password' => "incorrecta{$intento}"])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    $respuesta = $this->post('/login', ['email' => 'activa@example.com', 'password' => 'password']);

    $respuesta->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->not->toBe(trans('auth.failed'))
        ->toContain('Demasiados intentos');
    $this->assertGuest();
});

test('(c) un inicio de sesión correcto antes del límite reinicia el contador', function () {
    foreach (range(1, 4) as $intento) {
        $this->post('/login', ['email' => 'activa@example.com', 'password' => 'incorrecta']);
    }
    $this->post('/login', ['email' => 'activa@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($this->activo);
    $this->post('/logout');

    // Otros 4 fallidos no llegan a bloquear: el contador volvió a cero.
    foreach (range(1, 4) as $intento) {
        $this->post('/login', ['email' => 'activa@example.com', 'password' => 'incorrecta']);
    }
    $this->post('/login', ['email' => 'activa@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($this->activo);
});

test('(d) el inicio de sesión registra el último acceso y deja la sesión iniciada', function () {
    expect($this->activo->ultimo_acceso)->toBeNull();

    $this->post('/login', ['email' => 'activa@example.com', 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($this->activo);
    expect($this->activo->fresh()->ultimo_acceso)->not->toBeNull();
});

test('(e) si el usuario pasa a INACTIVO con la sesión abierta, lo saca en el siguiente pedido', function () {
    $this->actingAs($this->activo)->get('/dashboard')->assertOk();

    $this->activo->update(['estado_id' => estadoId('INACTIVO')]);

    $this->actingAs($this->activo->fresh())->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Su usuario está inactivo. Contacte al administrador.']);
    $this->assertGuest();
});
