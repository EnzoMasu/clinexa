<?php

use App\Models\Departamento;
use App\Models\Persona;
use App\Models\User;
use Database\Seeders\GeografiaSeeder;
use Illuminate\Support\Facades\Route;

/*
 * Los formularios de administración llevan novalidate (en el componente compartido x-admin.form):
 * así el navegador no frena el envío con sus globitos nativos, que salen en el idioma del
 * navegador ("Please select an item in the list"), y el aviso que se ve es el del servidor, en
 * castellano, junto al campo.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
});

test('todos los formularios de alta de /admin llevan novalidate', function () {
    $altas = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($ruta) => $ruta->getName())
        ->filter(fn (?string $nombre) => $nombre && str_starts_with($nombre, 'admin.') && str_ends_with($nombre, '.create'))
        // La consulta y la receta dependen de una historia o una consulta y de un profesional: su novalidate se
        // prueba en FormularioConsultaTest y RecetasTest.
        ->reject(fn (string $nombre) => in_array($nombre, ['admin.consultas.create', 'admin.recetas.create'], true))
        ->values();

    expect($altas)->not->toBeEmpty();
    // Sin personas disponibles, el alta de Usuarios muestra un aviso en lugar del formulario.
    Persona::factory()->create();

    // El formulario principal (POST al store de la sección) tiene novalidate.
    $sinNovalidate = $altas->reject(function (string $nombre) {
        $html = $this->get(route($nombre))->assertOk()->getContent();
        $store = route(str_replace('.create', '.store', $nombre));

        return (bool) preg_match('#<form method="POST" action="'.preg_quote($store, '#').'" novalidate#', $html);
    });

    expect($sinNovalidate->values()->all())->toBe([]);
});

test('también al editar', function () {
    $this->seed(GeografiaSeeder::class);
    $concepcion = Departamento::where('nombre', 'Concepción')->sole();

    expect($this->get(route('admin.departamentos.edit', $concepcion))->assertOk()->getContent())
        ->toMatch('#<form method="POST" action="'.preg_quote(route('admin.departamentos.update', $concepcion), '#').'" novalidate#');
});

test('alta de Ciudad sin departamento: vuelve con el aviso en castellano y conserva el nombre escrito', function () {
    $this->seed(GeografiaSeeder::class);

    $this->from(route('admin.ciudades.create'))
        ->post(route('admin.ciudades.store'), ['nombre' => 'Villa Nueva', 'departamento_id' => ''])
        ->assertRedirect(route('admin.ciudades.create'))
        ->assertSessionHasErrors(['departamento_id' => 'El campo departamento es obligatorio.']);

    $this->get(route('admin.ciudades.create'))->assertOk()
        ->assertSee('El campo departamento es obligatorio.')
        ->assertSee('value="Villa Nueva"', false);
});
