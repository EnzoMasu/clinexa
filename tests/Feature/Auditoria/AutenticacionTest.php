<?php

use App\Enums\AccionAuditoria;
use App\Models\LogAuditoria;
use App\Models\User;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->usuario = User::factory()->administrador()->create(['email' => 'liz@example.com']);
});

function registrosDe(AccionAuditoria $accion)
{
    return LogAuditoria::where('accion', $accion->value)->orderBy('id')->get();
}

/** Ningún registro guarda la contraseña intentada. */
function sinContrasenaEnElLog(string $contrasena): void
{
    $todo = LogAuditoria::all()->map(fn ($log) => json_encode([$log->detalle, $log->valor_anterior, $log->valor_nuevo]))->join(' ');
    expect($todo)->not->toContain($contrasena);
}

test('inicio de sesión', function () {
    $this->post(route('login'), ['email' => 'liz@example.com', 'password' => 'password'])->assertRedirect();

    expect(registrosDe(AccionAuditoria::INICIO_SESION)->sole())
        ->usuario_id->toBe($this->usuario->id)->tabla_afectada->toBe('users')
        ->registro_afectado_id->toBe((string) $this->usuario->id)->ip_origen->toBe('127.0.0.1')
        // El ultimo_acceso que se actualiza al entrar no genera un EDITAR del usuario.
        ->and(registrosDe(AccionAuditoria::EDITAR))->toBeEmpty();
});

test('intento fallido con un correo existente: con el usuario y el correo, nunca la contraseña', function () {
    $this->post(route('login'), ['email' => 'liz@example.com', 'password' => 'Clave$Equivocada9'])->assertSessionHasErrors('email');

    expect(registrosDe(AccionAuditoria::INICIO_SESION_FALLIDO)->sole())
        ->usuario_id->toBe($this->usuario->id)
        ->detalle->toBe('Correo: liz@example.com. Contraseña incorrecta.')
        ->and(registrosDe(AccionAuditoria::INICIO_SESION))->toBeEmpty();
    sinContrasenaEnElLog('Clave$Equivocada9');
});

test('intento fallido con un correo inexistente: sin usuario, con el correo intentado', function () {
    $this->post(route('login'), ['email' => 'nadie@example.com', 'password' => 'Cualquier$Cosa1'])->assertSessionHasErrors('email');

    expect(registrosDe(AccionAuditoria::INICIO_SESION_FALLIDO)->sole())
        ->usuario_id->toBeNull()->registro_afectado_id->toBeNull()
        ->detalle->toBe('Correo: nadie@example.com. El correo no corresponde a ningún usuario.');
    sinContrasenaEnElLog('Cualquier$Cosa1');
});

test('cuenta bloqueada con la contraseña correcta: intento fallido con el motivo, sin inicio ni cierre de sesión', function () {
    User::factory()->administrador()->create(); // el último administrador activo no se bloquea
    $this->usuario->update(['estado_id' => estadoId('BLOQUEADO')]);

    $this->post(route('login'), ['email' => 'liz@example.com', 'password' => 'password'])->assertSessionHasErrors('email');

    $fallido = registrosDe(AccionAuditoria::INICIO_SESION_FALLIDO)->sole();
    expect($fallido->usuario_id)->toBe($this->usuario->id)
        ->and($fallido->detalle)->toStartWith('Correo: liz@example.com. Contraseña correcta, pero no puede ingresar: ')
        ->and(registrosDe(AccionAuditoria::INICIO_SESION))->toBeEmpty()
        ->and(registrosDe(AccionAuditoria::CIERRE_SESION))->toBeEmpty();
    $this->assertGuest();
});

test('cierre de sesión', function () {
    $this->actingAs($this->usuario)->post(route('logout'))->assertRedirect();

    expect(registrosDe(AccionAuditoria::CIERRE_SESION)->sole())
        ->usuario_id->toBe($this->usuario->id)->detalle->toBeNull();
});

test('si el sistema cierra la sesión (cuenta bloqueada mientras estaba adentro), el cierre lleva el motivo', function () {
    $this->actingAs($this->usuario);
    User::factory()->administrador()->create(); // el último administrador activo no se bloquea
    $this->usuario->update(['estado_id' => estadoId('BLOQUEADO')]);

    $this->get('/dashboard')->assertRedirect(route('login'));

    expect(registrosDe(AccionAuditoria::CIERRE_SESION)->sole()->detalle)->toStartWith('Sesión cerrada por el sistema: ');
});

test('bloqueo temporal por demasiados intentos: se registra una vez por bloqueo', function () {
    foreach (range(1, 8) as $intento) {
        $this->post(route('login'), ['email' => 'liz@example.com', 'password' => "Mala$intento"]);
    }

    expect(registrosDe(AccionAuditoria::INICIO_SESION_FALLIDO))->toHaveCount(5)
        ->and(registrosDe(AccionAuditoria::BLOQUEO)->sole())
        ->usuario_id->toBe($this->usuario->id)
        ->detalle->toStartWith('Bloqueo temporal por demasiados intentos fallidos. Correo: liz@example.com.');
    sinContrasenaEnElLog('Mala6');
});

describe('cambio de contraseña', function () {
    function definirContrasena(User $usuario, string $nueva)
    {
        return test()->post(route('password.store'), [
            'token' => Password::broker()->createToken($usuario), 'email' => $usuario->email,
            'password' => $nueva, 'password_confirmation' => $nueva,
        ]);
    }

    test('por la invitación (el usuario nunca entró)', function () {
        definirContrasena($this->usuario, 'Primera$Clave2026')->assertSessionHasNoErrors();

        expect(registrosDe(AccionAuditoria::CAMBIO_CONTRASENA)->sole())
            ->usuario_id->toBe($this->usuario->id)->detalle->toBe('Origen: invitación (primera contraseña).')
            ->valor_anterior->toBeNull()->valor_nuevo->toBeNull();
        sinContrasenaEnElLog('Primera$Clave2026');
        sinContrasenaEnElLog($this->usuario->fresh()->password);
    });

    test('por restablecimiento (el usuario ya había entrado)', function () {
        $this->usuario->forceFill(['ultimo_acceso' => now()])->save();

        definirContrasena($this->usuario, 'Otra$Clave20261')->assertSessionHasNoErrors();

        expect(registrosDe(AccionAuditoria::CAMBIO_CONTRASENA)->sole()->detalle)->toBe('Origen: restablecimiento por link.');
    });

    test('desde el perfil', function () {
        $this->actingAs($this->usuario)->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'Desde$ElPerfil2026', 'password_confirmation' => 'Desde$ElPerfil2026',
        ])->assertSessionHasNoErrors();

        expect(registrosDe(AccionAuditoria::CAMBIO_CONTRASENA)->sole()->detalle)->toBe('Origen: perfil (cambio por el propio usuario).');
        sinContrasenaEnElLog('Desde$ElPerfil2026');
    });
});
