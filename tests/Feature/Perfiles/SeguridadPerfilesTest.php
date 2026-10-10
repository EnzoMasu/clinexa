<?php

/*
 * Seguridad de la asignación de perfiles: nunca sin administrador (quitarle el perfil, desactivar o bloquear
 * al último usuario activo con ADMINISTRADOR, ni desactivar su persona), sin escalada de privilegios (asignar
 * un perfil o agregar a un perfil un permiso que quien lo hace no tiene). Pantallas (casillas, listados,
 * Matriz de permisos) y auditoría de la asignación. Datos inventados.
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\UltimoAdministrador;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use Database\Factories\UserFactory;
use Database\Seeders\ModulosSensiblesSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->administrador()->create();
    PerfilesPredefinidos::asegurar();
    (new ModulosSensiblesSeeder)->run();
    $this->recepcion = PerfilAcceso::where('codigo', 'RECEPCION')->sole();
});

describe('último administrador', function () {
    test('no se le quita el perfil Administrador (lo hace otro usuario con permiso de Usuarios)', function () {
        $gestor = User::factory()->conPermisos(['USUARIOS' => ['VER', 'EDITAR']])->create();
        $vacio = PerfilAcceso::create(['nombre' => 'Sin permisos']); // asignarlo no es escalar

        $this->actingAs($gestor)->from(route('admin.usuarios.edit', $this->admin))
            ->put(route('admin.usuarios.update', $this->admin), ['perfiles' => [$vacio->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHas('error', UltimoAdministrador::MENSAJE);

        expect($this->admin->fresh()->esAdministrador())->toBeTrue();
    });

    test('no se lo desactiva ni se lo bloquea, por la pantalla ni por el modelo', function () {
        $gestor = User::factory()->conPermisos(['USUARIOS' => ['VER', 'EDITAR', 'DESACTIVAR']])->create();
        $this->actingAs($gestor);

        $this->from(route('admin.usuarios.index'))->patch(route('admin.usuarios.desactivar', $this->admin))->assertSessionHas('error', UltimoAdministrador::MENSAJE);
        $this->put(route('admin.usuarios.update', $this->admin), ['perfiles' => $this->admin->perfiles->modelKeys(), 'estado_id' => Estado::idDe(Estado::BLOQUEADO)])
            ->assertSessionHas('error', UltimoAdministrador::MENSAJE);
        expect(fn () => $this->admin->fresh()->desactivar())->toThrow(UltimoAdministrador::class)
            ->and($this->admin->fresh()->estaActivo())->toBeTrue();
    });

    test('tampoco se desactiva su persona', function () {
        $this->actingAs($this->admin);

        expect(fn () => $this->admin->persona->fresh()->desactivar())->toThrow(UltimoAdministrador::class);
        $this->from(route('admin.personas.index'))->patch(route('admin.personas.desactivar', $this->admin->persona))
            ->assertSessionHas('error', UltimoAdministrador::MENSAJE);
        expect($this->admin->persona->fresh()->estaActivo())->toBeTrue();
    });

    test('con otro administrador activo, sí se puede', function () {
        $otro = User::factory()->administrador()->create();
        $this->actingAs($otro);

        $this->put(route('admin.usuarios.update', $this->admin), ['perfiles' => [$this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        expect($this->admin->fresh()->esAdministrador())->toBeFalse();

        // Y ahora el último es $otro: no se puede desactivar.
        expect(fn () => $otro->fresh()->desactivar())->toThrow(UltimoAdministrador::class);
    });

    test('un administrador bloqueado o con la persona inactiva no cuenta como activo', function () {
        $bloqueado = User::factory()->administrador()->create();
        \Illuminate\Support\Facades\DB::table('users')->where('id', $bloqueado->id)->update(['estado_id' => Estado::idDe(Estado::BLOQUEADO)]);

        expect($this->admin->fresh()->esUltimoAdministrador())->toBeTrue();
    });
});

describe('sin escalada de privilegios', function () {
    beforeEach(function () {
        // Gestiona usuarios y tiene los permisos de Recepción, pero no los de Médico.
        $this->gestor = User::factory()->conPerfiles([
            UserFactory::perfilConPermisos(['USUARIOS' => ['VER', 'CREAR', 'EDITAR']], 'Gestión de usuarios'),
            $this->recepcion,
        ])->create();
        $this->actingAs($this->gestor);
    });

    test('puede asignar un perfil cuyos permisos ya tiene todos', function () {
        $usuario = User::factory()->create();

        $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [$this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHasNoErrors();
        expect($usuario->fresh()->perfiles->modelKeys())->toBe([$this->recepcion->id]);
    });

    test('no puede asignar un perfil con permisos que no tiene: ni a otro, ni al crear; con su propio usuario tampoco (no edita sus perfiles)', function (string $perfil) {
        $ajeno = PerfilAcceso::where('codigo', $perfil)->sole();
        $usuario = User::factory()->create();
        $mensaje = "No puede asignar el perfil «{$ajeno->nombre}»: tiene permisos que usted no tiene.";

        $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [$ajeno->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHasErrors(['perfiles' => $mensaje]);
        $this->post(route('admin.usuarios.store'), ['persona_id' => Persona::factory()->create()->id, 'perfiles' => [$ajeno->id]])
            ->assertSessionHasErrors(['perfiles' => $mensaje]);
        // Saltarse la regla con su propio usuario: nadie cambia sus propios perfiles.
        $this->put(route('admin.usuarios.update', $this->gestor), ['perfiles' => [...$this->gestor->perfiles->modelKeys(), $ajeno->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHas('error', \App\Exceptions\PerfilesPropios::MENSAJE);

        expect($usuario->fresh()->perfiles->pluck('codigo')->all())->not->toContain($perfil)
            ->and($this->gestor->fresh()->perfiles->pluck('codigo')->all())->not->toContain($perfil);
    })->with(['MEDICO', 'ADMINISTRADOR', 'AUDITOR']);

    test('conservar un perfil ajeno que el usuario ya tenía no es escalar (solo cuenta lo que se agrega)', function () {
        $medico = PerfilAcceso::where('codigo', 'MEDICO')->sole();
        $usuario = User::factory()->conPerfiles([$medico])->create();

        $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [$medico->id, $this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHasNoErrors();
        expect($usuario->fresh()->perfiles->modelKeys())->toEqualCanonicalizing([$medico->id, $this->recepcion->id]);
    });

    test('a un perfil solo se le agregan permisos que quien edita ya tiene', function () {
        $editor = User::factory()->conPermisos(['PERFILES_ACCESO' => ['VER', 'CREAR', 'EDITAR'], 'TURNOS' => ['VER']])->create();
        $this->actingAs($editor);
        $perfil = UserFactory::perfilConPermisos(['HISTORIA_CLINICA' => ['VER']], 'Lectura clínica');
        $id = fn (string $codigo) => ModuloSistema::where('codigo', $codigo)->sole()->id;

        // Agregar TURNOS: VER (lo tiene) y conservar HISTORIA_CLINICA: VER (ya estaba): sí.
        $this->put(route('admin.perfiles-acceso.update', $perfil), [
            'nombre' => 'Lectura clínica', 'estado_id' => Estado::idDe(Estado::ACTIVO),
            'permisos' => [$id('HISTORIA_CLINICA') => ['VER'], $id('TURNOS') => ['VER']],
        ])->assertSessionHasNoErrors();

        // Agregar RECETAS: VER y USUARIOS: CREAR (no los tiene): no, y no cambia nada.
        $this->put(route('admin.perfiles-acceso.update', $perfil), [
            'nombre' => 'Lectura clínica', 'estado_id' => Estado::idDe(Estado::ACTIVO),
            'permisos' => [$id('HISTORIA_CLINICA') => ['VER'], $id('TURNOS') => ['VER'], $id('RECETAS') => ['VER'], $id('USUARIOS') => ['CREAR']],
        ])->assertSessionHasErrors(['permisos' => 'No puede agregar permisos que usted no tiene: RECETAS: VER, USUARIOS: CREAR.']);
        $this->post(route('admin.perfiles-acceso.store'), ['nombre' => 'Nuevo', 'permisos' => [$id('USUARIOS') => ['CREAR']]])
            ->assertSessionHasErrors('permisos');

        expect($perfil->fresh()->clavesPermisos())->toEqualCanonicalizing(['HISTORIA_CLINICA:VER', 'TURNOS:VER'])
            ->and(PerfilAcceso::where('nombre', 'Nuevo')->exists())->toBeFalse();
    });

    test('el Administrador no queda limitado: asigna cualquier perfil y agrega cualquier permiso', function () {
        $this->actingAs($this->admin);
        $usuario = User::factory()->create();

        $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => PerfilAcceso::pluck('id')->all(), 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHasNoErrors();
        $this->put(route('admin.perfiles-acceso.update', $this->recepcion), [
            'nombre' => 'Recepción', 'estado_id' => Estado::idDe(Estado::ACTIVO),
            'permisos' => [ModuloSistema::where('codigo', 'RECETAS')->sole()->id => ['VER']],
        ])->assertSessionHasNoErrors();
    });
});

describe('pantallas', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    test('el listado de usuarios muestra los nombres de sus perfiles', function () {
        $medico = PerfilAcceso::where('codigo', 'MEDICO')->sole();
        User::factory()->conPersona(['apellidos' => 'Galeano', 'nombres' => 'Rocío'])->conPerfiles([$medico, $this->recepcion])->create();

        $this->get(route('admin.usuarios.index'))->assertOk()->assertSeeInOrder(['Galeano, Rocío', 'Médico', 'Recepción']);
    });

    test('el alta crea el usuario con varios perfiles', function () {
        $persona = Persona::factory()->create();
        $medico = PerfilAcceso::where('codigo', 'MEDICO')->sole();

        $this->post(route('admin.usuarios.store'), ['persona_id' => $persona->id, 'perfiles' => [$medico->id, $this->recepcion->id]])->assertSessionHasNoErrors();

        expect(User::where('persona_id', $persona->id)->sole()->perfiles->modelKeys())->toEqualCanonicalizing([$medico->id, $this->recepcion->id]);
    });

    test('el listado de perfiles indica Predefinido, usuarios y permisos', function () {
        User::factory()->count(2)->conPerfiles([$this->recepcion])->create();
        PerfilAcceso::create(['nombre' => 'Perfil propio de la clínica']);

        $html = $this->get(route('admin.perfiles-acceso.index'))->assertOk()->getContent();
        expect($html)->toMatch('/Recepción\s*<span[^>]*>Predefinido<\/span>/')
            ->toMatch('/Recepción.*?<td[^>]*>'.count($this->recepcion->clavesPermisos()).'<\/td>\s*<td[^>]*>2<\/td>/s')
            ->not->toMatch('/Perfil propio de la clínica\s*<span[^>]*>Predefinido/');
    });

    test('Matriz de permisos: perfiles en columnas, módulos en filas, solo lectura; en el menú de Seguridad', function () {
        $html = $this->get(route('admin.matriz-permisos.index'))->assertOk()
            ->assertSee('Matriz de permisos')->assertSee('Recepción')->assertSee('Enfermería')->assertSee('Historia clínica')
            ->assertDontSee('type="checkbox"', false)->assertDontSee('name="permisos', false)
            ->getContent();
        expect($html)->toMatch('/data-perfil="'.PerfilAcceso::where('codigo', 'ENFERMERIA')->value('id').'" data-modulo="PREPARACION">\s*<span class="text-xs">VER, CREAR, EDITAR<\/span>/')
            ->toMatch('/data-perfil="'.PerfilAcceso::where('codigo', 'ENFERMERIA')->value('id').'" data-modulo="HISTORIA_CLINICA">\s*<span[^>]*aria-label="Sin permisos"/');

        $this->get('/dashboard')->assertSeeInOrder(['data-grupo-menu="Seguridad"', route('admin.perfiles-acceso.index'), route('admin.matriz-permisos.index')], false);
    });

    test('la Matriz exige VER sobre Perfiles de acceso', function () {
        $this->actingAs(User::factory()->conPermisos(['USUARIOS' => ['VER']])->create());

        $this->get(route('admin.matriz-permisos.index'))->assertForbidden();
        $this->get('/dashboard')->assertDontSee(route('admin.matriz-permisos.index'));
    });
});

describe('auditoría', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    test('cambiar los perfiles de un usuario es un EDITAR del usuario con la lista antes y después', function () {
        $medico = PerfilAcceso::where('codigo', 'MEDICO')->sole();
        $usuario = User::factory()->conPerfiles([$this->recepcion])->create();

        $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [$medico->id, $this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])->assertSessionHasNoErrors();

        $evento = LogAuditoria::where('tabla_afectada', 'users')->where('registro_afectado_id', (string) $usuario->id)->sole();
        expect($evento)->accion->toBe(AccionAuditoria::EDITAR)->usuario_id->toBe($this->admin->id)
            ->valor_anterior->toBe(['perfiles' => ['Recepción']])
            ->valor_nuevo->toBe(['perfiles' => ['Médico', 'Recepción']]);
    });

    test('el alta registra los perfiles que recibe el usuario; guardar sin cambios no registra nada', function () {
        $persona = Persona::factory()->create();
        $this->post(route('admin.usuarios.store'), ['persona_id' => $persona->id, 'perfiles' => [$this->recepcion->id]])->assertSessionHasNoErrors();
        $usuario = User::where('persona_id', $persona->id)->sole();

        $eventos = LogAuditoria::where('tabla_afectada', 'users')->where('registro_afectado_id', (string) $usuario->id)->orderBy('id')->get();
        expect($eventos->pluck('accion')->all())->toBe([AccionAuditoria::CREAR, AccionAuditoria::EDITAR])
            ->and($eventos[1]->valor_anterior)->toBe(['perfiles' => []])->and($eventos[1]->valor_nuevo)->toBe(['perfiles' => ['Recepción']]);

        $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [$this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])->assertSessionHasNoErrors();
        expect(LogAuditoria::where('tabla_afectada', 'users')->where('registro_afectado_id', (string) $usuario->id)->count())->toBe(2);
    });

    test('un intento rechazado (escalada o último administrador) no deja eventos', function () {
        $gestor = User::factory()->conPerfiles([UserFactory::perfilConPermisos(['USUARIOS' => ['VER', 'EDITAR']], 'Gestión de usuarios')])->create();
        $vacio = PerfilAcceso::create(['nombre' => 'Sin permisos']);
        $otro = User::factory()->create();
        $this->actingAs($gestor);
        $antes = LogAuditoria::max('id');

        $this->put(route('admin.usuarios.update', $this->admin), ['perfiles' => [$vacio->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHas('error', UltimoAdministrador::MENSAJE);
        $this->put(route('admin.usuarios.update', $otro), ['perfiles' => [$this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHasErrors('perfiles');
        $this->put(route('admin.usuarios.update', $gestor), ['perfiles' => [$vacio->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHas('error', \App\Exceptions\PerfilesPropios::MENSAJE);

        expect(LogAuditoria::where('id', '>', $antes ?? 0)->where('tabla_afectada', 'users')->exists())->toBeFalse()
            ->and($this->admin->fresh()->perfiles->modelKeys())->toBe([PerfilAcceso::where('codigo', 'ADMINISTRADOR')->value('id')]);
    });

    test('los cambios de permisos de un perfil se auditan como EDITAR del perfil', function () {
        $turnos = ModuloSistema::where('codigo', 'TURNOS')->sole();

        $this->put(route('admin.perfiles-acceso.update', $this->recepcion), [
            'nombre' => 'Recepción', 'estado_id' => Estado::idDe(Estado::ACTIVO), 'permisos' => [$turnos->id => ['VER']],
        ])->assertSessionHasNoErrors();

        $evento = LogAuditoria::where('tabla_afectada', 'perfiles_acceso')->where('registro_afectado_id', (string) $this->recepcion->id)->where('accion', AccionAuditoria::EDITAR)->latest('id')->first();
        expect($evento->valor_nuevo)->toBe(['permisos' => ['TURNOS: VER']])
            ->and($evento->valor_anterior['permisos'])->toContain('TURNOS: CREAR', 'PACIENTES: VER');
    });

    test('abrir la Matriz de permisos registra VER (Perfiles de acceso es un módulo sensible)', function () {
        $this->get(route('admin.matriz-permisos.index'))->assertOk();

        expect(LogAuditoria::where('accion', AccionAuditoria::VER)->where('tabla_afectada', 'perfiles_acceso')->where('usuario_id', $this->admin->id)->count())->toBe(1);
    });
});
