<?php

use App\Models\Especialidad;
use App\Models\ModuloSistema;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\PropietarioEquipo;
use App\Models\Proveedor;
use App\Models\ResponsablePago;
use App\Models\User;
use App\Support\BuscadorPersonas;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
});

function personaJuridica(array $datos = []): Persona
{
    return Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Laboratorio Central S.A.', ...$datos]);
}

/*
 * [ruta, modelo, campos propios válidos, ¿acepta personas jurídicas?]
 */
dataset('roles', [
    'pacientes' => ['pacientes', Paciente::class, ['nro_ficha' => 'F-100'], false],
    'profesionales' => ['profesionales', Profesional::class, ['matricula' => 'MP-123'], false],
    'proveedores' => ['proveedores', Proveedor::class, ['condiciones_comerciales' => 'Pago a 30 días'], true],
    'propietarios de equipo' => ['propietarios-equipo', PropietarioEquipo::class, ['datos_bancarios' => 'Banco Continental, CA 123456'], true],
    'responsables de pago' => ['responsables-pago', ResponsablePago::class, ['limite_credito' => '1500000'], true],
]);

test('los 5 módulos de roles existen con ACTIVO (inicial) e INACTIVO', function () {
    foreach (['PACIENTES', 'PROFESIONALES', 'PROVEEDORES', 'PROPIETARIOS_EQUIPO', 'RESPONSABLES_PAGO'] as $codigo) {
        $modulo = ModuloSistema::where('codigo', $codigo)->with('estados')->sole();
        expect($modulo->estados->pluck('codigo')->sort()->values()->all())->toBe(['ACTIVO', 'INACTIVO'])
            ->and($modulo->estados->firstWhere('pivot.es_inicial', true)->codigo)->toBe('ACTIVO');
    }
});

test('crear el rol eligiendo una persona existente, con sus campos propios', function (string $ruta, string $modelo, array $campos) {
    $persona = Persona::factory()->create();

    $this->get(route("admin.{$ruta}.create"))->assertOk()
        // La URL del buscador va dentro de @js(): en JSON, con las barras escapadas.
        ->assertSee(str_replace('/', '\/', route("admin.{$ruta}.personas-disponibles")), false)
        ->assertSee('name="persona_id"', false);

    $this->post(route("admin.{$ruta}.store"), ['persona_id' => $persona->id, ...$campos])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route("admin.{$ruta}.index"));

    $rol = $modelo::where('persona_id', $persona->id)->sole();
    foreach ($campos as $campo => $valor) {
        expect((string) $rol->{$campo})->toStartWith($valor);
    }
    expect($rol->estado->codigo)->toBe('ACTIVO');
})->with('roles');

test('no se puede crear dos veces el mismo rol para la misma persona', function (string $ruta, string $modelo, array $campos) {
    $persona = Persona::factory()->create();
    $modelo::create(['persona_id' => $persona->id, ...$campos]);

    $otrosCampos = array_map(fn ($valor) => is_numeric($valor) ? $valor : $valor.'-2', $campos);
    $this->post(route("admin.{$ruta}.store"), ['persona_id' => $persona->id, ...$otrosCampos])
        ->assertSessionHasErrors('persona_id');

    expect($modelo::where('persona_id', $persona->id)->count())->toBe(1);
})->with('roles');

test('una persona puede tener varios roles distintos a la vez', function () {
    $persona = Persona::factory()->create();

    $this->post(route('admin.profesionales.store'), ['persona_id' => $persona->id, 'matricula' => 'MP-1'])->assertSessionHasNoErrors();
    $this->post(route('admin.propietarios-equipo.store'), ['persona_id' => $persona->id])->assertSessionHasNoErrors();
    $this->post(route('admin.pacientes.store'), ['persona_id' => $persona->id, 'nro_ficha' => '000001'])->assertSessionHasNoErrors();

    expect($persona->fresh())->profesional->not->toBeNull()->propietarioEquipo->not->toBeNull()->paciente->not->toBeNull();
});

test('el buscador de personas excluye a las que ya tienen el rol, las inactivas y los tipos no permitidos', function (string $ruta, string $modelo, array $campos, bool $aceptaJuridicas) {
    $libre = Persona::factory()->create(['apellidos' => 'Libre', 'nombres' => 'Ana']);
    $conRol = Persona::factory()->create(['apellidos' => 'ConRol', 'nombres' => 'Ana']);
    $modelo::create(['persona_id' => $conRol->id, ...$campos]);
    Persona::factory()->inactiva()->create(['apellidos' => 'Inactiva', 'nombres' => 'Ana']);
    personaJuridica();

    $buscar = fn (string $q) => collect($this->getJson(route("admin.{$ruta}.personas-disponibles", ['q' => $q]))->assertOk()->json())
        ->pluck('texto')->join(' | ');

    expect($buscar('Ana'))->toContain('Libre, Ana')->not->toContain('ConRol')->not->toContain('Inactiva');
    $aceptaJuridicas
        ? expect($buscar('Laboratorio'))->toContain('Laboratorio Central S.A.')
        : expect($buscar('Laboratorio'))->not->toContain('Laboratorio Central S.A.');

    // Filtra por nombre o documento.
    $porDocumento = $this->getJson(route("admin.{$ruta}.personas-disponibles", ['q' => $libre->nro_documento]))->json();
    expect(collect($porDocumento)->pluck('id')->all())->toBe([$libre->id]);
})->with('roles');

test('el buscador de personas de los roles muestra nombre y documento, sin email', function (string $ruta) {
    $persona = Persona::factory()->create(['apellidos' => 'Ruiz', 'nombres' => 'Liz', 'nro_documento' => '4567890', 'email' => 'liz@clinexa.test']);

    expect($this->getJson(route("admin.{$ruta}.personas-disponibles", ['q' => 'Ruiz']))->json('0.texto'))
        ->toBe("Ruiz, Liz — {$persona->tipoDocumento->codigo} 4567890")
        ->not->toContain('liz@clinexa.test');
})->with('roles');

test('el buscador de personas nunca devuelve más de 15 resultados, haya las que haya en la base', function (string $ruta) {
    // 40 personas disponibles que coinciden con la búsqueda, más de dos veces el tope.
    Persona::factory()->count(40)->sequence(fn ($s) => ['apellidos' => 'Gómez', 'nombres' => "Persona {$s->index}"])->create();

    expect(Persona::whereLike('apellidos', 'Gómez')->count())->toBe(40)
        ->and($this->getJson(route("admin.{$ruta}.personas-disponibles", ['q' => 'Gómez']))->assertOk()->json())
        ->toHaveCount(BuscadorPersonas::LIMITE)
        ->and(BuscadorPersonas::LIMITE)->toBe(15);
})->with('roles');

test('con menos de 2 caracteres el buscador no devuelve nada y no consulta personas', function (string $ruta) {
    Persona::factory()->count(5)->create(['nombres' => 'Ana']);

    foreach (['', ' ', 'A', ' a '] as $q) {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson(route("admin.{$ruta}.personas-disponibles", ['q' => $q]))->assertOk()->assertExactJson([]);

        // La búsqueda de disponibles filtra por tipo_persona; la otra lectura de "personas" es la del
        // middleware de acceso (la persona del usuario logueado) y no cuenta.
        $busquedas = collect(DB::getQueryLog())->filter(fn ($consulta) => str_contains($consulta['query'], '"tipo_persona" in'));
        expect($busquedas)->toBeEmpty();
    }

    // Con 2 caracteres ya busca.
    expect($this->getJson(route("admin.{$ruta}.personas-disponibles", ['q' => 'An']))->json())->not->toBeEmpty();
})->with('roles');

test('el formulario de alta no busca al abrirse: pide escribir al menos 2 caracteres', function () {
    $html = $this->get(route('admin.pacientes.create'))->assertOk()
        ->assertSee('Escriba al menos 2 caracteres del nombre o del documento para buscar.')
        ->assertSee('hasta 15 resultados')
        ->getContent();

    expect($html)->toContain('x-on:input.debounce.350ms="buscar()"')->toContain('minimo: 2');
});

test('no se puede crear el rol para una persona inactiva', function (string $ruta, string $modelo, array $campos) {
    $persona = Persona::factory()->inactiva()->create();

    $this->post(route("admin.{$ruta}.store"), ['persona_id' => $persona->id, ...$campos])
        ->assertSessionHasErrors(['persona_id' => 'La persona elegida está inactiva.']);
})->with('roles');

test('pacientes y profesionales solo pueden ser personas físicas; el resto acepta jurídicas', function (string $ruta, string $modelo, array $campos, bool $aceptaJuridicas) {
    $empresa = personaJuridica();

    $respuesta = $this->post(route("admin.{$ruta}.store"), ['persona_id' => $empresa->id, ...$campos]);

    $aceptaJuridicas ? $respuesta->assertSessionHasNoErrors() : $respuesta->assertSessionHasErrors('persona_id');
    expect($modelo::where('persona_id', $empresa->id)->exists())->toBe($aceptaJuridicas);
})->with('roles');

test('el listado muestra nombre y documento de la persona y busca en vivo', function (string $ruta, string $modelo, array $campos) {
    $ana = Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Carmen', 'nro_documento' => '5234567']);
    $otra = Persona::factory()->create(['apellidos' => 'Ramírez', 'nombres' => 'Ana', 'nro_documento' => '5876543']);
    $modelo::create(['persona_id' => $ana->id, ...$campos]);
    $modelo::create(['persona_id' => $otra->id, ...array_map(fn ($valor) => is_numeric($valor) ? $valor : $valor.'-2', $campos)]);

    $this->get(route("admin.{$ruta}.index"))->assertOk()->assertSeeInOrder(['Duarte, Carmen', '5234567'])->assertSee('Ramírez, Ana');

    // Mismo buscador en vivo que el resto: por documento o nombre, con y sin AJAX.
    $this->get(route("admin.{$ruta}.index", ['q' => '5234']))->assertSee('Duarte, Carmen')->assertDontSee('Ramírez, Ana');
    $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route("admin.{$ruta}.index", ['q' => 'ramír']))
        ->assertOk()->assertSee('Ramírez, Ana')->assertDontSee('Duarte, Carmen')->assertDontSee('<html', false);
})->with('roles');

test('al editar se cambian los campos propios y el estado, pero nunca la persona', function (string $ruta, string $modelo, array $campos) {
    $persona = Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Carmen']);
    $rol = $modelo::create(['persona_id' => $persona->id, ...$campos]);
    $otraPersona = Persona::factory()->create();

    $this->get(route("admin.{$ruta}.edit", $rol))->assertOk()
        ->assertSee('Duarte, Carmen')
        ->assertSee('La persona no se cambia una vez creado el rol.')
        ->assertDontSee('name="persona_id"', false);

    $nuevos = array_map(fn ($valor) => is_numeric($valor) ? (string) ($valor + 1) : $valor.' (editado)', $campos);
    $this->put(route("admin.{$ruta}.update", $rol), [...$nuevos, 'persona_id' => $otraPersona->id, 'estado_id' => estadoId('INACTIVO')])
        ->assertSessionHasNoErrors();

    $rol = $rol->fresh();
    expect($rol->persona_id)->toBe($persona->id)->and($rol->estado->codigo)->toBe('INACTIVO');
    foreach ($nuevos as $campo => $valor) {
        expect((string) $rol->{$campo})->toStartWith($valor);
    }
})->with('roles');

test('desactivar pasa el rol a INACTIVO sin borrarlo y sin tocar la persona', function (string $ruta, string $modelo, array $campos) {
    $persona = Persona::factory()->create();
    $rol = $modelo::create(['persona_id' => $persona->id, ...$campos]);

    $this->get(route("admin.{$ruta}.index"))->assertSee(route("admin.{$ruta}.desactivar", $rol));
    $this->patch(route("admin.{$ruta}.desactivar", $rol))->assertRedirect(route("admin.{$ruta}.index"));

    expect($rol->fresh())->not->toBeNull()->estado->codigo->toBe('INACTIVO')
        ->and($persona->fresh()->estado->codigo)->toBe('ACTIVO');
    $this->get(route("admin.{$ruta}.index"))->assertDontSee(route("admin.{$ruta}.desactivar", $rol));
})->with('roles');

test('permisos: sin VER no se entra; con VER pero sin CREAR no se crea ni se busca persona', function (string $ruta, string $modelo) {
    $codigo = (new $modelo)::moduloEstado();

    $this->actingAs(User::factory()->conPermisos([])->create());
    $this->get(route("admin.{$ruta}.index"))->assertForbidden();

    $this->actingAs(User::factory()->conPermisos([$codigo => ['VER']])->create());
    $this->get(route("admin.{$ruta}.index"))->assertOk()->assertDontSee(route("admin.{$ruta}.create"));
    $this->get(route("admin.{$ruta}.create"))->assertForbidden();
    $this->getJson(route("admin.{$ruta}.personas-disponibles"))->assertForbidden();
    $this->post(route("admin.{$ruta}.store"), ['persona_id' => Persona::factory()->create()->id])->assertForbidden();
})->with('roles');

test('los roles aparecen en el menú de Administración según el permiso VER', function () {
    $this->get('/dashboard')->assertSee(route('admin.pacientes.index'))->assertSee('Responsables de pago');

    $this->actingAs(User::factory()->conPermisos(['PACIENTES' => ['VER']])->create());
    $this->get('/dashboard')->assertSee(route('admin.pacientes.index'))->assertDontSee(route('admin.profesionales.index'));
});

describe('paciente', function () {
    test('propone el siguiente número de ficha libre y se puede cambiar', function () {
        $this->get(route('admin.pacientes.create'))->assertSee('value="FP-0000001"', false);

        Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000123']);
        Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'PAPEL-7']); // no numérica: se ignora
        $this->get(route('admin.pacientes.create'))->assertSee('value="FP-0000124"', false);

        // Un número viejo solo con dígitos también cuenta para el correlativo.
        Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => '000200']);
        expect(Paciente::siguienteNroFicha())->toBe('FP-0000201');

        $this->post(route('admin.pacientes.store'), ['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'A-55'])
            ->assertSessionHasNoErrors();
    });

    test('el número de ficha es obligatorio y único', function () {
        Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => '000001']);

        $this->post(route('admin.pacientes.store'), ['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => '000001'])
            ->assertSessionHasErrors('nro_ficha');
        $this->post(route('admin.pacientes.store'), ['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => ''])
            ->assertSessionHasErrors('nro_ficha');
    });
});

describe('profesional', function () {
    beforeEach(function () {
        $this->gineco = Especialidad::create(['nombre' => 'Ginecología y Obstetricia']);
        $this->eco = Especialidad::create(['nombre' => 'Ecografía']);
    });

    test('se crea con varias especialidades, cada una con matrícula y fecha desde', function () {
        $persona = Persona::factory()->create();

        $this->post(route('admin.profesionales.store'), [
            'persona_id' => $persona->id, 'matricula' => 'MP-500', 'con_especialidades' => '1',
            'especialidades' => [
                ['especialidad_id' => $this->gineco->id, 'nro_matricula_especialidad' => 'GO-77', 'fecha_desde' => '2015-03-01'],
                ['especialidad_id' => $this->eco->id, 'nro_matricula_especialidad' => '', 'fecha_desde' => '2020-06-15'],
            ],
        ])->assertSessionHasNoErrors();

        $especialidades = Profesional::where('persona_id', $persona->id)->sole()->especialidades->keyBy('nombre');
        expect($especialidades)->toHaveCount(2)
            ->and($especialidades['Ginecología y Obstetricia']->pivot->nro_matricula_especialidad)->toBe('GO-77')
            ->and(substr($especialidades['Ginecología y Obstetricia']->pivot->fecha_desde, 0, 10))->toBe('2015-03-01')
            ->and($especialidades['Ecografía']->pivot->nro_matricula_especialidad)->toBeNull();

        $this->get(route('admin.profesionales.index'))->assertSee('Ginecología y Obstetricia, Ecografía');
    });

    test('al editar se reemplazan las especialidades por las del formulario', function () {
        $profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);
        $profesional->especialidades()->attach($this->gineco->id, ['fecha_desde' => '2015-01-01']);

        $this->put(route('admin.profesionales.update', $profesional), [
            'matricula' => 'MP-1', 'estado_id' => estadoId('ACTIVO'), 'con_especialidades' => '1',
            'especialidades' => [['especialidad_id' => $this->eco->id, 'fecha_desde' => '2021-01-01']],
        ])->assertSessionHasNoErrors();

        expect($profesional->fresh()->especialidades->pluck('nombre')->all())->toBe(['Ecografía']);
    });

    test('sin la marca del formulario (sin JavaScript) no se tocan las especialidades', function () {
        $profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);
        $profesional->especialidades()->attach($this->gineco->id, ['fecha_desde' => '2015-01-01']);

        $this->put(route('admin.profesionales.update', $profesional), ['matricula' => 'MP-2', 'estado_id' => estadoId('ACTIVO')])
            ->assertSessionHasNoErrors();

        expect($profesional->fresh())->matricula->toBe('MP-2')
            ->and($profesional->fresh()->especialidades->pluck('nombre')->all())->toBe(['Ginecología y Obstetricia']);
    });

    test('valida especialidades: no repetidas, activas y con fecha desde', function () {
        $this->eco->desactivar();
        $base = ['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-9', 'con_especialidades' => '1'];

        $this->post(route('admin.profesionales.store'), [...$base, 'especialidades' => [
            ['especialidad_id' => $this->gineco->id, 'fecha_desde' => '2015-01-01'],
            ['especialidad_id' => $this->gineco->id, 'fecha_desde' => '2016-01-01'],
        ]])->assertSessionHasErrors('especialidades.0.especialidad_id');

        $this->post(route('admin.profesionales.store'), [...$base, 'especialidades' => [
            ['especialidad_id' => $this->eco->id, 'fecha_desde' => '2015-01-01'],
        ]])->assertSessionHasErrors('especialidades.0.especialidad_id');

        $this->post(route('admin.profesionales.store'), [...$base, 'especialidades' => [
            ['especialidad_id' => $this->gineco->id, 'fecha_desde' => ''],
        ]])->assertSessionHasErrors('especialidades.0.fecha_desde');

        expect(Profesional::count())->toBe(0);
    });

    test('la matrícula es obligatoria y única', function () {
        Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);

        $this->post(route('admin.profesionales.store'), ['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1'])
            ->assertSessionHasErrors('matricula');
    });

    test('el formulario ofrece solo especialidades activas', function () {
        $this->eco->desactivar();

        $this->get(route('admin.profesionales.create'))->assertOk()
            ->assertSee('Ginecología y Obstetricia')->assertDontSee('>Ecografía<', false);
    });
});

test('el límite de crédito es opcional, numérico y no negativo', function () {
    $persona = fn () => Persona::factory()->create()->id;

    $this->post(route('admin.responsables-pago.store'), ['persona_id' => $persona()])->assertSessionHasNoErrors();
    $this->post(route('admin.responsables-pago.store'), ['persona_id' => $persona(), 'limite_credito' => '-1'])->assertSessionHasErrors('limite_credito');
    $this->post(route('admin.responsables-pago.store'), ['persona_id' => $persona(), 'limite_credito' => 'mucho'])->assertSessionHasErrors('limite_credito');

    $this->get(route('admin.responsables-pago.index'))->assertSee('Sin límite');
});
