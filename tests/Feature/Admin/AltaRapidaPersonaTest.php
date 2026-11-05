<?php

use App\Enums\AccionAuditoria;
use App\Models\HistoriaClinica;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\ResponsablePago;
use App\Models\PersonalSalud;
use App\Models\TipoDocumento;
use App\Models\User;

/*
 * Alta rápida de persona (ventana emergente del selector de personas) en los formularios de Pacientes,
 * Profesionales, Personal de salud y Responsables de pago, y de persona + paciente en Turnos, Urgencias y
 * Atención. Proveedores tiene su propio test. Datos inventados.
 */

beforeEach(function () {
    User::factory()->conPermisos(['PERSONAS' => ['VER']])->create(); // siembra los módulos
    TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
    ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI']);
});

function altaRapidaPersonaDatos(array $cambios = []): array
{
    return [
        'tipo_persona' => 'FISICA',
        'tipo_documento_id' => TipoDocumento::where('codigo', 'CI')->value('id'),
        'nro_documento' => '7654321',
        'apellidos' => 'Rápida',
        'nombres' => 'Inventada',
        'fecha_nacimiento' => '15/03/1990',
        'email' => 'rapida@ejemplo.test',
        'telefono' => '0981 111 222',
        'direccion' => 'Calle Falsa 123',
        ...$cambios,
    ];
}

function altaRapidaPersonaEmpresa(): array
{
    return ['tipo_persona' => 'JURIDICA', 'ruc' => \App\Support\Ruc::conDigito('80054321'), 'razon_social' => 'Empresa Inventada SRL',
        'email' => 'empresa@ejemplo.test', 'telefono' => '021 000 000', 'direccion' => 'Ruta 1 km 5'];
}

dataset('altaRapidaPersonaRoles', [
    'pacientes' => ['pacientes', 'PACIENTES', Paciente::class, false],
    'profesionales' => ['profesionales', 'PROFESIONALES', Profesional::class, false],
    'personal-salud' => ['personal-salud', 'PERSONAL_SALUD', PersonalSalud::class, false],
    'responsables-pago' => ['responsables-pago', 'RESPONSABLES_PAGO', ResponsablePago::class, true],
]);

describe('alta rápida de persona en el formulario de un rol', function () {
    test('crea la persona auditada, la deja elegible y el rol se guarda con el formulario', function (string $uri, string $modulo, string $modelo) {
        $usuario = User::factory()->conPermisos([$modulo => ['VER', 'CREAR']])->create();
        $this->actingAs($usuario);

        $respuesta = $this->postJson(route("admin.{$uri}.personas-rapida"), altaRapidaPersonaDatos())->assertCreated();

        $persona = Persona::where('nro_documento', '7654321')->sole();
        $respuesta->assertExactJson(['id' => $persona->id, 'texto' => 'Rápida, Inventada — CI 7654321']);
        expect($modelo::where('persona_id', $persona->id)->exists())->toBeFalse();

        $log = LogAuditoria::where('tabla_afectada', 'personas')->where('registro_afectado_id', (string) $persona->id)->sole();
        expect($log)->accion->toBe(AccionAuditoria::CREAR)->usuario_id->toBe($usuario->id);

        $this->getJson(route("admin.{$uri}.personas-disponibles", ['q' => '7654321']))->assertJsonPath('0.id', $persona->id);
    })->with('altaRapidaPersonaRoles');

    test('mismas validaciones que Personas y no crea nada si falla', function (string $uri, string $modulo) {
        $this->actingAs(User::factory()->conPermisos([$modulo => ['CREAR']])->create());
        Persona::factory()->create(['nro_documento' => '7654321']);

        $this->postJson(route("admin.{$uri}.personas-rapida"), altaRapidaPersonaDatos(['email' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['nro_documento', 'email']);
        expect(Persona::where('apellidos', 'Rápida')->exists())->toBeFalse();
    })->with('altaRapidaPersonaRoles');

    test('solo ofrece y acepta los tipos de persona del rol', function (string $uri, string $modulo, string $modelo, bool $admiteEmpresa) {
        $this->actingAs(User::factory()->conPermisos([$modulo => ['CREAR']])->create());

        $respuesta = $this->postJson(route("admin.{$uri}.personas-rapida"), altaRapidaPersonaEmpresa());
        if ($admiteEmpresa) {
            $respuesta->assertCreated();
        } else {
            $respuesta->assertStatus(422)->assertJsonValidationErrors(['tipo_persona']);
            expect(Persona::where('razon_social', 'Empresa Inventada SRL')->exists())->toBeFalse();
        }
    })->with('altaRapidaPersonaRoles');

    test('sin <MÓDULO>,CREAR no se puede usar y no da acceso a Personas', function (string $uri, string $modulo) {
        $this->actingAs(User::factory()->conPermisos([$modulo => ['VER']])->create());

        $this->postJson(route("admin.{$uri}.personas-rapida"), altaRapidaPersonaDatos())->assertForbidden();
        $this->get(route('admin.personas.index'))->assertForbidden();
        $this->post(route('admin.personas.store'), altaRapidaPersonaDatos())->assertForbidden();
        expect(Persona::where('apellidos', 'Rápida')->exists())->toBeFalse();
    })->with('altaRapidaPersonaRoles');

    test('el formulario de alta ofrece la ventana "Crear" con los campos completos', function (string $uri, string $modulo) {
        $this->actingAs(User::factory()->conPermisos([$modulo => ['VER', 'CREAR']])->create());

        $this->get(route("admin.{$uri}.create"))->assertOk()
            ->assertSee("{$uri}\\/personas-rapida", false)
            ->assertSee('Nueva persona')
            ->assertSee('name="nro_documento"', false)
            ->assertSee('name="fecha_nacimiento"', false);
    })->with('altaRapidaPersonaRoles');
});

describe('alta rápida de persona y paciente (Turnos, Urgencias, Atención)', function () {
    beforeEach(function () {
        $this->recepcion = User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR'], 'PACIENTES' => ['CREAR']])->create();
        $this->actingAs($this->recepcion);
    });

    test('crea persona y paciente con ficha automática, sin cobertura, y los audita', function () {
        $respuesta = $this->postJson(route('admin.pacientes.alta-rapida'), altaRapidaPersonaDatos())->assertCreated();

        $persona = Persona::where('nro_documento', '7654321')->sole();
        $paciente = Paciente::where('persona_id', $persona->id)->sole();
        expect($paciente->nro_ficha)->toStartWith('FP-');
        $respuesta->assertExactJson(['id' => $paciente->id, 'texto' => "Rápida, Inventada — CI 7654321 · Ficha {$paciente->nro_ficha}"]);
        expect($paciente->persona->is($persona))->toBeTrue()
            ->and(HistoriaClinica::where('paciente_id', $paciente->id)->exists())->toBeTrue();
        expect(\App\Models\CoberturaPaciente::count())->toBe(0);

        foreach ([['personas', $persona->id], ['pacientes', $paciente->id]] as [$tabla, $id]) {
            $log = LogAuditoria::where('tabla_afectada', $tabla)->where('registro_afectado_id', (string) $id)->sole();
            expect($log)->accion->toBe(AccionAuditoria::CREAR)->usuario_id->toBe($this->recepcion->id);
        }

        // Un segundo paciente toma el número siguiente.
        $segundo = $this->postJson(route('admin.pacientes.alta-rapida'), altaRapidaPersonaDatos(['nro_documento' => '1234567']))->assertCreated();
        expect(Paciente::find($segundo->json('id'))->nro_ficha)->not->toBe($paciente->nro_ficha);
    });

    test('con ?como=historia devuelve el id de la historia clínica (Atención)', function () {
        $respuesta = $this->postJson(route('admin.pacientes.alta-rapida', ['como' => 'historia']), altaRapidaPersonaDatos())->assertCreated();

        $paciente = Paciente::sole();
        expect($respuesta->json('id'))->toBe($paciente->historiaClinica->id);
    });

    test('mismas validaciones que Personas; una empresa no es paciente; si falla no queda nada', function () {
        Persona::factory()->create(['nro_documento' => '7654321']);

        $this->postJson(route('admin.pacientes.alta-rapida'), altaRapidaPersonaDatos(['email' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['nro_documento', 'email']);
        $this->postJson(route('admin.pacientes.alta-rapida'), altaRapidaPersonaEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['tipo_persona']);

        expect(Paciente::count())->toBe(0)->and(Persona::where('apellidos', 'Rápida')->exists())->toBeFalse();
    });

    test('quien agenda sin PACIENTES,CREAR no puede crear y no ve el botón', function () {
        $soloAgenda = User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR']])->create();
        $this->actingAs($soloAgenda);

        $this->postJson(route('admin.pacientes.alta-rapida'), altaRapidaPersonaDatos())->assertForbidden();
        expect(Paciente::count())->toBe(0);

        $this->get(route('admin.turnos.create'))->assertOk()->assertDontSee('alta-rapida-persona-paciente_id');
        $this->get(route('admin.turnos.urgencias.create'))->assertOk()->assertDontSee('alta-rapida-persona-paciente_id');
    });

    test('con PACIENTES,CREAR, los formularios de Turnos y Urgencias ofrecen "Crear" para el paciente y no para el profesional', function () {
        foreach (['admin.turnos.create', 'admin.turnos.urgencias.create'] as $ruta) {
            $this->get(route($ruta))->assertOk()
                ->assertSee('alta-rapida-persona-paciente_id')
                ->assertSee('pacientes\\/alta-rapida', false)
                ->assertSee('ficha de paciente')
                ->assertDontSee('alta-rapida-persona-profesional_id');
        }
    });

    test('la ventana solo ofrece lo activo en país, departamento y ciudad', function () {
        $pais = \App\Models\Pais::create(['codigo' => 'ZZ', 'nombre' => 'Paisinactivolandia']);
        $departamento = \App\Models\Departamento::create(['pais_id' => $pais->id, 'nombre' => 'Deptoinactivo']);
        \App\Models\Ciudad::create(['departamento_id' => $departamento->id, 'nombre' => 'Ciudadinactiva']);
        $pais->desactivar();

        $this->get(route('admin.turnos.create'))->assertOk()
            ->assertDontSee('Paisinactivolandia')->assertDontSee('Deptoinactivo')->assertDontSee('Ciudadinactiva');
    });
});
