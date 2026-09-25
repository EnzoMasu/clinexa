<?php

use App\Console\Commands\MigrarUsuariosAPersona;
use App\Models\TipoDocumento;
use App\Models\User;

// Con el esquema final (persona_id obligatorio) ya no pueden existir usuarios sin persona, así que el
// recorrido completo se probó contra la base real; acá se cubre la lógica que se puede aislar.

test('separa el nombre del usuario en apellidos y nombres solo cuando no es ambiguo', function (string $nombre, array $esperado) {
    expect(MigrarUsuariosAPersona::separarNombre($nombre))->toBe($esperado);
})->with([
    'dos palabras' => ['Liz Ruiz', ['Ruiz', 'Liz']],
    'cuatro palabras' => ['María José López Gómez', ['López Gómez', 'María José']],
    'espacios de más' => ['  Ana   Ruiz  ', ['Ruiz', 'Ana']],
    'una palabra' => ['Administrador', ['SIN DATO', 'Administrador']],
    'tres palabras (ambiguo)' => ['Juan Carlos Pérez', ['SIN DATO', 'Juan Carlos Pérez']],
    'vacío' => ['', ['SIN DATO', 'SIN DATO']],
    'demasiado largo' => [str_repeat('a', 150).' '.str_repeat('b', 150), [str_repeat('b', 100), str_repeat('a', 100)]],
]);

test('sin usuarios pendientes no hace nada', function () {
    User::factory()->create();

    $this->artisan('clinexa:migrar-usuarios-a-persona')
        ->expectsOutputToContain('nada que migrar')
        ->assertSuccessful();
});

test('sin el tipo de documento CI avisa y no hace nada', function () {
    TipoDocumento::query()->delete();

    $this->artisan('clinexa:migrar-usuarios-a-persona')
        ->expectsOutputToContain('Falta el tipo de documento CI')
        ->assertFailed();
});
