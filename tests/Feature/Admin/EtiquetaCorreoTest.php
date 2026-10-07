<?php

use App\Models\User;

/*
 * Las pantallas dicen "Correo electrónico" (o "Correo" en la columna del listado de Usuarios,
 * para que no quede tan ancha), no el anglicismo "Email".
 */

beforeEach(function () {
    $this->admin = User::factory()->administrador()->create();
    $this->actingAs($this->admin);
});

/** Texto visible de la página (sin etiquetas, scripts ni atributos). */
function textoVisible(string $html): string
{
    return preg_replace('/\s+/u', ' ', strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', '', $html)));
}

test('Usuarios, Personas y Perfil muestran "Correo electrónico" y ningún "Email"', function (Closure $url, string $etiqueta) {
    $texto = textoVisible($this->get($url())->assertOk()->getContent());

    expect($texto)->toContain($etiqueta)
        ->and(preg_match('/(^|[^\pL])Email([^\pL]|$)/u', $texto))->toBe(0);
})->with([
    'listado de usuarios (columna corta)' => [fn () => route('admin.usuarios.index'), 'Correo'],
    'edición de usuario' => [fn () => route('admin.usuarios.edit', test()->admin), 'Correo electrónico'],
    'alta de persona' => [fn () => route('admin.personas.create'), 'Correo electrónico'],
    'edición de persona' => [fn () => route('admin.personas.edit', test()->admin->persona), 'Correo electrónico'],
    'perfil' => [fn () => route('profile.edit'), 'Correo electrónico'],
]);

test('el error de validación también dice "correo electrónico"', function () {
    $this->post(route('admin.personas.store'), ['email' => 'no-es-un-correo'])
        ->assertSessionHasErrors(['email' => 'El campo correo electrónico no es un correo válido.']);
});
