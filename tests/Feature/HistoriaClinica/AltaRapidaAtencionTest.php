<?php

use App\Models\Consulta;
use App\Models\ModuloSistema;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;

/*
 * Atención > "Atender sin turno": desde la búsqueda sin resultados se crea persona + paciente (alta rápida),
 * el paciente queda elegido y se ofrece "Atender". Datos inventados. El clic en el navegador lo cubre
 * tests/Js; acá, la pantalla y el recorrido del servidor.
 */

beforeEach(function () {
    hcEscenario();
    TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
    ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI']);
});

function altaRapidaAtencionDatos(): array
{
    return [
        'tipo_persona' => 'FISICA', 'tipo_documento_id' => TipoDocumento::where('codigo', 'CI')->value('id'),
        'nro_documento' => '7654321', 'apellidos' => 'Rápida', 'nombres' => 'Inventada', 'fecha_nacimiento' => '15/03/1990',
        'email' => 'rapida@ejemplo.test', 'telefono' => '0981 111 222', 'direccion' => 'Calle Falsa 123',
    ];
}

test('con PACIENTES,CREAR, la búsqueda sin resultados ofrece "Crear"; se crea persona + paciente, queda elegido y se puede "Atender"', function () {
    [$medico] = hcMedico(['apellidos' => 'Gómez', 'nombres' => 'Ana'], 'MP-9', [...HC_PERMISOS_MEDICO, 'PACIENTES' => ['VER', 'CREAR']]);
    $this->actingAs($medico);

    $this->get(route('admin.atencion.index'))->assertOk()
        ->assertSee('>Crear</button>', false)
        ->assertSee('alta-rapida-persona-paciente_atencion')
        ->assertSee('pacientes\\/alta-rapida', false)
        ->assertSee('>Atender</button>', false);

    // La búsqueda de la pantalla no encuentra al paciente nuevo...
    $this->getJson(route('admin.atencion.pacientes', ['q' => '7654321']))->assertOk()->assertExactJson([]);

    // ..."Crear" lo da de alta y devuelve el id de su historia clínica, que es lo que queda elegido.
    $respuesta = $this->postJson(route('admin.pacientes.alta-rapida', ['como' => 'historia']), altaRapidaAtencionDatos())->assertCreated();
    $paciente = Paciente::where('persona_id', Persona::where('nro_documento', '7654321')->value('id'))->sole();
    expect($respuesta->json('id'))->toBe($paciente->historiaClinica->id);

    // Ya aparece en la búsqueda y "Atender" (el POST del botón) abre la consulta de ese paciente.
    $this->getJson(route('admin.atencion.pacientes', ['q' => '7654321']))->assertOk()->assertJsonPath('0.id', $paciente->historiaClinica->id);
    $this->post(route('admin.atencion.atender-sin-turno', $paciente->historiaClinica))->assertRedirect();
    expect(Consulta::where('historia_clinica_id', $paciente->historiaClinica->id)->count())->toBe(1);
});

test('sin PACIENTES,CREAR no aparece "Crear" en Atención y el alta rápida se rechaza', function () {
    [$medico] = hcMedico(['apellidos' => 'Gómez', 'nombres' => 'Ana'], 'MP-9', [...HC_PERMISOS_MEDICO, 'PACIENTES' => ['VER']]);
    $this->actingAs($medico);

    $this->get(route('admin.atencion.index'))->assertOk()
        ->assertDontSee('>Crear</button>', false)
        ->assertDontSee('alta-rapida-persona-paciente_atencion')
        ->assertSee('>Atender</button>', false);

    $this->postJson(route('admin.pacientes.alta-rapida', ['como' => 'historia']), altaRapidaAtencionDatos())->assertForbidden();
    expect(Persona::where('nro_documento', '7654321')->exists())->toBeFalse();
});
