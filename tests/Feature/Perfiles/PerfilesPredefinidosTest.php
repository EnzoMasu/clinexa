<?php

/*
 * Perfiles predefinidos (App\Support\PerfilesPredefinidos): código estable, seeder idempotente que crea los
 * que faltan, adopta el existente y nunca pisa permisos; la matriz solo usa módulos y acciones reales, y
 * nadie salvo el Administrador escribe en Usuarios ni en Perfiles. Datos inventados.
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;
use Database\Seeders\PerfilesPredefinidosSeeder;

beforeEach(fn () => (new ModuloSistemaSeeder)->run());

function perfilesPredefinidosPermisos(string $codigo): array
{
    return PerfilAcceso::where('codigo', $codigo)->sole()->clavesPermisos();
}

test('el seeder crea los 10 perfiles con su código, su nombre y su matriz', function () {
    (new PerfilesPredefinidosSeeder)->run();

    expect(PerfilAcceso::where('predefinido', true)->orderBy('codigo')->pluck('nombre', 'codigo')->all())
        ->toEqual(collect(PerfilesPredefinidos::NOMBRES)->sortKeys()->all());

    foreach (PerfilesPredefinidos::MATRIZ as $codigo => $matriz) {
        $esperado = collect($matriz)->flatMap(fn ($acciones, $modulo) => array_map(fn ($accion) => "{$modulo}:{$accion}", $acciones))->all();
        expect(perfilesPredefinidosPermisos($codigo))->toEqualCanonicalizing($esperado);
    }
    expect(PerfilAcceso::where('codigo', 'ADMINISTRADOR')->sole()->permisos()->count())->toBe(ModuloSistema::count() * count(Permiso::ACCIONES));
});

test('es idempotente: correrlo de nuevo no crea duplicados ni cambia nada', function () {
    (new PerfilesPredefinidosSeeder)->run();
    $antes = PerfilAcceso::with('permisos')->orderBy('id')->get()->map(fn ($p) => [$p->id, $p->nombre, $p->permisos->modelKeys()]);

    (new PerfilesPredefinidosSeeder)->run();

    expect(PerfilAcceso::with('permisos')->orderBy('id')->get()->map(fn ($p) => [$p->id, $p->nombre, $p->permisos->modelKeys()]))->toEqual($antes);
});

test('no pisa lo que se cambió desde la pantalla: ni permisos, ni nombre, ni estado', function () {
    (new PerfilesPredefinidosSeeder)->run();
    $admin = User::factory()->administrador()->create();
    $medico = PerfilAcceso::where('codigo', 'MEDICO')->sole();
    $turnos = ModuloSistema::where('codigo', 'TURNOS')->sole();

    // Desde la pantalla: se renombra, se le quita TURNOS: EDITAR y se le suma TURNOS: CREAR.
    $matriz = $medico->permisos()->get()->groupBy('modulo_sistema_id')->map(fn ($p) => $p->pluck('accion')->all())->all();
    $matriz[$turnos->id] = ['VER', 'CREAR'];
    $this->actingAs($admin)->put(route('admin.perfiles-acceso.update', $medico), [
        'nombre' => 'Médicos de planta', 'descripcion' => 'x', 'estado_id' => Estado::idDe(Estado::INACTIVO), 'permisos' => $matriz,
    ])->assertSessionHasNoErrors();
    $despues = $medico->fresh()->clavesPermisos();

    (new PerfilesPredefinidosSeeder)->run();

    $medico->refresh();
    expect($medico->nombre)->toBe('Médicos de planta')
        ->and($medico->estaActivo())->toBeFalse()
        ->and($medico->clavesPermisos())->toEqualCanonicalizing($despues)->toContain('TURNOS:CREAR')->not->toContain('TURNOS:EDITAR')
        ->and(PerfilAcceso::where('nombre', 'Médico')->exists())->toBeFalse(); // no creó otro "Médico"
});

/** Un perfil sin código con estos permisos, como ['ORIGENES_TURNO' => ['EDITAR']] (como los de la base real). */
function perfilesPredefinidosExistente(string $nombre, array $permisos): PerfilAcceso
{
    return Database\Factories\UserFactory::perfilConPermisos($permisos, $nombre);
}

test('adopta Recepcionista como RECEPCION: lo renombra y SOLO le suma lo que le falta de la matriz', function () {
    $recepcionista = perfilesPredefinidosExistente('Recepcionista', ['ORIGENES_TURNO' => ['VER', 'CREAR', 'EDITAR'], 'SUCURSALES' => ['VER'], 'TURNOS' => ['VER']]);
    $recepcionista->update(['descripcion' => 'El de siempre']);
    $usuario = User::factory()->conPerfiles([$recepcionista])->create();
    $antes = $recepcionista->clavesPermisos();

    $plan = PerfilesPredefinidos::plan();
    expect($plan['RECEPCION']['accion'])->toBe('adoptar')->and($plan['RECEPCION']['perfil']->is($recepcionista))->toBeTrue()
        ->and($plan['GERENCIA']['accion'])->toBe('crear');
    expect(PerfilesPredefinidos::diferencia($recepcionista, 'RECEPCION'))
        ->sobran->toBe(['ORIGENES_TURNO: CREAR', 'ORIGENES_TURNO: EDITAR', 'SUCURSALES: VER'])
        ->faltan->toContain('RESPONSABLES_PAGO: VER', 'DISPONIBILIDAD: DESACTIVAR');

    (new PerfilesPredefinidosSeeder)->run();

    $recepcionista->refresh();
    $esperado = array_unique([...$antes, ...collect(PerfilesPredefinidos::MATRIZ['RECEPCION'])->flatMap(fn ($acciones, $modulo) => array_map(fn ($a) => "{$modulo}:{$a}", $acciones))->all()]);
    expect($recepcionista)->codigo->toBe('RECEPCION')->predefinido->toBeTrue()->nombre->toBe('Recepción')->descripcion->toBe('El de siempre')
        // No se le quitó nada (orígenes de turno: crear y editar; sucursales: ver) y se le sumó lo que faltaba.
        ->and($recepcionista->clavesPermisos())->toEqualCanonicalizing($esperado)
        ->and(PerfilesPredefinidos::diferencia($recepcionista, 'RECEPCION')['faltan'])->toBe([])
        ->and($usuario->fresh()->perfiles->modelKeys())->toBe([$recepcionista->id])
        ->and(PerfilAcceso::where('codigo', 'RECEPCION')->count())->toBe(1);
});

test('adopta Enfermera como ENFERMERIA: la renombra y sus permisos quedan tal cual (con Turnos: ver)', function () {
    $enfermera = perfilesPredefinidosExistente('Enfermera', ['PREPARACION' => ['VER', 'CREAR', 'EDITAR'], 'TURNOS' => ['VER']]);
    $antes = $enfermera->clavesPermisos();

    (new PerfilesPredefinidosSeeder)->run();

    expect($enfermera->fresh())->codigo->toBe('ENFERMERIA')->nombre->toBe('Enfermería')
        ->and($enfermera->fresh()->clavesPermisos())->toEqualCanonicalizing($antes)->toContain('TURNOS:VER')
        ->and(PerfilAcceso::where('codigo', 'ENFERMERIA')->count())->toBe(1)
        ->and(PerfilAcceso::where('nombre', 'like', 'Enfermer%')->count())->toBe(1);
});

test('Secretaria Turno Tarde y Recepcionista - Enfermera no se tocan', function () {
    $secretaria = perfilesPredefinidosExistente('Secretaria Turno Tarde', ['PERSONAS' => ['VER']]);
    $mixto = perfilesPredefinidosExistente('Recepcionista - Enfermera', ['PREPARACION' => ['VER']]);
    perfilesPredefinidosExistente('Recepcionista', []);
    perfilesPredefinidosExistente('Enfermera', []);

    (new PerfilesPredefinidosSeeder)->run();

    foreach ([[$secretaria, 'Secretaria Turno Tarde', ['PERSONAS:VER']], [$mixto, 'Recepcionista - Enfermera', ['PREPARACION:VER']]] as [$perfil, $nombre, $permisos]) {
        expect($perfil->fresh())->codigo->toBeNull()->predefinido->toBeFalse()->nombre->toBe($nombre)
            ->and($perfil->fresh()->clavesPermisos())->toBe($permisos);
    }
});

test('la adopción se hace una sola vez: un perfil adoptado y después cambiado a mano no se repite ni se pisa', function () {
    $recepcionista = perfilesPredefinidosExistente('Recepcionista', ['ORIGENES_TURNO' => ['EDITAR']]);
    $enfermera = perfilesPredefinidosExistente('Enfermera', ['PREPARACION' => ['VER']]);
    (new PerfilesPredefinidosSeeder)->run();
    expect(PerfilesPredefinidos::plan())->each(fn ($paso) => $paso->accion->toBe('existe'));

    // Desde la pantalla: se renombran, se les quitan permisos (también los que sumó la adopción) y se desactiva uno.
    $this->actingAs(User::factory()->administrador()->create());
    $turnos = ModuloSistema::where('codigo', 'TURNOS')->sole();
    $this->put(route('admin.perfiles-acceso.update', $recepcionista), ['nombre' => 'Mostrador', 'estado_id' => Estado::idDe(Estado::ACTIVO), 'permisos' => [$turnos->id => ['VER']]])
        ->assertSessionHasNoErrors();
    $this->put(route('admin.perfiles-acceso.update', $enfermera), ['nombre' => 'Enfermería de guardia', 'estado_id' => Estado::idDe(Estado::INACTIVO), 'permisos' => []])
        ->assertSessionHasNoErrors();
    // Y alguien crea a mano otro perfil con el nombre viejo: tampoco se adopta (ya hay uno con el código).
    $nuevaRecepcionista = PerfilAcceso::create(['nombre' => 'Recepcionista']);
    $foto = fn () => PerfilAcceso::with('permisos')->orderBy('id')->get()->map(fn ($p) => [$p->id, $p->codigo, $p->nombre, $p->estado_id, $p->permisos->modelKeys()])->all();
    $antes = $foto();

    (new PerfilesPredefinidosSeeder)->run();
    (new PerfilesPredefinidosSeeder)->run();

    expect($foto())->toEqual($antes)
        ->and($recepcionista->fresh()->clavesPermisos())->toBe(['TURNOS:VER'])
        ->and($nuevaRecepcionista->fresh()->codigo)->toBeNull();
});

test('adopta el Administrador por nombre si todavía no tiene código', function () {
    $viejo = PerfilAcceso::create(['nombre' => 'Administrador']);

    PerfilAcceso::asegurarAdministrador();

    expect($viejo->fresh())->codigo->toBe('ADMINISTRADOR')->predefinido->toBeTrue()
        ->and(PerfilAcceso::count())->toBe(1);
});

test('la matriz usa módulos reales y solo acciones con efecto (ninguna EXPORTAR)', function () {
    $modulos = ModuloSistema::pluck('codigo')->all();
    foreach (PerfilesPredefinidos::MATRIZ as $codigo => $matriz) {
        expect(array_keys(PerfilesPredefinidos::NOMBRES))->toContain($codigo);
        foreach ($matriz as $modulo => $acciones) {
            expect($modulos)->toContain($modulo)
                ->and(array_diff($acciones, Permiso::accionesDe($modulo)))->toBe([], "{$codigo} / {$modulo}")
                ->and($acciones)->not->toContain('EXPORTAR');
        }
    }
    // TURNOS no tiene baja (un turno se cancela); MODULOS_SISTEMA no tiene pantalla.
    expect(collect(PerfilesPredefinidos::MATRIZ)->pluck('TURNOS')->filter()->flatten()->all())->not->toContain('DESACTIVAR')
        ->and(collect(PerfilesPredefinidos::MATRIZ)->pluck('MODULOS_SISTEMA')->filter()->all())->toBe([]);
});

test('ningún perfil salvo el Administrador escribe en Usuarios ni en Perfiles de acceso', function () {
    (new PerfilesPredefinidosSeeder)->run();

    foreach (array_keys(PerfilesPredefinidos::MATRIZ) as $codigo) {
        expect(collect(perfilesPredefinidosPermisos($codigo))->filter(fn ($clave) => preg_match('/^(USUARIOS|PERFILES_ACCESO):/', $clave))->all())->toBe([], $codigo);
    }
});

test('lo clínico solo lo tienen Médico, Enfermería (preparación) y Supervisión médica (lectura)', function () {
    (new PerfilesPredefinidosSeeder)->run();
    $clinico = fn (string $codigo) => collect(perfilesPredefinidosPermisos($codigo))->filter(fn ($c) => preg_match('/^(HISTORIA_CLINICA|RECETAS|PREPARACION):/', $c))->sort()->values()->all();

    expect($clinico('ENFERMERIA'))->toBe(['PREPARACION:CREAR', 'PREPARACION:EDITAR', 'PREPARACION:VER'])
        ->and($clinico('SUPERVISION_MEDICA'))->toBe(['HISTORIA_CLINICA:VER', 'RECETAS:VER']);
    foreach (['GERENCIA', 'RECEPCION', 'CAJA', 'FACTURACION', 'COMPRAS_TESORERIA', 'AUDITOR'] as $codigo) {
        expect($clinico($codigo))->toBe([], $codigo);
    }
});

test('un módulo nuevo se suma a los perfiles indicados una vez, desde su migración (sumarModulo)', function () {
    (new PerfilesPredefinidosSeeder)->run();
    $nuevo = ModuloSistema::create(['codigo' => 'CAJA_DIARIA', 'nombre' => 'Caja diaria']);

    PerfilesPredefinidos::sumarModulo('CAJA_DIARIA', ['CAJA' => ['VER', 'CREAR'], 'GERENCIA' => ['VER']]);

    expect(perfilesPredefinidosPermisos('CAJA'))->toContain('CAJA_DIARIA:VER', 'CAJA_DIARIA:CREAR')
        ->and(perfilesPredefinidosPermisos('GERENCIA'))->toContain('CAJA_DIARIA:VER')->not->toContain('CAJA_DIARIA:CREAR')
        ->and(perfilesPredefinidosPermisos('MEDICO'))->not->toContain('CAJA_DIARIA:VER')
        // El Administrador lo recibe completo, como todo módulo nuevo.
        ->and(perfilesPredefinidosPermisos('ADMINISTRADOR'))->toContain(...array_map(fn ($a) => "CAJA_DIARIA:{$a}", Permiso::ACCIONES));
    expect($nuevo->exists)->toBeTrue();
});

test('el perfil Administrador no se puede desactivar, ni desde la pantalla ni desde el formulario', function () {
    (new PerfilesPredefinidosSeeder)->run();
    $admin = User::factory()->administrador()->create();
    $perfil = PerfilAcceso::where('codigo', 'ADMINISTRADOR')->sole();
    $this->actingAs($admin);

    $this->patch(route('admin.perfiles-acceso.desactivar', $perfil))->assertSessionHas('error', 'El perfil Administrador no se puede desactivar.');
    $this->put(route('admin.perfiles-acceso.update', $perfil), ['nombre' => 'Administrador', 'estado_id' => Estado::idDe(Estado::INACTIVO)]);

    expect($perfil->fresh()->estaActivo())->toBeTrue();
});
