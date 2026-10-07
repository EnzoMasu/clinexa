<?php

use App\Models\Ciudad;
use App\Models\Departamento;
use App\Models\Pais;
use App\Models\User;
use Database\Seeders\GeografiaSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
    $this->seed(GeografiaSeeder::class); // los 249 países, 18 departamentos y 263 ciudades reales

    $this->paraguay = Pais::where('codigo', 'PY')->sole();
    $this->concepcion = Departamento::where('nombre', 'Concepción')->sole();
});

test('los 249 países tienen su código ISO de 2 letras', function () {
    expect(Pais::count())->toBe(249)
        ->and(Pais::whereNull('codigo')->count())->toBe(0)
        ->and(Pais::pluck('codigo')->unique())->toHaveCount(249)
        ->and(Pais::where('nombre', 'Brasil')->value('codigo'))->toBe('BR');
});

describe('países', function () {
    test('el código se guarda en mayúsculas y tiene que ser de 2 letras, único', function () {
        $this->post(route('admin.paises.store'), ['nombre' => 'Atlantis', 'codigo' => 'xa'])->assertSessionHasNoErrors();
        expect(Pais::where('nombre', 'Atlantis')->value('codigo'))->toBe('XA');

        $this->post(route('admin.paises.store'), ['nombre' => 'Otro', 'codigo' => 'PY'])->assertSessionHasErrors('codigo');
        $this->post(route('admin.paises.store'), ['nombre' => 'Otro', 'codigo' => 'PRY'])
            ->assertSessionHasErrors(['codigo' => 'El código debe ser el ISO de 2 letras (por ejemplo, PY).']);
        $this->post(route('admin.paises.store'), ['nombre' => 'Paraguay', 'codigo' => 'XB'])->assertSessionHasErrors('nombre');
    });

    test('el listado muestra código y cantidad de departamentos, y busca por nombre o código', function () {
        $this->get(route('admin.paises.index', ['q' => 'parag']))->assertOk()
            ->assertSeeInOrder(['PY', 'Paraguay', '18'])->assertDontSee('Brasil');
        $this->get(route('admin.paises.index', ['q' => 'BR']))->assertSee('Brasil');
    });
});

describe('departamentos', function () {
    test('no se puede crear sin país, ni con uno inexistente o inactivo', function () {
        $this->post(route('admin.departamentos.store'), ['nombre' => 'Nuevo'])->assertSessionHasErrors('pais_id');
        $this->post(route('admin.departamentos.store'), ['nombre' => 'Nuevo', 'pais_id' => 999999])->assertSessionHasErrors('pais_id');

        $brasil = Pais::where('codigo', 'BR')->sole();
        $brasil->desactivar();
        $this->post(route('admin.departamentos.store'), ['nombre' => 'Mato Grosso', 'pais_id' => $brasil->id])->assertSessionHasErrors('pais_id');

        expect(Departamento::where('nombre', 'Nuevo')->exists())->toBeFalse();
    });

    test('el nombre se repite entre países, no dentro del mismo', function () {
        $argentina = Pais::where('codigo', 'AR')->sole();

        $this->post(route('admin.departamentos.store'), ['nombre' => 'Concepción', 'pais_id' => $this->paraguay->id])
            ->assertSessionHasErrors(['nombre' => 'Ese país ya tiene un departamento con ese nombre.']);
        $this->post(route('admin.departamentos.store'), ['nombre' => 'Concepción', 'pais_id' => $argentina->id])->assertSessionHasNoErrors();
    });

    test('el formulario ofrece todos los países activos, con Paraguay elegido', function () {
        $this->get(route('admin.departamentos.create'))->assertOk()
            ->assertSee('<option value="'.$this->paraguay->id.'" selected>Paraguay</option>', false)
            ->assertSee('>Brasil</option>', false);
    });

    test('el listado muestra a qué país pertenece cada uno y busca por departamento o país', function () {
        $this->get(route('admin.departamentos.index', ['q' => 'concep']))->assertOk()
            ->assertSeeInOrder(['Concepción', 'Paraguay', '14'])->assertDontSee('Itapúa');
        $this->get(route('admin.departamentos.index', ['q' => 'paraguay']))->assertSee('Itapúa');
    });
});

describe('ciudades', function () {
    test('no se puede crear sin departamento, ni con uno inexistente o inactivo', function () {
        $this->post(route('admin.ciudades.store'), ['nombre' => 'Nueva'])->assertSessionHasErrors('departamento_id');
        $this->post(route('admin.ciudades.store'), ['nombre' => 'Nueva', 'departamento_id' => 999999])->assertSessionHasErrors('departamento_id');

        $this->concepcion->desactivar();
        $this->post(route('admin.ciudades.store'), ['nombre' => 'Nueva', 'departamento_id' => $this->concepcion->id])
            ->assertSessionHasErrors('departamento_id');

        expect(Ciudad::where('nombre', 'Nueva')->exists())->toBeFalse();
    });

    test('se crea dentro del departamento elegido; el nombre se repite entre departamentos', function () {
        $this->post(route('admin.ciudades.store'), ['nombre' => 'Horqueta', 'departamento_id' => $this->concepcion->id])
            ->assertSessionHasErrors(['nombre' => 'Ese departamento ya tiene una ciudad con ese nombre.']);

        $this->post(route('admin.ciudades.store'), ['nombre' => 'Nueva Colonia', 'departamento_id' => $this->concepcion->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.ciudades.index'));
        expect(Ciudad::where('nombre', 'Nueva Colonia')->sole()->departamento->nombre)->toBe('Concepción');
    });

    test('el formulario usa el selector en cascada País → Departamento, que envía departamento_id', function () {
        $html = $this->get(route('admin.ciudades.create'))->assertOk()->getContent();

        expect($html)->toContain('x-data="selectorCiudad(')
            ->toMatch('/<select id="departamento_id"[^>]*name="departamento_id"[^>]*required/')
            ->not->toContain('name="ciudad_id"')
            ->toContain('<option value="'.$this->paraguay->id.'" selected>Paraguay</option>');

        // Al editar, país y departamento vienen elegidos.
        $horqueta = Ciudad::where('nombre', 'Horqueta')->sole();
        $this->get(route('admin.ciudades.edit', $horqueta))->assertOk()
            ->assertSee('<option value="'.$this->concepcion->id.'" selected>Concepción</option>', false);
    });

    test('el listado muestra departamento y país, y busca por ciudad, departamento o país', function () {
        $this->get(route('admin.ciudades.index', ['q' => 'horqueta']))->assertOk()
            ->assertSeeInOrder(['Horqueta', 'Concepción', 'Paraguay'])->assertDontSee('Encarnación');
        $this->get(route('admin.ciudades.index', ['q' => 'itapúa']))->assertSee('Encarnación')->assertDontSee('Horqueta');
        $this->get(route('admin.ciudades.index', ['q' => 'paraguay']))->assertOk()->assertSee('Mostrando');
    });

    test('una ciudad desactivada deja de ofrecerse en el selector de Personas y Sucursales', function () {
        $horqueta = Ciudad::where('nombre', 'Horqueta')->sole();
        $this->patch(route('admin.ciudades.desactivar', $horqueta))->assertRedirect(route('admin.ciudades.index'));

        expect($horqueta->fresh()->estado->codigo)->toBe('INACTIVO')
            ->and(collect($this->getJson(route('geografia.ciudades', ['departamento_id' => $this->concepcion->id]))->json())->pluck('nombre'))
            ->not->toContain('Horqueta');
    });
});

test('permisos: las tres pantallas usan el módulo GEOGRAFIA', function () {
    $this->actingAs(User::factory()->conPermisos([])->create());
    foreach (['paises', 'departamentos', 'ciudades'] as $ruta) {
        $this->get(route("admin.{$ruta}.index"))->assertForbidden();
    }

    $this->actingAs(User::factory()->conPermisos(['GEOGRAFIA' => ['VER']])->create());
    foreach (['paises', 'departamentos', 'ciudades'] as $ruta) {
        $this->get(route("admin.{$ruta}.index"))->assertOk()->assertDontSee(route("admin.{$ruta}.create"));
        $this->get(route("admin.{$ruta}.create"))->assertForbidden();
    }
    $this->post(route('admin.paises.store'), ['nombre' => 'X', 'codigo' => 'XX'])->assertForbidden();
});

test('con el volumen real, el listado y la búsqueda paginan y no hacen una consulta por fila', function (string $ruta, ?string $busqueda) {
    DB::enableQueryLog();
    $inicio = microtime(true);

    $respuesta = $this->get(route("admin.{$ruta}.index", array_filter(['q' => $busqueda])))->assertOk();

    $segundos = microtime(true) - $inicio;
    $consultas = count(DB::getQueryLog());

    // 20 por página (no los 249 / 263), con el paginador.
    expect(substr_count($respuesta->getContent(), '/edit"'))->toBeLessThanOrEqual(20)
        // Cantidad de consultas fija (precarga de relaciones), no proporcional a las filas.
        ->and($consultas)->toBeLessThan(25)
        ->and($segundos)->toBeLessThan(2.0);
})->with([
    ['paises', null], ['paises', 'an'],
    ['departamentos', null],
    ['ciudades', null], ['ciudades', 'san'], ['ciudades', 'paraguay'],
]);
