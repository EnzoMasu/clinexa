<?php

use App\Models\Ciudad;
use App\Models\Departamento;
use App\Models\ModuloSistema;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\Sucursal;
use App\Models\TipoDocumento;
use App\Models\User;
use Database\Seeders\DatosRealesClinicaSeeder;
use Database\Seeders\GeografiaSeeder;
use Database\Seeders\ModuloSistemaSeeder;

function ciudad(string $departamento, string $nombre): Ciudad
{
    return Ciudad::whereHas('departamento', fn ($query) => $query->where('nombre', $departamento))->where('nombre', $nombre)->sole();
}

beforeEach(function () {
    $this->seed(GeografiaSeeder::class);
});

test('carga los 249 países de ISO 3166-1, todos activos', function () {
    expect(Pais::count())->toBe(249)
        ->and(Pais::pluck('nombre')->all())->toContain('Paraguay', 'Brasil', 'Argentina', 'Bolivia', 'Estados Unidos', 'España', 'Costa de Marfil')
        ->and(Pais::where('estado_id', '!=', estadoId('ACTIVO'))->count())->toBe(0)
        // Solo Paraguay tiene departamentos.
        ->and(Pais::whereHas('departamentos')->pluck('nombre')->all())->toBe(['Paraguay']);
});

test('carga Paraguay, sus 17 departamentos más el Distrito Capital, y sus 263 distritos', function () {
    $paraguay = Pais::where('nombre', 'Paraguay')->sole();

    expect($paraguay->nombre)->toBe('Paraguay')
        ->and($paraguay->departamentos()->count())->toBe(18)
        ->and($paraguay->departamentos()->pluck('nombre')->all())->toContain('Concepción', 'Central', 'Boquerón', 'Ñeembucú', 'Asunción (Distrito Capital)')
        ->and(Ciudad::count())->toBe(263)
        ->and(Departamento::where('nombre', 'Concepción')->sole()->ciudades()->count())->toBe(14)
        ->and(ciudad('Asunción (Distrito Capital)', 'Asunción'))->not->toBeNull()
        ->and(Ciudad::where('estado_id', '!=', estadoId('ACTIVO'))->count())->toBe(0);
});

test('el seeder de geografía se puede volver a correr sin duplicar', function () {
    $this->seed(GeografiaSeeder::class);

    expect(Pais::count())->toBe(249)->and(Departamento::count())->toBe(18)->and(Ciudad::count())->toBe(263);
});

test('cada departamento tiene la cantidad de distritos del INE (Censo 2022)', function () {
    $esperado = [
        'Asunción (Distrito Capital)' => 1, 'Concepción' => 14, 'San Pedro' => 22, 'Cordillera' => 20, 'Guairá' => 18,
        'Caaguazú' => 22, 'Caazapá' => 11, 'Itapúa' => 30, 'Misiones' => 10, 'Paraguarí' => 18, 'Alto Paraná' => 22,
        'Central' => 19, 'Ñeembucú' => 16, 'Amambay' => 6, 'Canindeyú' => 16, 'Presidente Hayes' => 10,
        'Boquerón' => 4, 'Alto Paraguay' => 4,
    ];

    $real = Departamento::withCount('ciudades')->pluck('ciudades_count', 'nombre')->all();
    ksort($esperado);
    ksort($real);

    expect($real)->toBe($esperado)
        ->and(ciudad('Central', 'San Lorenzo'))->not->toBeNull()
        ->and(ciudad('Alto Paraná', 'Ciudad del Este'))->not->toBeNull()
        ->and(ciudad('Itapúa', 'Encarnación'))->not->toBeNull()
        ->and(ciudad('Guairá', 'General Eugenio A. Garay'))->not->toBeNull();
});

test('volver a correr el seeder es solo aditivo: no toca ciudades existentes ni las agregadas a mano', function () {
    $belen = ciudad('Concepción', 'Belén');
    $belen->desactivar();
    $aMano = Ciudad::create(['departamento_id' => Departamento::where('nombre', 'Central')->sole()->id, 'nombre' => 'Compañía agregada a mano']);

    $this->seed(GeografiaSeeder::class);

    expect($belen->fresh()->estaActivo())->toBeFalse()
        ->and(Ciudad::whereKey($aMano->id)->exists())->toBeTrue()
        ->and(Ciudad::count())->toBe(264);
});

test('la sucursal real queda en la ciudad de Concepción', function () {
    $this->seed([ModuloSistemaSeeder::class, DatosRealesClinicaSeeder::class]);

    expect(Sucursal::where('nombre', 'Plenitud Mujer')->sole()->ciudad->nombre)->toBe('Concepción');
});

test('los endpoints del selector exigen estar logueado', function () {
    $this->getJson(route('geografia.departamentos', ['pais_id' => Pais::idParaguay()]))->assertUnauthorized();
});

test('devuelve los departamentos de un país y las ciudades de un departamento, solo activos', function () {
    $this->actingAs(User::factory()->create());
    $concepcion = Departamento::where('nombre', 'Concepción')->sole();
    ciudad('Concepción', 'Belén')->desactivar();

    $departamentos = $this->getJson(route('geografia.departamentos', ['pais_id' => Pais::idParaguay()]))->assertOk()->json();
    expect($departamentos)->toHaveCount(18)->and($departamentos[0])->toHaveKeys(['id', 'nombre']);

    $ciudades = collect($this->getJson(route('geografia.ciudades', ['departamento_id' => $concepcion->id]))->assertOk()->json());
    expect($ciudades)->toHaveCount(13)
        ->and($ciudades->pluck('nombre'))->toContain('Horqueta')->not->toContain('Belén');

    expect($this->getJson(route('geografia.ciudades', ['departamento_id' => Departamento::where('nombre', 'Central')->sole()->id]))->assertOk()->json())
        ->toHaveCount(19);
    $this->getJson(route('geografia.ciudades'))->assertUnprocessable();
});

describe('ciudad en personas y sucursales', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->administrador()->create());
        ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI']);
        $this->persona = [
            'tipo_persona' => 'FISICA', 'tipo_documento_id' => TipoDocumento::where('codigo', 'CI')->value('id'),
            'nro_documento' => '1234567', 'apellidos' => 'Duarte', 'nombres' => 'Carmen', 'fecha_nacimiento' => '1990-03-15',
            'email' => 'carmen@example.com', 'telefono' => '0981 000 000', 'direccion' => 'Calle 1',
        ];
        $this->sucursal = ['nombre' => 'Centro', 'direccion' => 'Calle 1', 'telefono' => '0331 000 000'];
    });

    test('se guarda la ciudad elegida', function () {
        $horqueta = ciudad('Concepción', 'Horqueta');

        $this->post(route('admin.personas.store'), [...$this->persona, 'ciudad_id' => $horqueta->id])->assertSessionHasNoErrors();
        $this->post(route('admin.sucursales.store'), [...$this->sucursal, 'ciudad_id' => $horqueta->id])->assertSessionHasNoErrors();

        expect(Persona::where('nro_documento', '1234567')->sole()->ciudad->nombre)->toBe('Horqueta')
            ->and(Sucursal::where('nombre', 'Centro')->sole()->ciudad_id)->toBe($horqueta->id);
    });

    test('la ciudad es opcional y la dirección de texto libre se mantiene', function () {
        $this->post(route('admin.personas.store'), [...$this->persona, 'ciudad_id' => ''])->assertSessionHasNoErrors();

        expect(Persona::where('nro_documento', '1234567')->sole())->ciudad_id->toBeNull()->direccion->toBe('Calle 1');
    });

    test('no se puede elegir una ciudad inactiva o inexistente', function () {
        $belen = ciudad('Concepción', 'Belén');
        $belen->desactivar();

        $this->post(route('admin.sucursales.store'), [...$this->sucursal, 'ciudad_id' => $belen->id])->assertSessionHasErrors('ciudad_id');
        $this->post(route('admin.sucursales.store'), [...$this->sucursal, 'ciudad_id' => 999999])->assertSessionHasErrors('ciudad_id');
    });

    test('al editar se conserva la ciudad aunque después se haya desactivado', function () {
        $belen = ciudad('Concepción', 'Belén');
        $sucursal = Sucursal::create([...$this->sucursal, 'ciudad_id' => $belen->id]);
        $belen->desactivar();

        $this->get(route('admin.sucursales.edit', $sucursal))->assertOk()->assertSee('Belén');
        $this->put(route('admin.sucursales.update', $sucursal), [...$this->sucursal, 'ciudad_id' => $belen->id, 'estado_id' => estadoId('ACTIVO')])
            ->assertSessionHasNoErrors();
    });

    test('el formulario nuevo trae el selector con Paraguay elegido y los departamentos cargados', function () {
        $html = $this->get(route('admin.personas.create'))->assertOk()->getContent();

        expect($html)->toContain('x-data="selectorCiudad(')
            ->toMatch('/<option value="'.Pais::idParaguay().'"\s+selected>Paraguay<\/option>/')
            ->toContain('>Alto Paraguay</option>')
            ->toContain('name="ciudad_id"');
    });

    test('al editar, país, departamento y ciudad vienen preseleccionados', function () {
        $horqueta = ciudad('Concepción', 'Horqueta');
        $sucursal = Sucursal::create([...$this->sucursal, 'ciudad_id' => $horqueta->id]);

        $html = $this->get(route('admin.sucursales.edit', $sucursal))->assertOk()->getContent();

        expect($html)->toMatch('/<option value="'.$horqueta->departamento_id.'"\s+selected>Concepción<\/option>/')
            ->toMatch('/<option value="'.$horqueta->id.'"\s+selected>Horqueta<\/option>/')
            ->toContain('>Yby Yaú</option>');
    });
});
