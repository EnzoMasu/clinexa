<?php

use App\Models\User;
use App\Rules\SinCaracteresRepetidos;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

dataset('passwords invalidas', [
    'menos de 8 caracteres' => 'Ab1$xyz',
    'sin mayúscula' => 'clave$123',
    'sin minúscula' => 'CLAVE$123',
    'sin número' => 'Clave$abc',
    'sin carácter especial' => 'Clave1234',
    '4 números iguales seguidos' => 'Clave$0000',
    '4 letras iguales seguidas' => 'Claaaave$1',
    '5 símbolos iguales seguidos' => 'Clave1$$$$$',
]);

dataset('passwords validas', [
    'típica' => 'Clave$123',
    '3 números iguales seguidos' => 'Clave$000',
    '3 letras iguales seguidas' => 'Claaave$1',
    'iguales pero no seguidos' => 'a0a0a0a0A$',
]);

function valida(string $password): bool
{
    return Validator::make(['password' => $password], ['password' => Password::defaults()])->passes();
}

test('rechaza contraseñas que no cumplen la política', function (string $password) {
    expect(valida($password))->toBeFalse();
})->with('passwords invalidas');

test('acepta contraseñas que cumplen la política', function (string $password) {
    expect(valida($password))->toBeTrue();
})->with('passwords validas');

test('la regla de repetidos rechaza desde 4 y permite hasta 3', function () {
    $pasa = fn (string $valor) => Validator::make(['p' => $valor], ['p' => new SinCaracteresRepetidos])->passes();

    expect($pasa('000'))->toBeTrue()
        ->and($pasa('aaa'))->toBeTrue()
        ->and($pasa('0000'))->toBeFalse()
        ->and($pasa('xaaaax'))->toBeFalse()
        ->and($pasa('ññññ'))->toBeFalse();
});

test('se aplica al definir la contraseña con el link del email (reset / olvidé mi contraseña)', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notificacion) use ($user) {
        $this->post('/reset-password', [
            'token' => $notificacion->token,
            'email' => $user->email,
            'password' => 'Clave$0000',
            'password_confirmation' => 'Clave$0000',
        ])->assertSessionHasErrors('password');

        expect(Hash::check('password', $user->fresh()->password))->toBeTrue();

        return true;
    });
});

test('se aplica al cambiar la contraseña desde el perfil', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'sinmayuscula1$',
            'password_confirmation' => 'sinmayuscula1$',
        ])
        ->assertSessionHasErrorsIn('updatePassword', 'password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('los formularios muestran los requisitos', function () {
    $user = User::factory()->create();

    $this->get('/reset-password/token-cualquiera')->assertSee('Al menos un carácter especial');
    $this->actingAs($user)->get('/profile')->assertSee('No más de 3 caracteres iguales seguidos');
});

test('el email de "olvidé mi contraseña" sale en castellano', function () {
    $mail = (new ResetPassword('token'))->toMail(User::factory()->create());

    expect($mail->subject)->not->toBe('Reset Password Notification')
        ->and($mail->actionText)->not->toBe('Reset Password');
});

test('los mensajes de validación de la contraseña salen en castellano', function () {
    $errores = Validator::make(['password' => 'clave'], ['password' => Password::defaults()])->errors()->get('password');

    expect(implode(' ', $errores))->toContain('contraseña')->not->toContain('must contain');
});
