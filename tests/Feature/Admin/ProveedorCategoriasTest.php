<?php

use App\Models\CategoriaProveedor;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\User;
use Database\Seeders\DatosRealesClinicaSeeder;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
});

describe('migración de propietarios de equipo a proveedores', function () {
    beforeEach(function () {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $this->copiar = require database_path('migrations/2026_10_02_100002_fusionar_propietarios_equipo_en_proveedores.php');
        $this->eliminar = require database_path('migrations/2026_10_02_100003_eliminar_rol_propietario_equipo.php');
        $this->eliminar->down(); // vuelve a existir la tabla propietarios_equipo (vacía)

        $this->propietario = fn (Persona $persona, ?string $banco, string $estado) => DB::table('propietarios_equipo')->insert([
            'persona_id' => $persona->id, 'datos_bancarios' => $banco, 'estado_id' => estadoId($estado),
            'created_at' => '2026-09-30 10:00:00', 'updated_at' => '2026-09-30 10:00:00',
        ]);
    });

    test('persona que ya era proveedor: recibe los datos bancarios y "Equipos médicos", conserva su estado y sus datos', function () {
        $persona = Persona::factory()->create();
        $proveedor = Proveedor::create(['persona_id' => $persona->id, 'condiciones_comerciales' => 'Contado']);
        $proveedor->update(['estado_id' => estadoId('INACTIVO')]);
        ($this->propietario)($persona, 'Banco Continental, CA 123', 'ACTIVO');

        $this->copiar->up();
        $this->eliminar->up();

        $proveedor = Proveedor::where('persona_id', $persona->id)->sole();
        expect($proveedor)
            ->datos_bancarios->toBe('Banco Continental, CA 123')
            ->condiciones_comerciales->toBe('Contado')
            ->estado->codigo->toBe('INACTIVO')
            ->and($proveedor->categorias->pluck('nombre')->all())->toBe(['Equipos médicos'])
            ->and(Schema::hasTable('propietarios_equipo'))->toBeFalse();
    });

    test('persona que no era proveedor: se crea con el estado y los datos bancarios del propietario', function () {
        $persona = Persona::factory()->create();
        ($this->propietario)($persona, 'Itaú, CC 999', 'INACTIVO');

        $this->copiar->up();
        $this->eliminar->up();

        $proveedor = Proveedor::where('persona_id', $persona->id)->sole();
        expect($proveedor)
            ->datos_bancarios->toBe('Itaú, CC 999')
            ->condiciones_comerciales->toBeNull()
            ->estado->codigo->toBe('INACTIVO')
            ->and($proveedor->categorias->pluck('nombre')->all())->toBe(['Equipos médicos']);
    });

    test('no duplica la categoría ni pisa los datos bancarios que el proveedor ya tenía, y se puede volver a correr', function () {
        $persona = Persona::factory()->create();
        $equipos = CategoriaProveedor::create(['nombre' => 'Equipos médicos']);
        $proveedor = Proveedor::create(['persona_id' => $persona->id, 'datos_bancarios' => 'Banco A']);
        $proveedor->categorias()->attach($equipos->id);
        ($this->propietario)($persona, null, 'ACTIVO');

        $this->copiar->up();
        $this->copiar->up();

        expect($proveedor->fresh()->datos_bancarios)->toBe('Banco A')
            ->and(DB::table('proveedor_categoria')->count())->toBe(1)
            ->and(CategoriaProveedor::where('nombre', 'Equipos médicos')->count())->toBe(1)
            ->and(Proveedor::count())->toBe(1);
    });

    test('si los datos bancarios del proveedor y del propietario difieren, se detiene sin cambiar nada', function () {
        $persona = Persona::factory()->create();
        $proveedor = Proveedor::create(['persona_id' => $persona->id, 'datos_bancarios' => 'Banco A']);
        ($this->propietario)($persona, 'Banco B', 'ACTIVO');

        expect(fn () => $this->copiar->up())->toThrow(RuntimeException::class, 'Datos bancarios distintos');

        expect($proveedor->fresh()->datos_bancarios)->toBe('Banco A')
            ->and(DB::table('proveedor_categoria')->count())->toBe(0);
    });

    test('el borrado del rol viejo se niega si algún propietario no fue copiado', function () {
        ($this->propietario)(Persona::factory()->create(), null, 'ACTIVO');

        expect(fn () => $this->eliminar->up())->toThrow(RuntimeException::class, 'sin copiar a proveedores');
        expect(Schema::hasTable('propietarios_equipo'))->toBeTrue();
    });
});

describe('categorías del proveedor', function () {
    beforeEach(function () {
        $this->insumos = CategoriaProveedor::create(['nombre' => 'Insumos médicos']);
        $this->equipos = CategoriaProveedor::create(['nombre' => 'Equipos médicos']);
        $this->limpieza = CategoriaProveedor::create(['nombre' => 'Artículos de limpieza']);
    });

    test('se crea con varias categorías y datos bancarios', function () {
        $persona = Persona::factory()->create();

        $this->post(route('admin.proveedores.store'), [
            'persona_id' => $persona->id, 'datos_bancarios' => 'Banco Continental, CA 123',
            'con_categorias' => '1', 'categorias' => [$this->insumos->id, $this->equipos->id],
        ])->assertSessionHasNoErrors();

        $proveedor = Proveedor::where('persona_id', $persona->id)->sole();
        expect($proveedor->datos_bancarios)->toBe('Banco Continental, CA 123')
            ->and($proveedor->categorias->pluck('nombre')->sort()->values()->all())->toBe(['Equipos médicos', 'Insumos médicos']);
    });

    test('al editar se dejan exactamente las tildadas', function () {
        $proveedor = Proveedor::create(['persona_id' => Persona::factory()->create()->id]);
        $proveedor->categorias()->attach([$this->insumos->id, $this->equipos->id]);

        $this->put(route('admin.proveedores.update', $proveedor), [
            'estado_id' => estadoId('ACTIVO'), 'con_categorias' => '1', 'categorias' => [$this->limpieza->id],
        ])->assertSessionHasNoErrors();
        expect($proveedor->fresh()->categorias->pluck('nombre')->all())->toBe(['Artículos de limpieza']);

        // Ninguna tildada: queda sin categorías.
        $this->put(route('admin.proveedores.update', $proveedor), ['estado_id' => estadoId('ACTIVO'), 'con_categorias' => '1'])
            ->assertSessionHasNoErrors();
        expect($proveedor->fresh()->categorias)->toBeEmpty();
    });

    test('el formulario ofrece las activas (y las inactivas que ya tenía); una inexistente o inactiva nueva se rechaza', function () {
        $proveedor = Proveedor::create(['persona_id' => Persona::factory()->create()->id]);
        $proveedor->categorias()->attach($this->limpieza->id);
        $this->limpieza->desactivar();

        $this->get(route('admin.proveedores.create'))->assertOk()
            ->assertSee('Insumos médicos')->assertSee('Equipos médicos')->assertDontSee('Artículos de limpieza');
        $this->get(route('admin.proveedores.edit', $proveedor))->assertOk()
            ->assertSee('Artículos de limpieza')
            ->assertSee('name="categorias[]" value="'.$this->limpieza->id.'" checked', false);

        $this->post(route('admin.proveedores.store'), [
            'persona_id' => Persona::factory()->create()->id, 'con_categorias' => '1', 'categorias' => [$this->limpieza->id],
        ])->assertSessionHasErrors('categorias.0');
        $this->post(route('admin.proveedores.store'), [
            'persona_id' => Persona::factory()->create()->id, 'con_categorias' => '1', 'categorias' => [999999],
        ])->assertSessionHasErrors('categorias.0');

        // La que ya tenía se puede conservar.
        $this->put(route('admin.proveedores.update', $proveedor), [
            'estado_id' => estadoId('ACTIVO'), 'con_categorias' => '1', 'categorias' => [$this->limpieza->id],
        ])->assertSessionHasNoErrors();
    });

    test('el listado muestra las categorías de cada proveedor', function () {
        $proveedor = Proveedor::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Benítez', 'nombres' => 'Rosa'])->id]);
        $proveedor->categorias()->attach([$this->equipos->id, $this->insumos->id]);

        $this->get(route('admin.proveedores.index'))->assertOk()
            ->assertSeeInOrder(['Categorías', 'Benítez, Rosa', 'Equipos médicos, Insumos médicos']);
    });
});

test('el seeder de datos reales carga las 5 categorías base', function () {
    $this->seed([ModuloSistemaSeeder::class, DatosRealesClinicaSeeder::class]);

    expect(CategoriaProveedor::orderBy('nombre')->pluck('nombre')->all())->toBe([
        'Artículos de limpieza', 'Equipos médicos', 'Insumos de oficina', 'Insumos médicos', 'Servicios tercerizados',
    ]);
});

test('el rol PropietarioEquipo ya no aparece en ningún lado', function () {
    expect(ModuloSistema::where('codigo', 'PROPIETARIOS_EQUIPO')->exists())->toBeFalse()
        ->and(Schema::hasTable('propietarios_equipo'))->toBeFalse()
        ->and(class_exists('App\\Models\\PropietarioEquipo'))->toBeFalse()
        ->and(class_exists('App\\Http\\Controllers\\Admin\\PropietarioEquipoController'))->toBeFalse()
        ->and(method_exists(Persona::class, 'propietarioEquipo'))->toBeFalse()
        ->and(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($nombre) => str_contains($nombre, 'propietarios-equipo')))->toBeEmpty()
        ->and(is_dir(resource_path('views/admin/propietarios-equipo')))->toBeFalse();

    $this->get('/dashboard')->assertOk()->assertDontSee('Propietarios de equipo')->assertSee('Categorías de proveedor');

    // El Administrador tiene exactamente las 5 acciones de los módulos que quedan.
    expect(PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->sole()->permisos()->count())
        ->toBe(ModuloSistema::count() * count(Permiso::ACCIONES));
});
