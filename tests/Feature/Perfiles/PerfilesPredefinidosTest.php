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

function permisosDelPredefinido(string $codigo): array
{
    return PerfilAcceso::where('codigo', $codigo)->sole()->clavesPermisos();
}

test('el seeder crea los 10 perfiles con su código, su nombre y su matriz', function () {
    (new PerfilesPredefinidosSeeder)->run();

    expect(PerfilAcceso::where('predefinido', true)->orderBy('codigo')->pluck('nombre', 'codigo')->all())
        ->toEqual(collect(PerfilesPredefinidos::NOMBRES)->sortKeys()->all());

    foreach (PerfilesPredefinidos::MATRIZ as $codigo => $matriz) {
        $esperado = collect($matriz)->flatMap(fn ($acciones, $modulo) => array_map(fn ($accion) => "{$modulo}:{$accion}", $acciones))->all();
        expect(permisosDelPredefinido($codigo))->toEqualCanonicalizing($esperado);
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

test('adopta el perfil Recepcionista existente como RECEPCION, sin cambiarle los permisos', function () {
    $recepcionista = PerfilAcceso::create(['nombre' => 'Recepcionista', 'descripcion' => 'El de siempre']);
    $recepcionista->permisos()->attach(Permiso::firstOrCreate(['modulo_sistema_id' => ModuloSistema::where('codigo', 'ORIGENES_TURNO')->sole()->id, 'accion' => 'EDITAR'])->id);
    $usuario = User::factory()->conPerfiles([$recepcionista])->create();
    $otro = PerfilAcceso::create(['nombre' => 'Secretaria Turno Tarde']);

    $plan = PerfilesPredefinidos::plan();
    expect($plan['RECEPCION']['accion'])->toBe('adoptar')->and($plan['RECEPCION']['perfil']->is($recepcionista))->toBeTrue()
        ->and($plan['GERENCIA']['accion'])->toBe('crear');
    expect(PerfilesPredefinidos::diferencia($recepcionista, 'RECEPCION'))
        ->sobran->toBe(['ORIGENES_TURNO: EDITAR'])
        ->faltan->toContain('TURNOS: VER', 'PACIENTES: CREAR');

    (new PerfilesPredefinidosSeeder)->run();

    $recepcionista->refresh();
    expect($recepcionista)->codigo->toBe('RECEPCION')->predefinido->toBeTrue()->nombre->toBe('Recepción')->descripcion->toBe('El de siempre')
        ->and($recepcionista->clavesPermisos())->toBe(['ORIGENES_TURNO:EDITAR'])
        ->and($usuario->fresh()->perfiles->modelKeys())->toBe([$recepcionista->id])
        ->and(PerfilAcceso::where('codigo', 'RECEPCION')->count())->toBe(1)
        // El otro perfil existente no se toca.
        ->and($otro->fresh())->codigo->toBeNull()->predefinido->toBeFalse()->nombre->toBe('Secretaria Turno Tarde');
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
        expect(collect(permisosDelPredefinido($codigo))->filter(fn ($clave) => preg_match('/^(USUARIOS|PERFILES_ACCESO):/', $clave))->all())->toBe([], $codigo);
    }
});

test('lo clínico solo lo tienen Médico, Enfermería (preparación) y Supervisión médica (lectura)', function () {
    (new PerfilesPredefinidosSeeder)->run();
    $clinico = fn (string $codigo) => collect(permisosDelPredefinido($codigo))->filter(fn ($c) => preg_match('/^(HISTORIA_CLINICA|RECETAS|PREPARACION):/', $c))->sort()->values()->all();

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

    expect(permisosDelPredefinido('CAJA'))->toContain('CAJA_DIARIA:VER', 'CAJA_DIARIA:CREAR')
        ->and(permisosDelPredefinido('GERENCIA'))->toContain('CAJA_DIARIA:VER')->not->toContain('CAJA_DIARIA:CREAR')
        ->and(permisosDelPredefinido('MEDICO'))->not->toContain('CAJA_DIARIA:VER')
        // El Administrador lo recibe completo, como todo módulo nuevo.
        ->and(permisosDelPredefinido('ADMINISTRADOR'))->toContain(...array_map(fn ($a) => "CAJA_DIARIA:{$a}", Permiso::ACCIONES));
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
