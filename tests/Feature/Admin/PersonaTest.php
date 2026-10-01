<?php

use App\Models\ModuloSistema;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());

    // El usuario de prueba ya creó CI (su persona la usa).
    $this->ci = TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
    $this->ruc = TipoDocumento::create(['codigo' => 'RUC', 'nombre' => 'RUC']);
    $this->pasaporte = TipoDocumento::create(['codigo' => 'PAS', 'nombre' => 'Pasaporte']);

    $this->paraguay = Pais::firstOrCreate(['nombre' => 'Paraguay']);

    // Tipos que acepta el módulo Personas (tipo_documento_modulo); CI es el predeterminado.
    ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI', 'RUC', 'PAS']);
});

function datosFisica(array $cambios = []): array
{
    return [
        'tipo_persona' => 'FISICA',
        'tipo_documento_id' => test()->ci->id,
        'nro_documento' => '1234567',
        'apellidos' => 'González',
        'nombres' => 'María',
        'fecha_nacimiento' => '1990-05-10',
        'sexo' => 'F',
        'pais_nacionalidad_id' => test()->paraguay->id,
        'estado_civil' => 'SOLTERO',
        'email' => 'maria@example.com',
        'telefono' => '0981 000 000',
        'direccion' => 'Asunción',
        ...$cambios,
    ];
}

function datosJuridica(array $cambios = []): array
{
    return [
        'tipo_persona' => 'JURIDICA',
        'tipo_documento_id' => test()->ruc->id,
        'nro_documento' => '80012345-6',
        'razon_social' => 'Laboratorio Central S.A.',
        'nombre_fantasia' => 'LabCentral',
        'representante_legal' => 'Juan Pérez',
        'email' => 'info@labcentral.com',
        'telefono' => '021 000 000',
        'direccion' => 'Asunción',
        ...$cambios,
    ];
}

test('las pantallas de personas cargan', function () {
    $persona = Persona::create(datosFisica());

    $this->get(route('admin.personas.index'))->assertOk()->assertSee('González, María');
    $this->get(route('admin.personas.create'))->assertOk();
    $this->get(route('admin.personas.edit', $persona))->assertOk();
});

test('crea una persona física', function () {
    $this->post(route('admin.personas.store'), enFormulario(datosFisica()))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.personas.index'));

    $persona = Persona::where('nro_documento', '1234567')->sole();
    expect($persona->apellidos)->toBe('González')
        ->and($persona->fecha_nacimiento->format('Y-m-d'))->toBe('1990-05-10')
        ->and($persona->estado->codigo)->toBe('ACTIVO')
        ->and($persona->tipoDocumento->codigo)->toBe('CI');
});

test('crea una persona jurídica', function () {
    $this->post(route('admin.personas.store'), enFormulario(datosJuridica()))->assertSessionHasNoErrors();

    expect(Persona::where('nro_documento', '80012345-6')->sole())->razon_social->toBe('Laboratorio Central S.A.')
        ->apellidos->toBeNull();
});

test('física exige apellidos, nombres y fecha de nacimiento', function () {
    $this->post(route('admin.personas.store'), enFormulario(datosFisica(['apellidos' => '', 'nombres' => '', 'fecha_nacimiento' => ''])))
        ->assertSessionHasErrors(['apellidos', 'nombres', 'fecha_nacimiento']);
});

test('jurídica exige razón social y no pide datos personales', function () {
    $this->post(route('admin.personas.store'), enFormulario(datosJuridica(['razon_social' => ''])))
        ->assertSessionHasErrors('razon_social')
        ->assertSessionDoesntHaveErrors(['apellidos', 'nombres', 'fecha_nacimiento']);
});

test('los campos del otro tipo quedan en null aunque vengan cargados', function () {
    $this->post(route('admin.personas.store'), enFormulario(datosFisica(['razon_social' => 'No va', 'nombre_fantasia' => 'Tampoco'])))
        ->assertSessionHasNoErrors();

    expect(Persona::where('nro_documento', '1234567')->sole())->razon_social->toBeNull()->nombre_fantasia->toBeNull();
});

test('al cambiar de física a jurídica se limpian los datos personales', function () {
    $persona = Persona::create(datosFisica(['tipo_documento_id' => $this->ruc->id]));

    $this->put(route('admin.personas.update', $persona), enFormulario(datosJuridica(['estado_id' => estadoId('ACTIVO')])))
        ->assertSessionHasNoErrors();

    expect($persona->fresh())
        ->tipo_persona->toBe('JURIDICA')
        ->razon_social->toBe('Laboratorio Central S.A.')
        ->apellidos->toBeNull()->nombres->toBeNull()->fecha_nacimiento->toBeNull()
        ->sexo->toBeNull()->estado_civil->toBeNull();
});

test('el número de documento es único por tipo de documento', function () {
    Persona::create(datosFisica());

    // Mismo número con otro tipo de documento: permitido.
    $this->post(route('admin.personas.store'), enFormulario(datosFisica(['tipo_documento_id' => $this->pasaporte->id])))
        ->assertSessionHasNoErrors();

    // Mismo tipo y número: rechazado.
    $this->post(route('admin.personas.store'), enFormulario(datosFisica()))
        ->assertSessionHasErrors('nro_documento');
});

test('editar sin cambiar el documento no choca con el unique', function () {
    $persona = Persona::create(datosFisica());

    $this->put(route('admin.personas.update', $persona), enFormulario(datosFisica(['nombres' => 'María José', 'estado_id' => estadoId('ACTIVO')])))
        ->assertSessionHasNoErrors();

    expect($persona->fresh()->nombres)->toBe('María José');
});

test('el tipo de documento tiene que estar habilitado para personas', function () {
    $otro = TipoDocumento::create(['codigo' => 'OTRO', 'nombre' => 'Otro documento']); // existe, pero no está en tipo_documento_modulo

    $this->post(route('admin.personas.store'), enFormulario(datosFisica(['tipo_documento_id' => $otro->id])))
        ->assertSessionHasErrors(['tipo_documento_id' => 'El tipo de documento no está habilitado para personas o está inactivo.']);
});

test('ya no depende del tipo de persona: una jurídica puede usar cualquier tipo habilitado', function () {
    $this->post(route('admin.personas.store'), enFormulario(datosJuridica(['tipo_documento_id' => $this->ci->id])))
        ->assertSessionHasNoErrors();
});

test('el formulario ofrece solo los tipos habilitados para personas, con el predeterminado elegido', function () {
    TipoDocumento::create(['codigo' => 'OTRO', 'nombre' => 'Otro documento']);

    $html = $this->get(route('admin.personas.create'))->assertOk()
        ->assertSee('CI — Cédula')->assertSee('RUC — RUC')->assertSee('PAS — Pasaporte')
        ->assertDontSee('Otro documento')
        ->getContent();

    expect($html)->toMatch('/<option value="'.$this->ci->id.'"\s+selected>/');
});

test('al editar se sigue mostrando el tipo que la persona ya tiene aunque no esté habilitado', function () {
    $otro = TipoDocumento::create(['codigo' => 'OTRO', 'nombre' => 'Otro documento']);
    $persona = Persona::create(datosFisica(['tipo_documento_id' => $otro->id]));

    $this->get(route('admin.personas.edit', $persona))->assertOk()->assertSee('OTRO — Otro documento');
    $this->put(route('admin.personas.update', $persona), enFormulario(datosFisica(['tipo_documento_id' => $otro->id, 'estado_id' => estadoId('ACTIVO')])))
        ->assertSessionHasNoErrors();
});

test('no se puede usar un tipo de documento inactivo en una persona nueva', function () {
    $this->ci->update(['estado_id' => estadoId('INACTIVO')]);

    $this->post(route('admin.personas.store'), enFormulario(datosFisica()))
        ->assertSessionHasErrors('tipo_documento_id');
});

test('desactivar pasa la persona a INACTIVO sin borrarla', function () {
    $persona = Persona::create(datosFisica());

    $this->patch(route('admin.personas.desactivar', $persona))
        ->assertRedirect(route('admin.personas.index'));

    expect($persona->fresh())->not->toBeNull()->estado->codigo->toBe('INACTIVO');
});

test('el buscador filtra por documento, nombre y razón social', function () {
    Persona::create(datosFisica());
    Persona::create(datosJuridica());

    $this->get(route('admin.personas.index', ['q' => '1234']))->assertSee('González')->assertDontSee('Laboratorio');
    $this->get(route('admin.personas.index', ['q' => 'laboratorio']))->assertSee('Laboratorio')->assertDontSee('González');
});

test('cambiar el email de una persona con usuario actualiza también el email del usuario', function () {
    $usuario = User::factory()->create(['email' => 'viejo@clinexa.test']);

    $this->put(route('admin.personas.update', $usuario->persona), enFormulario([
        ...$usuario->persona->only(['tipo_persona', 'tipo_documento_id', 'nro_documento', 'apellidos', 'nombres', 'telefono', 'direccion', 'estado_id']),
        'fecha_nacimiento' => $usuario->persona->fecha_nacimiento->format('Y-m-d'),
        'email' => 'Nuevo@Clinexa.test',
    ]))->assertSessionHasNoErrors();

    expect($usuario->persona->fresh()->email)->toBe('Nuevo@Clinexa.test')
        // En users queda en minúsculas: el login compara el email textualmente.
        ->and($usuario->fresh()->email)->toBe('nuevo@clinexa.test');
});

test('una persona sin usuario puede cambiar su email sin afectar a ningún usuario', function () {
    $persona = Persona::create(datosFisica());
    $emailsAntes = User::pluck('email')->all();

    $persona->update(['email' => 'otro@clinexa.test']);

    expect(User::pluck('email')->all())->toBe($emailsAntes);
});

test('no se puede poner a una persona con usuario un email que ya usa otro usuario', function () {
    $usuario = User::factory()->create();
    $otro = User::factory()->create(['email' => 'ocupado@clinexa.test']);

    $this->put(route('admin.personas.update', $usuario->persona), enFormulario([
        ...$usuario->persona->only(['tipo_persona', 'tipo_documento_id', 'nro_documento', 'apellidos', 'nombres', 'telefono', 'direccion', 'estado_id']),
        'fecha_nacimiento' => $usuario->persona->fecha_nacimiento->format('Y-m-d'),
        'email' => 'OCUPADO@clinexa.test',
    ]))->assertSessionHasErrors(['email' => 'Ese email ya lo usa otro usuario del sistema.']);

    expect($usuario->fresh()->email)->not->toBe('ocupado@clinexa.test');
});
