<?php

use App\Models\ModuloSistema;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());

    $this->personas = ModuloSistema::where('codigo', 'PERSONAS')->sole();
    $this->ci = TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula de identidad']);
    $this->ruc = TipoDocumento::create(['codigo' => 'RUC', 'nombre' => 'RUC']);
    // Personas acepta CI (predeterminado) y RUC.
    $this->personas->configurarTiposDocumento(['CI', 'RUC']);
});

/** Módulos habilitados de un tipo: [id de módulo => es_predeterminado]. */
function habilitacion(TipoDocumento $tipo): array
{
    return $tipo->modulos()->get()->mapWithKeys(fn ($modulo) => [$modulo->id => (bool) $modulo->pivot->es_predeterminado])->all();
}

function editarTipo(TipoDocumento $tipo, array $cambios = [])
{
    return test()->put(route('admin.tipos-documento.update', $tipo), [
        'codigo' => $tipo->codigo,
        'nombre' => $tipo->nombre,
        'estado_id' => estadoId('ACTIVO'),
        ...$cambios,
    ]);
}

test('el formulario ofrece los módulos que usan tipos de documento, con su predeterminado', function () {
    $this->get(route('admin.tipos-documento.create'))->assertOk()
        ->assertSee('Habilitado en')
        ->assertSee('name="modulos[]" value="'.$this->personas->id.'"', false)
        ->assertSee('name="predeterminado[]" value="'.$this->personas->id.'"', false)
        ->assertSee('Predeterminado actual: Cédula de identidad (CI).')
        // Módulos sin tipos de documento no aparecen.
        ->assertDontSee('value="'.ModuloSistema::where('codigo', 'USUARIOS')->sole()->id.'"', false);

    $this->get(route('admin.tipos-documento.edit', $this->ci))->assertOk()
        ->assertSee('Hoy es el predeterminado de este módulo.');
});

test('un módulo nuevo marcado con usa_tipos_documento aparece solo y se puede habilitar', function () {
    $facturacion = ModuloSistema::create(['codigo' => 'FACTURACION', 'nombre' => 'Facturación', 'usa_tipos_documento' => true]);

    $this->get(route('admin.tipos-documento.edit', $this->ruc))->assertOk()
        ->assertSee('Facturación')
        ->assertSee('name="modulos[]" value="'.$facturacion->id.'"', false);

    editarTipo($this->ruc, ['modulos' => [$this->personas->id, $facturacion->id], 'predeterminado' => [$facturacion->id]])
        ->assertSessionHasNoErrors();

    expect(habilitacion($this->ruc))->toBe([$this->personas->id => false, $facturacion->id => true])
        ->and(TipoDocumento::predeterminadoPara('FACTURACION'))->toBe($this->ruc->id)
        ->and(TipoDocumento::predeterminadoPara('PERSONAS'))->toBe($this->ci->id);
});

test('crear habilita los módulos tildados', function () {
    $this->post(route('admin.tipos-documento.store'), [
        'codigo' => 'PASAPORTE', 'nombre' => 'Pasaporte', 'modulos' => [$this->personas->id],
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.tipos-documento.index'))->assertSessionMissing('aviso');

    $pasaporte = TipoDocumento::where('codigo', 'PASAPORTE')->sole();
    expect(habilitacion($pasaporte))->toBe([$this->personas->id => false])
        ->and(TipoDocumento::query()->habilitadosPara('PERSONAS')->pluck('codigo')->all())->toContain('PASAPORTE');
});

test('crear sin módulos se permite, advierte en el formulario y avisa que quedó sin uso', function () {
    // La advertencia está en el formulario desde antes de guardar (visible si no hay nada tildado).
    $this->get(route('admin.tipos-documento.create'))
        ->assertSee('No está habilitado en ningún módulo.')
        ->assertSee('x-show="habilitados.length === 0"', false)
        ->assertDontSee('x-show="habilitados.length === 0" style="display: none"', false);

    $this->post(route('admin.tipos-documento.store'), ['codigo' => 'DNI', 'nombre' => 'DNI'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('aviso', fn (string $aviso) => str_contains($aviso, 'quedó sin uso'));

    expect(habilitacion(TipoDocumento::where('codigo', 'DNI')->sole()))->toBe([]);
});

test('editar habilita y deshabilita módulos', function () {
    $facturacion = ModuloSistema::create(['codigo' => 'FACTURACION', 'nombre' => 'Facturación', 'usa_tipos_documento' => true]);

    editarTipo($this->ruc, ['modulos' => [$facturacion->id]])->assertSessionHasNoErrors()->assertSessionMissing('aviso');
    expect(habilitacion($this->ruc))->toBe([$facturacion->id => false])
        ->and(TipoDocumento::query()->habilitadosPara('PERSONAS')->pluck('codigo')->all())->toBe(['CI']);

    // Destildar el único que quedaba: se permite, con aviso.
    editarTipo($this->ruc, ['modulos' => []])->assertSessionHasNoErrors()
        ->assertSessionHas('aviso', fn (string $aviso) => str_contains($aviso, 'RUC quedó sin uso'));
    expect(habilitacion($this->ruc))->toBe([]);
});

test('solo hay un predeterminado por módulo: marcar otro reemplaza al anterior', function () {
    editarTipo($this->ruc, ['modulos' => [$this->personas->id], 'predeterminado' => [$this->personas->id]])
        ->assertSessionHasNoErrors();

    expect(habilitacion($this->ruc))->toBe([$this->personas->id => true])
        ->and(habilitacion($this->ci))->toBe([$this->personas->id => false])
        ->and(TipoDocumento::predeterminadoPara('PERSONAS'))->toBe($this->ruc->id);

    // Al crear también.
    $this->post(route('admin.tipos-documento.store'), [
        'codigo' => 'DNI', 'nombre' => 'DNI', 'modulos' => [$this->personas->id], 'predeterminado' => [$this->personas->id],
    ])->assertSessionHasNoErrors();

    $dni = TipoDocumento::where('codigo', 'DNI')->sole();
    expect(TipoDocumento::predeterminadoPara('PERSONAS'))->toBe($dni->id)
        ->and($this->personas->tiposDocumento()->wherePivot('es_predeterminado', true)->count())->toBe(1);
});

test('la base no admite dos predeterminados en el mismo módulo', function () {
    expect(fn () => DB::table('tipo_documento_modulo')
        ->where('modulo_sistema_id', $this->personas->id)->where('tipo_documento_id', $this->ruc->id)
        ->update(['es_predeterminado' => true]))
        ->toThrow(QueryException::class);
});

test('quitarle el predeterminado deja al módulo sin predeterminado, con aviso', function () {
    $this->get(route('admin.tipos-documento.edit', $this->ci))
        ->assertSee('Al guardar, Personas quedará sin tipo de documento predeterminado.');

    editarTipo($this->ci, ['modulos' => [$this->personas->id]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('aviso', fn (string $aviso) => str_contains($aviso, 'Sin tipo de documento predeterminado en: Personas'));

    expect(TipoDocumento::predeterminadoPara('PERSONAS'))->toBeNull()
        ->and(habilitacion($this->ci))->toBe([$this->personas->id => false]);
});

test('solo puede ser predeterminado de un módulo donde esté habilitado', function () {
    editarTipo($this->ruc, ['modulos' => [], 'predeterminado' => [$this->personas->id]])
        ->assertSessionHasErrors(['predeterminado' => 'Solo puede ser el predeterminado de un módulo en el que esté habilitado.']);

    expect(habilitacion($this->ruc))->toBe([$this->personas->id => false]);
});

test('no se puede habilitar en un módulo que no usa tipos de documento', function () {
    $usuarios = ModuloSistema::where('codigo', 'USUARIOS')->sole();

    editarTipo($this->ruc, ['modulos' => [$usuarios->id]])->assertSessionHasErrors('modulos.0');
    $this->post(route('admin.tipos-documento.store'), ['codigo' => 'DNI', 'nombre' => 'DNI', 'modulos' => ['x']])
        ->assertSessionHasErrors('modulos.0');
});

test('un tipo inactivo no puede ser predeterminado', function () {
    editarTipo($this->ruc, ['estado_id' => estadoId('INACTIVO'), 'modulos' => [$this->personas->id], 'predeterminado' => [$this->personas->id]])
        ->assertSessionHasErrors('predeterminado');

    expect(TipoDocumento::predeterminadoPara('PERSONAS'))->toBe($this->ci->id);
});

test('desactivar el predeterminado conserva la marca pero no se preelige hasta reactivarlo', function () {
    $this->patch(route('admin.tipos-documento.desactivar', $this->ci))->assertRedirect();

    expect(habilitacion($this->ci->fresh()))->toBe([$this->personas->id => true])
        ->and(TipoDocumento::predeterminadoPara('PERSONAS'))->toBeNull();

    $this->ci->fresh()->update(['estado_id' => estadoId('ACTIVO')]);
    expect(TipoDocumento::predeterminadoPara('PERSONAS'))->toBe($this->ci->id);
});

test('destildar un módulo no rompe las personas que ya usan ese tipo de documento', function () {
    $empresa = Persona::factory()->create([
        'tipo_persona' => 'JURIDICA', 'razon_social' => 'Laboratorio Central S.A.', 'apellidos' => null, 'nombres' => null,
        'tipo_documento_id' => $this->ruc->id, 'nro_documento' => '80012345-6',
    ]);

    editarTipo($this->ruc, ['modulos' => []])->assertSessionHasNoErrors();

    // La persona sigue igual, se lista y se puede editar conservando su tipo.
    expect($empresa->fresh()->tipo_documento_id)->toBe($this->ruc->id)
        ->and(TipoDocumento::whereKey($this->ruc->id)->exists())->toBeTrue();
    $this->get(route('admin.personas.index'))->assertOk()->assertSee('Laboratorio Central S.A.');
    $this->get(route('admin.personas.edit', $empresa))->assertOk()->assertSee('RUC');
    $this->put(route('admin.personas.update', $empresa), [
        'tipo_persona' => 'JURIDICA', 'tipo_documento_id' => $this->ruc->id, 'nro_documento' => '80012345-6',
        'razon_social' => 'Laboratorio Central S.A. (nuevo nombre)', 'email' => 'lab@example.com',
        'telefono' => '021 000 000', 'direccion' => 'Asunción', 'estado_id' => estadoId('ACTIVO'),
    ])->assertSessionHasNoErrors();
    expect($empresa->fresh())->razon_social->toBe('Laboratorio Central S.A. (nuevo nombre)')->tipo_documento_id->toBe($this->ruc->id);

    // Lo que cambia: ya no se ofrece para personas nuevas.
    $this->post(route('admin.personas.store'), [
        'tipo_persona' => 'JURIDICA', 'tipo_documento_id' => $this->ruc->id, 'nro_documento' => '80099999-1', 'razon_social' => 'Otra S.A.',
    ])->assertSessionHasErrors('tipo_documento_id');
});

test('el listado muestra en qué módulos está habilitado y dónde es predeterminado', function () {
    $this->get(route('admin.tipos-documento.index'))->assertOk()
        ->assertSeeInOrder(['CI', 'Personas (predeterminado)', 'RUC', 'Personas']);

    editarTipo($this->ruc, ['modulos' => []]);
    editarTipo($this->ci, ['modulos' => [$this->personas->id]]);

    $html = $this->get(route('admin.tipos-documento.index'))->getContent();
    expect($html)->not->toContain('(predeterminado)')
        ->and(substr_count($html, '>—<') + substr_count($html, "—\n"))->toBeGreaterThan(0);
});

test('tras un error de validación el formulario conserva lo tildado', function () {
    $this->from(route('admin.tipos-documento.create'))
        ->post(route('admin.tipos-documento.store'), ['codigo' => '', 'nombre' => 'DNI', 'modulos' => [$this->personas->id], 'predeterminado' => [$this->personas->id]])
        ->assertSessionHasErrors('codigo');

    $html = $this->get(route('admin.tipos-documento.create'))->getContent();
    expect($html)
        ->toMatch('/name="modulos\[\]" value="'.$this->personas->id.'"[^>]*\schecked/')
        ->toMatch('/name="predeterminado\[\]" value="'.$this->personas->id.'"[^>]*\schecked/');
});
