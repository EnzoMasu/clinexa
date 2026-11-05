<?php

use App\Enums\AccionAuditoria;
use App\Http\Controllers\Admin\ProveedorController;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Support\Ruc;

/*
 * Alta de Proveedor para quien no ve Personas (p. ej. Compras y tesorería): búsqueda solo por número
 * exacto, alta rápida de persona en el popup y mensaje genérico al guardar. Datos inventados.
 */

beforeEach(function () {
    $this->compras = User::factory()->conPermisos(['PROVEEDORES' => ['VER', 'CREAR']])->create();
    $this->actingAs($this->compras);
    TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
    ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI']);
});

function proveedorAltaRapidaBuscar(string $q)
{
    return test()->getJson(route('admin.proveedores.personas-disponibles', ['q' => $q]));
}

/** Datos válidos de una persona física inventada, como los manda el formulario de Personas. */
function proveedorAltaRapidaDatos(array $cambios = []): array
{
    return [
        'tipo_persona' => 'FISICA',
        'tipo_documento_id' => TipoDocumento::where('codigo', 'CI')->value('id'),
        'nro_documento' => '7654321',
        'apellidos' => 'Proveedora',
        'nombres' => 'Inventada',
        'fecha_nacimiento' => '15/03/1990',
        'email' => 'inventada@ejemplo.test',
        'telefono' => '0981 111 222',
        'direccion' => 'Calle Falsa 123',
        ...$cambios,
    ];
}

describe('búsqueda sin PERSONAS,VER', function () {
    test('no busca por nombre ni por parte del número', function () {
        Persona::factory()->create(['apellidos' => 'Benítez', 'nombres' => 'Ana', 'nro_documento' => '4567890']);

        proveedorAltaRapidaBuscar('Ben')->assertOk()->assertExactJson([]);
        proveedorAltaRapidaBuscar('Ana')->assertOk()->assertExactJson([]);
        proveedorAltaRapidaBuscar('4567')->assertOk()->assertExactJson([]);
    });

    test('con el número exacto devuelve solo id y "Apellidos, Nombres — documento"', function () {
        $persona = Persona::factory()->create(['apellidos' => 'Benítez', 'nombres' => 'Ana', 'nro_documento' => '4567890']);

        proveedorAltaRapidaBuscar(' 4567890 ')->assertOk()
            ->assertExactJson([['id' => $persona->id, 'texto' => 'Benítez, Ana — CI 4567890']]);
    });

    test('encuentra el RUC con o sin dígito verificador', function () {
        $ruc = Ruc::conDigito('80012345');
        $empresa = Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'tipo_documento_id' => null, 'nro_documento' => null,
            'apellidos' => null, 'nombres' => null, 'razon_social' => 'Insumos Inventados SA', 'ruc' => $ruc]);

        proveedorAltaRapidaBuscar('80012345')->assertJsonPath('0.id', $empresa->id);
        proveedorAltaRapidaBuscar($ruc)->assertJsonPath('0.id', $empresa->id);
    });

    test('no devuelve a quien ya es proveedor ni a una persona inactiva', function () {
        $yaProveedor = Persona::factory()->create(['nro_documento' => '1112223']);
        Proveedor::create(['persona_id' => $yaProveedor->id]);
        Persona::factory()->inactiva()->create(['nro_documento' => '3334445']);

        proveedorAltaRapidaBuscar('1112223')->assertExactJson([]);
        proveedorAltaRapidaBuscar('3334445')->assertExactJson([]);
    });

    test('tiene un límite de pedidos por minuto', function () {
        foreach (range(1, 30) as $i) {
            proveedorAltaRapidaBuscar('99999'.$i)->assertOk();
        }

        proveedorAltaRapidaBuscar('1234567')->assertStatus(429);
    });
});

test('con PERSONAS,VER se sigue buscando por nombre', function () {
    $this->actingAs(User::factory()->conPermisos(['PROVEEDORES' => ['CREAR'], 'PERSONAS' => ['VER']])->create());
    $persona = Persona::factory()->create(['apellidos' => 'Benítez', 'nombres' => 'Ana']);

    proveedorAltaRapidaBuscar('Ben')->assertJsonPath('0.id', $persona->id);
});

describe('guardar el proveedor', function () {
    test('una persona que no buscó da un error genérico, exista o no', function () {
        $disponible = Persona::factory()->create();
        $inactiva = Persona::factory()->inactiva()->create();
        $yaProveedor = Persona::factory()->create();
        Proveedor::create(['persona_id' => $yaProveedor->id]);

        foreach ([$disponible->id, $inactiva->id, $yaProveedor->id, 999999] as $id) {
            $this->post(route('admin.proveedores.store'), ['persona_id' => $id])
                ->assertSessionHasErrors(['persona_id' => ProveedorController::PERSONA_NO_DISPONIBLE]);
        }
        expect(Proveedor::where('persona_id', $disponible->id)->exists())->toBeFalse();
    });

    test('la encontrada por número exacto se puede elegir', function () {
        $persona = Persona::factory()->create(['nro_documento' => '5556667']);
        proveedorAltaRapidaBuscar('5556667')->assertJsonPath('0.id', $persona->id);

        $this->post(route('admin.proveedores.store'), ['persona_id' => $persona->id])->assertSessionHasNoErrors();
        expect(Proveedor::where('persona_id', $persona->id)->exists())->toBeTrue();
    });

    test('tras un error, el formulario no muestra el nombre de una persona que no buscó', function () {
        $ajena = Persona::factory()->create(['apellidos' => 'Ajenísima', 'nombres' => 'Paciente']);

        $this->from(route('admin.proveedores.create'))
            ->post(route('admin.proveedores.store'), ['persona_id' => $ajena->id])
            ->assertRedirect(route('admin.proveedores.create'));

        $this->get(route('admin.proveedores.create'))->assertOk()->assertDontSee('Ajenísima');
    });

    test('el formulario ofrece buscar por número y el alta rápida', function () {
        $this->get(route('admin.proveedores.create'))->assertOk()
            // La pista de la búsqueda por número va como placeholder de la caja (como en main), sin fila aparte.
            ->assertSee('placeholder="Escriba el número completo de CI, pasaporte o RUC (el RUC, con o sin dígito verificador). No se busca por nombre."', false)
            ->assertSee('proveedores\\/personas-rapida', false)
            ->assertSee('Nueva persona');
    });
});

describe('alta rápida de persona', function () {
    test('crea la persona, auditada con el usuario, y queda elegible para el proveedor', function () {
        $respuesta = $this->postJson(route('admin.proveedores.personas-rapida'), proveedorAltaRapidaDatos())->assertCreated();

        $persona = Persona::where('nro_documento', '7654321')->sole();
        $respuesta->assertExactJson(['id' => $persona->id, 'texto' => 'Proveedora, Inventada — CI 7654321']);
        expect($persona->fecha_nacimiento->format('Y-m-d'))->toBe('1990-03-15');

        $log = LogAuditoria::where('tabla_afectada', 'personas')->where('registro_afectado_id', (string) $persona->id)->sole();
        expect($log)->accion->toBe(AccionAuditoria::CREAR)->usuario_id->toBe($this->compras->id);

        $this->post(route('admin.proveedores.store'), ['persona_id' => $persona->id])->assertSessionHasNoErrors();
        expect(Proveedor::where('persona_id', $persona->id)->exists())->toBeTrue();
    });

    test('empresa con RUC', function () {
        $ruc = Ruc::conDigito('80054321');

        $this->postJson(route('admin.proveedores.personas-rapida'), [
            'tipo_persona' => 'JURIDICA', 'ruc' => $ruc, 'razon_social' => 'Limpieza Inventada SRL',
            'email' => 'limpieza@ejemplo.test', 'telefono' => '021 000 000', 'direccion' => 'Ruta 1 km 5',
        ])->assertCreated()->assertJsonPath('texto', "Limpieza Inventada SRL — RUC {$ruc}");
    });

    test('mismas validaciones que Personas: obligatorios, RUC inválido y documento repetido', function () {
        Persona::factory()->create(['nro_documento' => '7654321']);

        $this->postJson(route('admin.proveedores.personas-rapida'), proveedorAltaRapidaDatos(['email' => '', 'ruc' => '123']))
            ->assertStatus(422)->assertJsonValidationErrors(['nro_documento', 'email', 'ruc']);

        $this->postJson(route('admin.proveedores.personas-rapida'), ['tipo_persona' => 'JURIDICA', 'razon_social' => 'Sin RUC SA',
            'email' => 'sinruc@ejemplo.test', 'telefono' => '021 000 000', 'direccion' => 'Calle 1'])
            ->assertStatus(422)->assertJsonValidationErrors(['ruc']);

        expect(Persona::where('apellidos', 'Proveedora')->exists())->toBeFalse();
    });

    test('no da acceso a listar ni a editar Personas', function () {
        $persona = Persona::factory()->create();

        $this->get(route('admin.personas.index'))->assertForbidden();
        $this->get(route('admin.personas.edit', $persona))->assertForbidden();
        $this->post(route('admin.personas.store'), proveedorAltaRapidaDatos())->assertForbidden();
    });

    test('sin PROVEEDORES,CREAR no se puede usar', function () {
        $this->actingAs(User::factory()->conPermisos(['PROVEEDORES' => ['VER']])->create());

        $this->postJson(route('admin.proveedores.personas-rapida'), proveedorAltaRapidaDatos())->assertForbidden();
        proveedorAltaRapidaBuscar('7654321')->assertForbidden();
    });
});
