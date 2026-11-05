<?php

use App\Models\Ciudad;
use App\Models\Departamento;
use App\Models\LogAuditoria;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\GeografiaSeeder;

beforeEach(function () {
    $this->actingAs(User::factory()->conPermisos(['GEOGRAFIA' => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'], 'PERSONAS' => ['VER', 'CREAR', 'EDITAR']])->create());
    $this->seed(GeografiaSeeder::class);

    $this->paraguay = Pais::where('codigo', 'PY')->sole();
    $this->concepcion = Departamento::where('nombre', 'Concepción')->sole();
    $this->horqueta = Ciudad::where('nombre', 'Horqueta')->sole();
});

test('Gerencia puede ver, crear, editar y desactivar geografía; nada se borra', function () {
    expect(PerfilesPredefinidos::MATRIZ[PerfilesPredefinidos::GERENCIA]['GEOGRAFIA'])->toBe(['VER', 'CREAR', 'EDITAR', 'DESACTIVAR']);

    foreach (['paises', 'departamentos', 'ciudades'] as $ruta) {
        expect(Route::has("admin.{$ruta}.destroy"))->toBeFalse();
    }
});

test('se crea un país, un departamento y una ciudad, y quedan en la auditoría', function () {
    $this->post(route('admin.paises.store'), ['nombre' => 'Atlantis', 'codigo' => 'XA'])->assertSessionHasNoErrors();
    $atlantis = Pais::where('codigo', 'XA')->sole();
    $this->post(route('admin.departamentos.store'), ['nombre' => 'Norte', 'pais_id' => $atlantis->id])->assertSessionHasNoErrors();
    $norte = Departamento::where('nombre', 'Norte')->sole();
    $this->post(route('admin.ciudades.store'), ['nombre' => 'Puerto Sol', 'departamento_id' => $norte->id])->assertSessionHasNoErrors();

    expect($norte->pais->is($atlantis))->toBeTrue()
        ->and(Ciudad::where('nombre', 'Puerto Sol')->sole()->departamento->is($norte))->toBeTrue()
        ->and(LogAuditoria::whereIn('tabla_afectada', ['paises', 'departamentos', 'ciudades'])->where('accion', 'CREAR')->count())->toBeGreaterThanOrEqual(3);
});

test('renombrar se ve en la ficha existente y se audita con el valor anterior y el nuevo', function () {
    $persona = Persona::factory()->create(['ciudad_id' => $this->horqueta->id]);

    $this->put(route('admin.ciudades.update', $this->horqueta), [
        'nombre' => 'Horqueta Nueva', 'departamento_id' => $this->concepcion->id, 'estado_id' => $this->horqueta->estado_id,
    ])->assertSessionHasNoErrors();

    $this->get(route('admin.personas.edit', $persona))->assertOk()->assertSee('Horqueta Nueva')->assertDontSee('>Horqueta<', false);

    $log = LogAuditoria::where('tabla_afectada', 'ciudades')->where('registro_afectado_id', $this->horqueta->id)->latest('id')->firstOrFail();
    expect($log->usuario_id)->not->toBeNull()
        ->and($log->fecha_hora)->not->toBeNull()
        ->and($log->valor_anterior)->toMatchArray(['nombre' => 'Horqueta'])
        ->and($log->valor_nuevo)->toMatchArray(['nombre' => 'Horqueta Nueva']);
});

test('lo desactivado no se ofrece en los selectores nuevos, pero la ficha vieja lo conserva marcado', function () {
    $persona = Persona::factory()->create(['ciudad_id' => $this->horqueta->id, 'pais_nacionalidad_id' => $this->paraguay->id]);

    $this->patch(route('admin.ciudades.desactivar', $this->horqueta))->assertRedirect();

    // Selector nuevo (cascada): sin la ciudad.
    expect(collect($this->getJson(route('geografia.ciudades', ['departamento_id' => $this->concepcion->id]))->json())->pluck('nombre'))->not->toContain('Horqueta');
    $this->get(route('admin.personas.create'))->assertOk()->assertDontSee('Horqueta');

    // Ficha vieja: sigue ahí, como inactiva. La persona no pierde su ciudad.
    $this->get(route('admin.personas.edit', $persona))->assertOk()->assertSee('Horqueta (inactivo)');
    expect($persona->fresh()->ciudad_id)->toBe($this->horqueta->id);

    // Un país desactivado: fuera de la nacionalidad nueva, pero la persona vieja lo conserva.
    $atlantis = Pais::create(['nombre' => 'Atlantis', 'codigo' => 'XA']);
    $vieja = Persona::factory()->create(['pais_nacionalidad_id' => $atlantis->id]);
    $atlantis->desactivar();
    $this->get(route('admin.personas.create'))->assertDontSee('Atlantis');
    $this->get(route('admin.personas.edit', $vieja))->assertSee('Atlantis (inactivo)');
});

test('no se puede desactivar un país o departamento con hijos activos, y sí cuando ya no los tiene', function () {
    $this->patch(route('admin.paises.desactivar', $this->paraguay))->assertSessionHas('error');
    $this->patch(route('admin.departamentos.desactivar', $this->concepcion))
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'ciudades activas'));
    expect($this->paraguay->fresh()->estaActivo())->toBeTrue()->and($this->concepcion->fresh()->estaActivo())->toBeTrue();

    // También cambiando el estado desde la edición.
    $this->put(route('admin.departamentos.update', $this->concepcion), [
        'nombre' => 'Concepción', 'pais_id' => $this->paraguay->id, 'estado_id' => \App\Models\Estado::idDe('INACTIVO'),
    ])->assertSessionHasErrors('estado_id');
    expect($this->concepcion->fresh()->estaActivo())->toBeTrue();

    // Sin hijos activos se puede.
    $atlantis = Pais::create(['nombre' => 'Atlantis', 'codigo' => 'XA']);
    $norte = Departamento::create(['nombre' => 'Norte', 'pais_id' => $atlantis->id]);
    $sol = Ciudad::create(['nombre' => 'Sol', 'departamento_id' => $norte->id]);
    $this->patch(route('admin.paises.desactivar', $atlantis))->assertSessionHas('error');
    $this->patch(route('admin.ciudades.desactivar', $sol))->assertSessionHasNoErrors();
    $this->patch(route('admin.departamentos.desactivar', $norte))->assertSessionHasNoErrors();
    $this->patch(route('admin.paises.desactivar', $atlantis))->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect($atlantis->fresh()->estaActivo())->toBeFalse();
});

test('volver a correr el seeder no pisa nombres ni estados cambiados, ni duplica', function () {
    $this->paraguay->update(['nombre' => 'República del Paraguay']);
    $this->concepcion->update(['nombre' => 'Concepción (renombrado)']);
    $this->horqueta->update(['nombre' => 'Horqueta (renombrada)']);
    $encarnacion = Ciudad::where('nombre', 'Encarnación')->sole();
    $encarnacion->desactivar();
    $antes = [Pais::count(), Departamento::count(), Ciudad::count()];

    $this->seed(GeografiaSeeder::class);

    expect([Pais::count(), Departamento::count(), Ciudad::count()])->toBe($antes)
        ->and($this->paraguay->fresh()->nombre)->toBe('República del Paraguay')
        ->and($this->concepcion->fresh()->nombre)->toBe('Concepción (renombrado)')
        ->and($this->horqueta->fresh()->nombre)->toBe('Horqueta (renombrada)')
        ->and($encarnacion->fresh()->estaActivo())->toBeFalse()
        ->and(Departamento::where('nombre', 'Concepción')->exists())->toBeFalse()
        ->and(Ciudad::where('nombre', 'Horqueta')->exists())->toBeFalse();
});

test('registros anteriores a la clave de origen se adoptan por nombre, sin duplicar', function () {
    Departamento::query()->update(['clave_origen' => null]);
    Ciudad::query()->update(['clave_origen' => null]);
    $antes = [Departamento::count(), Ciudad::count()];

    $this->seed(GeografiaSeeder::class);

    expect([Departamento::count(), Ciudad::count()])->toBe($antes)
        ->and(Departamento::whereNull('clave_origen')->count())->toBe(0)
        ->and(Ciudad::whereNull('clave_origen')->count())->toBe(0);
});

test('no se reactiva un hijo cuyo padre está inactivo, y el mensaje dice qué padre reactivar primero', function () {
    $inactivo = App\Models\Estado::idDe(App\Models\Estado::INACTIVO);
    $activo = App\Models\Estado::idDe(App\Models\Estado::ACTIVO);
    Ciudad::where('departamento_id', $this->concepcion->id)->update(['estado_id' => $inactivo]);
    $this->concepcion->update(['estado_id' => $inactivo]);
    $this->paraguay->departamentos()->activos()->update(['estado_id' => $inactivo]);
    $this->paraguay->update(['estado_id' => $inactivo]);

    // Departamento con país inactivo.
    $this->put(route('admin.departamentos.update', $this->concepcion), [
        'nombre' => 'Concepción', 'pais_id' => $this->paraguay->id, 'estado_id' => $activo,
    ])->assertSessionHasErrors(['estado_id' => 'No se puede reactivar Concepción: su país, Paraguay, está inactivo. Reactive primero el país.']);
    expect($this->concepcion->fresh()->estaActivo())->toBeFalse();

    // Se reactiva el país; ahora el departamento sí, y recién entonces la ciudad.
    $this->put(route('admin.paises.update', $this->paraguay), [
        'nombre' => 'Paraguay', 'codigo' => 'PY', 'estado_id' => $activo,
    ])->assertSessionHasNoErrors();

    // Ciudad con departamento inactivo (Concepción sigue inactivo).
    $this->put(route('admin.ciudades.update', $this->horqueta), [
        'nombre' => 'Horqueta', 'departamento_id' => $this->concepcion->id, 'estado_id' => $activo,
    ])->assertSessionHasErrors(['estado_id' => 'No se puede reactivar Horqueta: su departamento, Concepción, está inactivo. Reactive primero el departamento.']);
    expect($this->horqueta->fresh()->estaActivo())->toBeFalse();

    $this->put(route('admin.departamentos.update', $this->concepcion), [
        'nombre' => 'Concepción', 'pais_id' => $this->paraguay->id, 'estado_id' => $activo,
    ])->assertSessionHasNoErrors();
    $this->put(route('admin.ciudades.update', $this->horqueta), [
        'nombre' => 'Horqueta', 'departamento_id' => $this->concepcion->id, 'estado_id' => $activo,
    ])->assertSessionHasNoErrors();

    expect($this->horqueta->fresh()->estaActivo())->toBeTrue();
});

test('la migración de Asunción pone la clave al "Asunción (Capital)" sin clave, sin renombrar, y el seeder no duplica', function () {
    $capital = Departamento::where('nombre', 'Asunción (Distrito Capital)')->sole();
    $capital->forceFill(['nombre' => 'Asunción (Capital)', 'clave_origen' => null])->saveQuietly();
    $asuncion = Ciudad::where('departamento_id', $capital->id)->where('nombre', 'Asunción')->sole();
    $asuncion->forceFill(['clave_origen' => null])->saveQuietly();
    $otras = Departamento::where('id', '!=', $capital->id)->orderBy('id')->get(['id', 'nombre', 'estado_id', 'clave_origen'])->toArray();
    $antes = [Departamento::count(), Ciudad::count()];

    $migracion = require database_path('migrations/2026_11_06_110001_geografia_clave_asuncion.php');
    $migracion->up();
    $migracion->up(); // repetible

    expect($capital->fresh()->clave_origen)->toBe('PY:Asunción (Distrito Capital)')
        ->and($capital->fresh()->nombre)->toBe('Asunción (Capital)')
        ->and($capital->fresh()->estado_id)->toBe($capital->estado_id)
        ->and($asuncion->fresh()->clave_origen)->toBe('PY:Asunción (Distrito Capital)/Asunción')
        ->and(Departamento::where('id', '!=', $capital->id)->orderBy('id')->get(['id', 'nombre', 'estado_id', 'clave_origen'])->toArray())->toBe($otras);

    $this->seed(GeografiaSeeder::class);

    expect([Departamento::count(), Ciudad::count()])->toBe($antes)
        ->and($capital->fresh()->nombre)->toBe('Asunción (Capital)')
        ->and(Departamento::whereNull('clave_origen')->count())->toBe(0)
        ->and(Ciudad::whereNull('clave_origen')->count())->toBe(0);
});

test('la migración de Asunción no falla si no hay Paraguay, si ya tienen clave o si el departamento no existe', function () {
    $migracion = require database_path('migrations/2026_11_06_110001_geografia_clave_asuncion.php');
    $migracion->up(); // ya tienen clave

    Departamento::where('nombre', 'Asunción (Distrito Capital)')->update(['clave_origen' => null, 'nombre' => 'Otro nombre']);
    $migracion->up(); // no existe ninguna variante: no hace nada
    expect(Departamento::whereNull('clave_origen')->count())->toBe(1);

    Pais::where('codigo', 'PY')->update(['codigo' => 'XX']);
    $migracion->up(); // sin Paraguay
    expect(Departamento::whereNull('clave_origen')->count())->toBe(1);
});
