<?php

use App\Models\Especialidad;
use App\Models\MedioPago;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Sucursal;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Support\Unicidad;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
});

function verificar(array $parametros)
{
    return test()->getJson(route('verificar-unico', $parametros));
}

test('avisa si la matrícula ya existe y la acepta si está libre', function () {
    Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-500']);

    verificar(['campo' => 'profesional.matricula', 'valor' => 'MP-500'])->assertOk()
        ->assertExactJson(['disponible' => false, 'mensaje' => 'Esta matrícula ya está registrada. Corríjalo para poder guardar.']);
    verificar(['campo' => 'profesional.matricula', 'valor' => 'MP-501'])->assertOk()
        ->assertExactJson(['disponible' => true, 'mensaje' => null]);
});

test('al editar no se compara consigo mismo, pero sí con los demás', function () {
    $propia = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);
    Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-2']);

    verificar(['campo' => 'profesional.matricula', 'valor' => 'MP-1', 'ignorar' => $propia->id])->assertJson(['disponible' => true]);
    verificar(['campo' => 'profesional.matricula', 'valor' => 'MP-2', 'ignorar' => $propia->id])->assertJson(['disponible' => false]);
});

test('el documento de la persona es único junto con el tipo de documento', function () {
    $ci = TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
    $ruc = TipoDocumento::firstOrCreate(['codigo' => 'RUC'], ['nombre' => 'RUC']);
    $persona = Persona::factory()->create(['tipo_documento_id' => $ci->id, 'nro_documento' => '4567890']);

    verificar(['campo' => 'persona.documento', 'valor' => '4567890', 'tipo_documento_id' => $ci->id])
        ->assertJson(['disponible' => false, 'mensaje' => 'Ya hay una persona registrada con ese tipo y número de documento. Corríjalo para poder guardar.']);
    verificar(['campo' => 'persona.documento', 'valor' => '4567890', 'tipo_documento_id' => $ruc->id])->assertJson(['disponible' => true]);
    verificar(['campo' => 'persona.documento', 'valor' => '4567890', 'tipo_documento_id' => $ci->id, 'ignorar' => $persona->id])
        ->assertJson(['disponible' => true]);
});

test('número de ficha de paciente y nombre de sucursal', function () {
    $paciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000007']);
    Sucursal::create(['nombre' => 'Plenitud Mujer', 'direccion' => 'X', 'telefono' => '1']);

    verificar(['campo' => 'paciente.nro_ficha', 'valor' => 'FP-0000007'])->assertJson(['disponible' => false, 'mensaje' => 'Este número de ficha ya está registrado. Corríjalo para poder guardar.']);
    verificar(['campo' => 'paciente.nro_ficha', 'valor' => 'FP-0000007', 'ignorar' => $paciente->id])->assertJson(['disponible' => true]);
    verificar(['campo' => 'sucursal.nombre', 'valor' => '  Plenitud Mujer '])->assertJson(['disponible' => false]);
});

test('vacío o con menos de 2 caracteres no consulta: se considera libre', function () {
    Especialidad::create(['nombre' => 'X']);

    foreach (['', ' ', 'X'] as $valor) {
        verificar(['campo' => 'especialidad.nombre', 'valor' => $valor])->assertExactJson(['disponible' => true, 'mensaje' => null]);
    }
    expect(Unicidad::MINIMO)->toBe(2);
});

test('solo se pueden consultar los campos registrados', function () {
    verificar(['campo' => 'users.password', 'valor' => 'algo'])->assertUnprocessable()->assertJsonValidationErrors('campo');
    verificar(['valor' => 'algo'])->assertUnprocessable();
});

test('exige estar logueado y el permiso del módulo: CREAR al dar de alta, EDITAR al editar', function () {
    auth()->logout();
    verificar(['campo' => 'profesional.matricula', 'valor' => 'MP-1'])->assertUnauthorized();

    $this->actingAs(User::factory()->conPermisos(['PROFESIONALES' => ['VER']])->create());
    verificar(['campo' => 'profesional.matricula', 'valor' => 'MP-1'])->assertForbidden();

    $this->actingAs(User::factory()->conPermisos(['PROFESIONALES' => ['VER', 'CREAR']])->create());
    verificar(['campo' => 'profesional.matricula', 'valor' => 'MP-1'])->assertOk();
    verificar(['campo' => 'profesional.matricula', 'valor' => 'MP-1', 'ignorar' => 1])->assertForbidden();
    verificar(['campo' => 'paciente.nro_ficha', 'valor' => 'FP-1'])->assertForbidden(); // otro módulo
});

test('el email de persona solo se verifica contra otros usuarios si la persona tiene usuario', function () {
    $conUsuario = User::factory()->create()->persona;
    $otroUsuario = User::factory()->create();
    $sinUsuario = Persona::factory()->create();

    verificar(['campo' => 'persona.email', 'valor' => strtoupper($otroUsuario->email), 'ignorar' => $conUsuario->id])
        ->assertJson(['disponible' => false, 'mensaje' => 'Ese email ya lo usa otro usuario del sistema. Corríjalo para poder guardar.']);
    verificar(['campo' => 'persona.email', 'valor' => $conUsuario->email, 'ignorar' => $conUsuario->id])->assertJson(['disponible' => true]);
    verificar(['campo' => 'persona.email', 'valor' => $otroUsuario->email, 'ignorar' => $sinUsuario->id])->assertJson(['disponible' => true]);
});

describe('formularios', function () {
    test('el alta de profesional verifica la matrícula al salir del campo, sin registro a ignorar', function () {
        $html = $this->get(route('admin.profesionales.create'))->assertOk()->getContent();

        expect($html)->toContain('x-data="verificarUnico(')
            ->toContain("campo: 'profesional.matricula', ignorar: null")
            ->toContain('x-on:blur="verificar()"')
            ->toContain('id="matricula_unico"');
    });

    test('al editar, el formulario manda el id propio para no compararse consigo mismo', function () {
        $profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-9']);
        $sucursal = Sucursal::create(['nombre' => 'Centro', 'direccion' => 'X', 'telefono' => '1']);

        expect($this->get(route('admin.profesionales.edit', $profesional))->getContent())->toContain("campo: 'profesional.matricula', ignorar: {$profesional->id}")
            ->and($this->get(route('admin.sucursales.edit', $sucursal))->getContent())->toContain("campo: 'sucursal.nombre', ignorar: {$sucursal->id}");
    });

    test('persona: el documento se verifica junto con el tipo; el email solo si tiene usuario', function () {
        $html = $this->get(route('admin.personas.create'))->getContent();
        expect($html)->toContain("campo: 'persona.documento'")->toMatch('/con: JSON\.parse\(\'\[\\\\u0022tipo_documento_id\\\\u0022\]\'\)/')
            ->not->toContain("campo: 'persona.email'");

        $conUsuario = User::factory()->create()->persona;
        expect($this->get(route('admin.personas.edit', $conUsuario))->getContent())->toContain("campo: 'persona.email', ignorar: {$conUsuario->id}");
    });

    test('los formularios de los campos únicos tienen la verificación', function (string $ruta, string $campo) {
        expect($this->get(route($ruta))->assertOk()->getContent())->toContain("campo: '{$campo}'");
    })->with([
        ['admin.pacientes.create', 'paciente.nro_ficha'],
        ['admin.tipos-documento.create', 'tipo_documento.codigo'],
        ['admin.procedimientos.create', 'procedimiento.codigo'],
        ['admin.cie10.create', 'cie10.codigo'],
        ['admin.especialidades.create', 'especialidad.nombre'],
        ['admin.categorias-gasto.create', 'categoria_gasto.nombre'],
        ['admin.categorias-proveedor.create', 'categoria_proveedor.nombre'],
        ['admin.tipos-red-social.create', 'tipo_red_social.nombre'],
        ['admin.tipos-bloque-anamnesis.create', 'tipo_bloque_anamnesis.nombre'],
        ['admin.tipos-indicacion.create', 'tipo_indicacion.nombre'],
        ['admin.perfiles-acceso.create', 'perfil_acceso.nombre'],
        ['admin.sucursales.create', 'sucursal.nombre'],
        ['admin.medios-pago.create', 'medio_pago.nombre'],
    ]);
});

test('la validación al guardar sigue siendo el respaldo, también para los nombres nuevos únicos', function () {
    Sucursal::create(['nombre' => 'Centro', 'direccion' => 'X', 'telefono' => '1']);
    MedioPago::create(['nombre' => 'Efectivo']);
    Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);

    $this->post(route('admin.sucursales.store'), ['nombre' => 'Centro', 'direccion' => 'Y', 'telefono' => '2'])->assertSessionHasErrors('nombre');
    $this->post(route('admin.medios-pago.store'), ['nombre' => 'Efectivo'])->assertSessionHasErrors('nombre');
    $this->post(route('admin.profesionales.store'), ['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1'])
        ->assertSessionHasErrors('matricula');

    expect(Sucursal::count())->toBe(1)->and(MedioPago::count())->toBe(1);
});
