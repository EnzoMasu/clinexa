<?php

/*
 * "Preparación" se llama "Triaje" en pantalla: URLs /admin/triaje (las viejas redirigen con 301), textos,
 * menú, matriz de permisos y nombres del módulo y del estado. Los códigos internos (PREPARACION,
 * EN_PREPARACION) y los nombres de ruta (admin.preparacion.*) no cambian. Datos inventados.
 */

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\User;

beforeEach(function () {
    hcEscenario();
});

test('las URLs nuevas y la redirección permanente desde las viejas (solo GET)', function () {
    $this->post(route('admin.preparacion.preparar', hcTurno()));
    $consulta = Consulta::sole();

    expect(route('admin.preparacion.index', absolute: false))->toBe('/admin/triaje')
        ->and(route('admin.preparacion.formulario', $consulta, false))->toBe("/admin/consultas/{$consulta->id}/triaje")
        ->and(route('admin.preparacion.preparar', 1, false))->toBe('/admin/turnos/1/triaje')
        ->and(route('admin.preparacion.lista', $consulta, false))->toBe("/admin/consultas/{$consulta->id}/triaje/terminar")
        ->and(route('admin.preparacion.reabrir', $consulta, false))->toBe("/admin/consultas/{$consulta->id}/triaje/reabrir");

    $this->get('/admin/preparacion')->assertStatus(301)->assertRedirect('/admin/triaje');
    $this->get("/admin/consultas/{$consulta->id}/preparacion")->assertStatus(301)->assertRedirect("/admin/consultas/{$consulta->id}/triaje");
    $this->delete('/admin/preparacion')->assertStatus(405);
});

test('menú, títulos, botones y estados dicen Triaje', function () {
    $enfermera = User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->personalDeSalud()->create();
    hcTurno();
    $this->actingAs($enfermera);

    $this->get('/dashboard')->assertSee('Triaje')->assertDontSee('Preparación');
    $this->get(route('admin.preparacion.index'))->assertOk()->assertSee('Triaje')->assertSee('Sin triaje')->assertSee('Iniciar triaje')
        ->assertDontSee('Preparación')->assertDontSee('Preparar<', false);

    $this->post(route('admin.preparacion.preparar', \App\Models\Turno::sole()));
    $consulta = Consulta::sole();
    $this->get(route('admin.preparacion.index'))->assertSee('En triaje')->assertSee('Continuar triaje');
    $this->get(route('admin.preparacion.formulario', $consulta))->assertOk()->assertSee('Terminar triaje')->assertDontSee('Marcar como lista');
    $this->post(route('admin.preparacion.lista', $consulta))->assertSessionHas('status', 'Triaje terminado.');
    $this->get(route('admin.preparacion.index'))->assertSee('Con triaje');
    $this->get(route('admin.preparacion.formulario', $consulta))->assertSee('Reabrir triaje')->assertSee('Triaje terminado el');
    $this->post(route('admin.preparacion.reabrir', $consulta))->assertSessionHas('status', 'Triaje reabierto.');
});

test('el módulo se llama Triaje y el estado EN TRIAJE; los códigos no cambian', function () {
    expect(ModuloSistema::where('codigo', 'PREPARACION')->value('nombre'))->toBe('Triaje')
        ->and(Estado::where('codigo', Estado::EN_PREPARACION)->value('nombre'))->toBe('EN TRIAJE');

    $this->actingAs(User::factory()->principal()->create());
    $this->get(route('admin.matriz-permisos.index', ['vista' => 'comparar']))->assertSee('Triaje')->assertDontSee('Preparación');
});
