<?php

use App\Models\User;

/**
 * Cuenta los campos de contraseña con botón de mostrar/ocultar y verifica que, sin JavaScript,
 * sigan siendo type="password" (Alpine es quien alterna a "text").
 */
function camposConOjo(string $html): int
{
    $conOjo = preg_match_all('/<input type="password" x-bind:type="visible \? \'text\' : \'password\'"/', $html);
    $botones = substr_count($html, 'aria-label="Mostrar contraseña"');
    expect($botones)->toBe($conOjo);

    return $conOjo;
}

test('todos los campos de contraseña tienen el botón de mostrar/ocultar', function (string $uri, bool $logueado, int $campos) {
    if ($logueado) {
        $this->actingAs(User::factory()->create());
    }

    $html = $this->get($uri)->assertOk()->getContent();

    expect(camposConOjo($html))->toBe($campos)
        // Ningún campo de contraseña quedó sin el componente.
        ->and(substr_count($html, 'type="password"'))->toBe($campos);
})->with([
    'login' => ['/login', false, 1],
    'definir contraseña (reset)' => ['/reset-password/token-cualquiera', false, 2],
    'confirmar contraseña' => ['/confirm-password', true, 1],
    'perfil: cambiar contraseña + eliminar cuenta' => ['/profile', true, 4],
]);

test('el botón no envía el formulario y apunta a su campo', function () {
    $html = $this->get('/login')->getContent();

    expect($html)->toMatch('/<button type="button" x-on:click="visible = ! visible"/')
        ->toMatch('/aria-controls="password"/')
        ->toMatch('/<input type="password"[^>]*\bid="password"[^>]*\bname="password"[^>]*\brequired\b/s');
});
