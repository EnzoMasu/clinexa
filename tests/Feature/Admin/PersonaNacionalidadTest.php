<?php

use App\Models\Ciudad;
use App\Models\ModuloSistema;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use Database\Seeders\GeografiaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
    $this->seed(GeografiaSeeder::class);

    $this->ci = TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
    $this->ruc = TipoDocumento::firstOrCreate(['codigo' => 'RUC'], ['nombre' => 'RUC']);
    ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI', 'RUC']);

    $this->paraguay = Pais::where('nombre', 'Paraguay')->sole();
    $this->brasil = Pais::where('nombre', 'Brasil')->sole();
});

function fisicaConNacionalidad(array $cambios = []): array
{
    return [
        'tipo_persona' => 'FISICA',
        'tipo_documento_id' => test()->ci->id,
        'nro_documento' => '7654321',
        'apellidos' => 'Souza',
        'nombres' => 'João',
        'fecha_nacimiento' => '1988-02-01',
        'email' => 'joao@example.com',
        'telefono' => '0981 000 000',
        'direccion' => 'Concepción',
        ...$cambios,
    ];
}

test('el formulario tiene un select de nacionalidad con los países, Paraguay elegido al crear', function () {
    $html = $this->get(route('admin.personas.create'))->assertOk()
        ->assertSee('Nacionalidad')
        ->assertSee('name="pais_nacionalidad_id"', false)
        ->assertDontSee('name="nacionalidad"', false)
        ->getContent();

    expect($html)->toMatch('/<select id="pais_nacionalidad_id"[\s\S]*?<option value="'.$this->paraguay->id.'"\s+selected>Paraguay<\/option>/')
        // Brasil solo aparece en la nacionalidad: el selector de ciudad lista únicamente países con departamentos.
        ->and(substr_count($html, '>Brasil</option>'))->toBe(1);
});

test('la nacionalidad es independiente de la ciudad: vive en Concepción y es brasileña', function () {
    $concepcion = Ciudad::where('nombre', 'Concepción')->sole();

    $this->post(route('admin.personas.store'), fisicaConNacionalidad([
        'pais_nacionalidad_id' => $this->brasil->id,
        'ciudad_id' => $concepcion->id,
    ]))->assertSessionHasNoErrors();

    $persona = Persona::where('nro_documento', '7654321')->sole();
    expect($persona->paisNacionalidad->nombre)->toBe('Brasil')
        ->and($persona->ciudad->nombre)->toBe('Concepción');

    $this->get(route('admin.personas.edit', $persona))
        ->assertSee('<option value="'.$this->brasil->id.'" selected>Brasil</option>', false);
});

test('la nacionalidad es opcional', function () {
    $this->post(route('admin.personas.store'), fisicaConNacionalidad(['pais_nacionalidad_id' => '']))->assertSessionHasNoErrors();

    expect(Persona::where('nro_documento', '7654321')->sole()->pais_nacionalidad_id)->toBeNull();
});

test('rechaza un país inexistente o inactivo, pero conserva el inactivo que ya tenía', function () {
    $this->post(route('admin.personas.store'), fisicaConNacionalidad(['pais_nacionalidad_id' => 999999]))
        ->assertSessionHasErrors('pais_nacionalidad_id');

    $persona = Persona::create(fisicaConNacionalidad(['pais_nacionalidad_id' => $this->brasil->id]));
    $this->brasil->desactivar();

    $this->post(route('admin.personas.store'), fisicaConNacionalidad(['nro_documento' => '1111111', 'pais_nacionalidad_id' => $this->brasil->id]))
        ->assertSessionHasErrors('pais_nacionalidad_id');

    // Al crear no se ofrece; al editar a quien ya lo tenía, sí, y se puede guardar.
    $this->get(route('admin.personas.create'))->assertDontSee('>Brasil</option>', false);
    $this->get(route('admin.personas.edit', $persona))->assertSee('>Brasil</option>', false);
    $this->put(route('admin.personas.update', $persona), fisicaConNacionalidad([
        'pais_nacionalidad_id' => $this->brasil->id, 'estado_id' => estadoId('ACTIVO'),
    ]))->assertSessionHasNoErrors();
});

test('una persona jurídica no tiene nacionalidad', function () {
    $persona = Persona::create(fisicaConNacionalidad(['pais_nacionalidad_id' => $this->brasil->id]));

    $persona->update(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Souza S.A.', 'tipo_documento_id' => $this->ruc->id]);

    expect($persona->fresh()->pais_nacionalidad_id)->toBeNull();
});

test('la migración pasa la nacionalidad de texto a país sin perder ninguna', function () {
    // SQLite reconstruye la tabla personas al cambiar columnas: las FK se verifican al final de la transacción.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $migracion = require database_path('migrations/2026_09_29_100003_nacionalidad_a_pais_en_personas.php');
    $personas = collect(['Paraguaya', 'paraguaya', ' PARAGUAYA ', 'Paraguayo', 'Brasil', null, ''])
        ->map(fn ($valor, $i) => [Persona::factory()->create(['nro_documento' => "900{$i}"])->id, $valor]);

    // Vuelta al esquema viejo, con los textos cargados.
    $migracion->down();
    foreach ($personas as [$id, $valor]) {
        DB::table('personas')->where('id', $id)->update(['nacionalidad' => $valor]);
    }

    $migracion->up();

    expect(Schema::hasColumn('personas', 'nacionalidad'))->toBeFalse();
    $resultado = $personas->map(fn ($p) => DB::table('personas')->where('id', $p[0])->value('pais_nacionalidad_id'));
    expect($resultado->map(fn ($id) => $id === null ? null : (int) $id)->all())
        ->toBe([$this->paraguay->id, $this->paraguay->id, $this->paraguay->id, $this->paraguay->id, $this->brasil->id, null, null]);
});

test('la migración se detiene sin borrar nada si una nacionalidad no corresponde a ningún país', function () {
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $migracion = require database_path('migrations/2026_09_29_100003_nacionalidad_a_pais_en_personas.php');
    $persona = Persona::factory()->create();

    $migracion->down();
    DB::table('personas')->where('id', $persona->id)->update(['nacionalidad' => 'Marciana']);

    expect(fn () => $migracion->up())->toThrow(RuntimeException::class, '"Marciana" (1 persona/s)');
});
