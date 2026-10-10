<?php

use App\Models\Especialidad;
use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\User;
use Database\Seeders\ModuloSistemaSeeder;

test('la tabla estados tiene los 27 estados, con código y nombre (los del flujo de atención con su nombre legible)', function () {
    expect(Estado::orderBy('id')->pluck('codigo')->all())->toBe([
        'ACTIVO', 'INACTIVO', 'BLOQUEADO', 'PENDIENTE', 'CONFIRMADO', 'ATENDIDO', 'CANCELADO', 'AUSENTE',
        'REALIZADO', 'VIGENTE', 'VENCIDO', 'SUSPENDIDO', 'MANTENIMIENTO', 'FACTURADO', 'ANULADO', 'HISTORICO',
        'GENERADO', 'PAGADO', 'PARCIAL', 'EMITIDO', 'APROBADO', 'RECHAZADO',
        'EN_CONSULTA', 'SALTADO', 'EN_PREPARACION', 'EN_CURSO', 'FINALIZADO',
    ])->and(Estado::whereColumn('codigo', '!=', 'nombre')->pluck('nombre', 'codigo')->all())->toBe([
        'EN_CONSULTA' => 'EN CONSULTA', 'EN_PREPARACION' => 'EN PREPARACIÓN', 'EN_CURSO' => 'EN CURSO',
    ]);
});

test('estado_modulo: ACTIVO (inicial) e INACTIVO; Usuarios además BLOQUEADO; Turnos, Recetas y las consultas con su ciclo propio', function () {
    $this->seed(ModuloSistemaSeeder::class);

    foreach (ModuloSistema::with('estados')->get() as $modulo) {
        [$esperados, $inicial] = match ($modulo->codigo) {
            'USUARIOS' => [['ACTIVO', 'BLOQUEADO', 'INACTIVO'], 'ACTIVO'],
            'TURNOS' => [['ATENDIDO', 'AUSENTE', 'CANCELADO', 'CONFIRMADO', 'EN_CONSULTA', 'PENDIENTE', 'SALTADO'], 'PENDIENTE'],
            // Las consultas usan los estados de HISTORIA_CLINICA (la historia en sí no tiene estado).
            'HISTORIA_CLINICA' => [['ANULADO', 'EN_CURSO', 'EN_PREPARACION', 'FINALIZADO'], 'EN_PREPARACION'],
            // Recetas: borrador (PENDIENTE, inicial), emitida y anulada.
            'RECETAS' => [['ANULADO', 'EMITIDO', 'PENDIENTE'], 'PENDIENTE'],
            default => [['ACTIVO', 'INACTIVO'], 'ACTIVO'],
        };

        expect($modulo->estados->pluck('codigo')->sort()->values()->all())->toBe($esperados)
            ->and($modulo->estados->where('pivot.es_inicial', true)->pluck('codigo')->all())->toBe([$inicial]);
    }

    expect(ModuloSistema::pluck('codigo')->all())->toContain('PERSONAS', 'USUARIOS', 'PERFILES_ACCESO', 'MODULOS_SISTEMA', 'GEOGRAFIA');
});

test('correr el seeder de nuevo no duplica estado_modulo', function () {
    $this->seed(ModuloSistemaSeeder::class);
    $antes = DB::table('estado_modulo')->count();

    $this->seed(ModuloSistemaSeeder::class);

    expect(DB::table('estado_modulo')->count())->toBe($antes);
});

test('un registro nuevo toma el estado inicial de su módulo', function () {
    $this->seed(ModuloSistemaSeeder::class);

    expect(Especialidad::create(['nombre' => 'Cardiología'])->estado->codigo)->toBe('ACTIVO');
});

test('solo se puede asignar un estado habilitado para el módulo', function () {
    $this->actingAs(User::factory()->administrador()->create());
    $especialidad = Especialidad::create(['nombre' => 'Cardiología']);
    $usuario = User::factory()->create();

    // BLOQUEADO existe, pero solo está habilitado para Usuarios.
    $this->put(route('admin.especialidades.update', $especialidad), ['nombre' => 'Cardiología', 'estado_id' => estadoId('BLOQUEADO')])
        ->assertSessionHasErrors('estado_id');
    $this->put(route('admin.usuarios.update', $usuario), ['perfil_acceso_id' => $usuario->perfil_acceso_id ?? PerfilAcceso::first()->id, 'estado_id' => estadoId('BLOQUEADO')])
        ->assertSessionHasNoErrors();

    expect($especialidad->fresh()->estado->codigo)->toBe('ACTIVO')
        ->and($usuario->fresh()->estado->codigo)->toBe('BLOQUEADO');
});

test('los errores de validación del estado salen en castellano', function () {
    $this->actingAs(User::factory()->administrador()->create());
    $especialidad = Especialidad::create(['nombre' => 'Cardiología']);

    $this->put(route('admin.especialidades.update', $especialidad), ['nombre' => 'Cardiología'])
        ->assertSessionHasErrors(['estado_id' => 'El campo estado es obligatorio.']);
});

test('el badge muestra el nombre del estado', function () {
    $this->actingAs(User::factory()->administrador()->create());
    Estado::where('codigo', 'INACTIVO')->update(['nombre' => 'Dado de baja']);
    Especialidad::create(['nombre' => 'Cardiología', 'estado_id' => estadoId('INACTIVO')]);

    $this->get(route('admin.especialidades.index'))->assertSee('Dado de baja');
});

test('un usuario con el perfil de acceso INACTIVO no puede entrar', function () {
    $perfil = PerfilAcceso::create(['nombre' => 'Recepción']);
    $usuario = User::factory()->create(['perfil_acceso_id' => $perfil->id]);
    $perfil->desactivar();

    $this->post('/login', ['email' => $usuario->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Su perfil de acceso está inactivo. Contacte al administrador.']);
    $this->assertGuest();
});

test('el perfil Administrador no se puede desactivar', function () {
    $admin = User::factory()->administrador()->create();
    $this->actingAs($admin);
    $perfil = $admin->perfilAcceso;

    $this->patch(route('admin.perfiles-acceso.desactivar', $perfil))
        ->assertSessionHas('error', 'El perfil Administrador no se puede desactivar.');
    $this->get(route('admin.perfiles-acceso.index'))->assertDontSee(route('admin.perfiles-acceso.desactivar', $perfil));
    $this->get(route('admin.perfiles-acceso.edit', $perfil))->assertDontSee('name="estado_id"', false);

    expect($perfil->fresh()->estado->codigo)->toBe('ACTIVO');
});

test('al crear usuarios solo se ofrecen perfiles activos', function () {
    $this->actingAs(User::factory()->administrador()->create());
    Persona::factory()->create(); // para que el formulario de alta se muestre
    $inactivo = PerfilAcceso::create(['nombre' => 'Perfil viejo']);
    $inactivo->desactivar();

    $this->get(route('admin.usuarios.create'))->assertOk()->assertDontSee('Perfil viejo');
    $this->post(route('admin.usuarios.store'), ['persona_id' => Persona::latest('id')->first()->id, 'perfil_acceso_id' => $inactivo->id])
        ->assertSessionHasErrors('perfil_acceso_id');
});

test('un módulo INACTIVO (estado_id) no da permisos', function () {
    $admin = User::factory()->administrador()->create();
    ModuloSistema::where('codigo', 'PERSONAS')->update(['estado_id' => estadoId('INACTIVO')]);

    expect($admin->fresh()->tienePermiso('PERSONAS', 'VER'))->toBeFalse()
        ->and($admin->fresh()->tienePermiso('SUCURSALES', 'VER'))->toBeTrue();
});
