<?php

/*
 * Matriz de permisos editable (MatrizPermisosController + PermisosDePerfil): editar un perfil con confirmación,
 * sin JavaScript; escalada, Administrador fijo, huella (concurrencia), confirmación reforzada, permisos
 * inválidos, efecto en el siguiente pedido, auditoría y la pestaña Comparar. Datos inventados.
 */

use App\Enums\AccionAuditoria;
use App\Http\Controllers\Admin\MatrizPermisosController;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use App\Support\PermisosDePerfil;
use Database\Factories\UserFactory;
use Database\Seeders\ModulosSensiblesSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->principal()->create();
    PerfilesPredefinidos::asegurar();
    (new ModulosSensiblesSeeder)->run();
    $this->perfil = UserFactory::perfilConPermisos(['TURNOS' => ['VER'], 'CONSULTORIOS' => ['VER']], 'Mostrador');
    $this->actingAs($this->admin);
});

/** permisos[modulo_id][] del formulario, desde [CODIGO => [ACCIONES]]. */
function matrizPermisosFormulario(array $matriz): array
{
    return collect($matriz)->mapWithKeys(fn (array $acciones, string $codigo) => [ModuloSistema::where('codigo', $codigo)->value('id') => $acciones])->all();
}

/** Manda el formulario (primer paso: confirmación, o con confirmado=1: guardar). */
function matrizPermisosGuardar(PerfilAcceso $perfil, array $matriz, array $extra = [])
{
    return test()->put(route('admin.matriz-permisos.update', $perfil), [
        'huella' => PermisosDePerfil::huella($perfil->fresh()), 'permisos' => matrizPermisosFormulario($matriz), ...$extra,
    ]);
}

function matrizPermisosEventos(PerfilAcceso $perfil)
{
    return LogAuditoria::where('tabla_afectada', 'perfiles_acceso')->where('registro_afectado_id', (string) $perfil->id)->where('accion', AccionAuditoria::EDITAR->value)->get();
}

describe('la pantalla', function () {
    test('selector de perfil, módulos agrupados como en el menú, casillas solo donde la acción tiene efecto, atajos y buscador', function () {
        $html = $this->get(route('admin.matriz-permisos.index', ['perfil' => $this->perfil->id]))->assertOk()
            ->assertSee('name="perfil"', false)->assertSee('Mostrador')
            ->assertSeeInOrder(['data-grupo="Seguridad"', 'data-grupo="Personas y Roles"', 'data-grupo="Agenda"', 'data-grupo="Clínica"', 'data-grupo="Catálogos"'], false)
            ->assertSee('Guardar cambios')->assertSee('Buscar módulo')->assertSee('Solo ver')
            ->getContent();
        $id = fn (string $codigo) => ModuloSistema::where('codigo', $codigo)->value('id');

        // Tildados los actuales; Auditoría solo VER y EXPORTAR; Recetas sin DESACTIVAR.
        expect($html)->toMatch('/name="permisos\['.$id('TURNOS').'\]\[\]" value="VER"[^>]*checked/')
            ->not->toMatch('/name="permisos\['.$id('TURNOS').'\]\[\]" value="CREAR"[^>]*checked/')
            ->not->toContain('name="permisos['.$id('AUDITORIA').'][]" value="CREAR"')
            ->not->toContain('name="permisos['.$id('RECETAS').'][]" value="DESACTIVAR"')
            ->toContain('c.checked = c.value === \'VER\'');
        // Sin JavaScript: un formulario común (POST con _method PUT) y la huella.
        expect($html)->toContain('action="'.route('admin.matriz-permisos.update', $this->perfil).'"')
            ->toContain('name="_method" value="PUT"')->toContain('name="huella" value="'.PermisosDePerfil::huella($this->perfil).'"');
    });

    test('todos los módulos tienen grupo; solo los que no están en el menú van a Otros', function () {
        $enGrupos = collect(MatrizPermisosController::GRUPOS)->flatten();
        $sinGrupo = ModuloSistema::pluck('codigo')->diff($enGrupos)->values()->all();

        expect($sinGrupo)->toBe(['MODULOS_SISTEMA'])
            ->and($enGrupos->diff(ModuloSistema::pluck('codigo'))->values()->all())->toBe([]);
    });

    test('abrir la pantalla registra VER (como siempre, sin repetirlo en unos minutos); guardar no registra lecturas', function () {
        $this->get(route('admin.matriz-permisos.index'))->assertOk();
        $this->get(route('admin.matriz-permisos.index', ['vista' => 'comparar']))->assertOk();
        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER', 'CREAR']])->assertOk();

        expect(LogAuditoria::where('accion', AccionAuditoria::VER->value)->where('tabla_afectada', 'perfiles_acceso')->where('usuario_id', $this->admin->id)->count())->toBe(1);
    });

    test('cantidad fija de consultas SQL, con 12 perfiles y 31 módulos y con más', function () {
        $contar = function (array $parametros) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('admin.matriz-permisos.index', $parametros))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        expect(PerfilAcceso::count())->toBe(12)->and(ModuloSistema::count())->toBe(31);
        $this->get(route('admin.matriz-permisos.index')); // calentamiento
        [$editar, $comparar] = [$contar(['perfil' => $this->perfil->id]), $contar(['vista' => 'comparar'])];

        foreach (range(1, 5) as $i) {
            UserFactory::perfilConPermisos(['PERSONAS' => ['VER', 'CREAR'], 'TURNOS' => ['VER'], 'RECETAS' => ['VER']], "Perfil extra {$i}");
        }
        expect($contar(['perfil' => $this->perfil->id]))->toBe($editar)
            ->and($contar(['vista' => 'comparar']))->toBe($comparar);
    });
});

describe('guardar', function () {
    test('agregar y quitar: primero la confirmación con la lista (nada se guarda), después se aplica todo junto', function () {
        $confirmacion = matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER', 'CREAR'], 'DISPONIBILIDAD' => ['VER']], ['motivo' => 'Cubre el mostrador'])
            ->assertOk()->assertSee('Confirmar cambios de permisos')->assertSee('Confirmar y guardar')
            ->assertSeeInOrder(['Se agregan', 'DISPONIBILIDAD: ver', 'TURNOS: crear', 'Se quitan', 'CONSULTORIOS: ver'])
            ->assertSee('name="confirmado" value="1"', false)->assertDontSee('confirmar_delicados', false)
            ->getContent();
        expect($this->perfil->fresh()->clavesPermisos())->toEqualCanonicalizing(['TURNOS:VER', 'CONSULTORIOS:VER'])
            ->and(matrizPermisosEventos($this->perfil))->toHaveCount(0)
            // La confirmación lleva los permisos en campos ocultos (sin JavaScript).
            ->and($confirmacion)->toContain('type="hidden" name="permisos['.ModuloSistema::where('codigo', 'TURNOS')->value('id').'][]" value="CREAR"');

        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER', 'CREAR'], 'DISPONIBILIDAD' => ['VER']], ['motivo' => 'Cubre el mostrador', 'confirmado' => '1'])
            ->assertRedirect(route('admin.matriz-permisos.index', ['perfil' => $this->perfil->id]))
            ->assertSessionHas('status', 'Permisos de «Mostrador» guardados: 2 agregados y 1 quitados. Valen desde el próximo pedido de cada usuario del perfil.');

        expect($this->perfil->fresh()->clavesPermisos())->toEqualCanonicalizing(['TURNOS:VER', 'TURNOS:CREAR', 'DISPONIBILIDAD:VER']);
        $evento = matrizPermisosEventos($this->perfil)->sole();
        expect($evento)->usuario_id->toBe($this->admin->id)->detalle->toBe('Motivo: Cubre el mostrador')
            ->valor_nuevo->toBe(['permisos_agregados' => ['DISPONIBILIDAD: ver', 'TURNOS: crear']])
            ->valor_anterior->toBe(['permisos_quitados' => ['CONSULTORIOS: ver']]);
    });

    test('lo que mandan los atajos: "Solo ver", "Ninguno" (todo destildado) y "Todo"', function () {
        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER'], 'PROCEDIMIENTOS' => ['VER']], ['confirmado' => '1'])->assertSessionHasNoErrors();
        expect($this->perfil->fresh()->clavesPermisos())->toEqualCanonicalizing(['TURNOS:VER', 'PROCEDIMIENTOS:VER']);

        matrizPermisosGuardar($this->perfil, [], ['confirmado' => '1'])->assertSessionHasNoErrors();
        expect($this->perfil->fresh()->clavesPermisos())->toBe([]);

        matrizPermisosGuardar($this->perfil, ['PROCEDIMIENTOS' => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR', 'EXPORTAR']], ['confirmado' => '1'])->assertSessionHasNoErrors();
        expect($this->perfil->fresh()->clavesPermisos())->toHaveCount(5);
    });

    test('un guardado sin cambios no guarda ni registra nada', function () {
        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER'], 'CONSULTORIOS' => ['VER']], ['confirmado' => '1'])
            ->assertSessionHas('status', 'No hay cambios para guardar.');

        expect(matrizPermisosEventos($this->perfil))->toHaveCount(0);
    });

    test('concurrencia: si el perfil cambió desde que se abrió, se rechaza y no cambia nada', function () {
        $huellaVieja = PermisosDePerfil::huella($this->perfil);
        PermisosDePerfil::guardar($this->perfil, ['TURNOS:VER']); // otra ventana

        $this->put(route('admin.matriz-permisos.update', $this->perfil), ['huella' => $huellaVieja, 'confirmado' => '1', 'permisos' => matrizPermisosFormulario(['RECETAS' => ['VER']])])
            ->assertRedirect(route('admin.matriz-permisos.index', ['perfil' => $this->perfil->id]))
            ->assertSessionHas('error', 'El perfil fue modificado desde otra ventana. Recargue la página.');

        expect($this->perfil->fresh()->clavesPermisos())->toBe(['TURNOS:VER']);
    });

    test('un permiso inexistente, una acción sin efecto o un id manipulado se rechaza', function (Closure $permisos, string $mensaje) {
        $this->from(route('admin.matriz-permisos.index', ['perfil' => $this->perfil->id]))
            ->put(route('admin.matriz-permisos.update', $this->perfil), ['huella' => PermisosDePerfil::huella($this->perfil), 'confirmado' => '1', 'permisos' => $permisos()])
            ->assertSessionHasErrors(['permisos' => $mensaje]);

        expect($this->perfil->fresh()->clavesPermisos())->toEqualCanonicalizing(['TURNOS:VER', 'CONSULTORIOS:VER']);
    })->with([
        'módulo inexistente' => [fn () => [99999 => ['VER']], 'La matriz de permisos incluye un módulo inexistente.'],
        'id no numérico' => [fn () => ['TURNOS' => ['VER']], 'La matriz de permisos incluye un módulo inexistente.'],
        'acción inexistente' => [fn () => matrizPermisosFormulario(['TURNOS' => ['BORRAR']]), 'La matriz de permisos incluye una acción inexistente.'],
        'acción sin efecto' => [fn () => matrizPermisosFormulario(['AUDITORIA' => ['CREAR']]), 'La acción CREAR no tiene efecto en el módulo AUDITORIA.'],
        'no es una lista' => [fn () => 'TURNOS:VER', 'La matriz de permisos no es válida.'],
    ]);

    test('confirmación reforzada (módulo sensible o escritura en Usuarios/Perfiles): el servidor también la exige', function (string $modulo, string $accion) {
        $matriz = ['TURNOS' => ['VER'], 'CONSULTORIOS' => ['VER'], $modulo => [$accion]];

        matrizPermisosGuardar($this->perfil, $matriz)->assertOk()
            ->assertSee('Atención: se agregan permisos delicados.')->assertSee('name="confirmar_delicados"', false);
        // Confirmado, pero sin la casilla reforzada: vuelve a pedirla y no guarda.
        matrizPermisosGuardar($this->perfil, $matriz, ['confirmado' => '1'])->assertOk()
            ->assertSee(MatrizPermisosController::FALTA_CONFIRMAR);
        expect($this->perfil->fresh()->clavesPermisos())->not->toContain("{$modulo}:{$accion}");

        matrizPermisosGuardar($this->perfil, $matriz, ['confirmado' => '1', 'confirmar_delicados' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        expect($this->perfil->fresh()->clavesPermisos())->toContain("{$modulo}:{$accion}");
    })->with([
        'escritura en Usuarios' => ['USUARIOS', 'EDITAR'],
        'escritura en Perfiles' => ['PERFILES_ACCESO', 'CREAR'],
        'módulo sensible (Pacientes)' => ['PACIENTES', 'VER'],
        'módulo sensible (Historia clínica)' => ['HISTORIA_CLINICA', 'VER'],
    ]);

    test('agregar algo no delicado no pide la casilla reforzada', function () {
        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER', 'CREAR'], 'CONSULTORIOS' => ['VER']])->assertOk()->assertDontSee('confirmar_delicados', false);
    });
});

describe('reglas', function () {
    test('escalada: solo se agrega lo que quien edita ya tiene; quitar siempre se puede', function () {
        $editor = User::factory()->conPermisos(['PERFILES_ACCESO' => ['VER', 'EDITAR'], 'TURNOS' => ['VER', 'CREAR']])->create();
        $this->actingAs($editor);

        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER'], 'RECETAS' => ['VER']], ['confirmado' => '1', 'confirmar_delicados' => '1'])
            ->assertSessionHasErrors(['permisos' => 'No puede agregar permisos que usted no tiene: RECETAS: VER.']);
        // Quita CONSULTORIOS: VER (que no tiene) y agrega TURNOS: CREAR (que sí tiene).
        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER', 'CREAR']], ['confirmado' => '1'])->assertSessionHasNoErrors();

        expect($this->perfil->fresh()->clavesPermisos())->toEqualCanonicalizing(['TURNOS:VER', 'TURNOS:CREAR']);
    });

    test('el perfil Administrador no se edita: casillas deshabilitadas y el servidor lo rechaza', function () {
        $admin = PerfilAcceso::where('codigo', 'ADMINISTRADOR')->sole();
        $antes = $admin->clavesPermisos();

        $this->get(route('admin.matriz-permisos.index', ['perfil' => $admin->id]))->assertOk()
            ->assertSee('El perfil Administrador tiene siempre todos los permisos y no se edita')->assertDontSee('Guardar cambios');
        $this->put(route('admin.matriz-permisos.update', $admin), ['huella' => PermisosDePerfil::huella($admin), 'confirmado' => '1', 'permisos' => []])
            ->assertSessionHas('error', MatrizPermisosController::ADMINISTRADOR_FIJO);

        expect($admin->fresh()->clavesPermisos())->toEqualCanonicalizing($antes);
    });

    test('con VER sobre Perfiles de acceso se mira (casillas deshabilitadas); guardar exige EDITAR', function () {
        $this->actingAs(User::factory()->conPermisos(['PERFILES_ACCESO' => ['VER']])->create());

        $html = $this->get(route('admin.matriz-permisos.index', ['perfil' => $this->perfil->id]))->assertOk()->assertDontSee('Guardar cambios')->getContent();
        expect($html)->toMatch('/name="permisos\[\d+\]\[\]"[^>]*\sdisabled[\s>]/');
        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER', 'CREAR']], ['confirmado' => '1'])->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.matriz-permisos.index'))->assertForbidden();
    });

    test('un perfil inactivo se edita y sigue sin dar permisos', function () {
        $usuario = User::factory()->conPerfiles([$this->perfil, UserFactory::perfilConPermisos(['PERSONAS' => ['VER']], 'Otro')])->create();
        $this->perfil->desactivar();

        $this->get(route('admin.matriz-permisos.index', ['perfil' => $this->perfil->id]))->assertSee('Este perfil está inactivo');
        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER', 'CREAR']], ['confirmado' => '1'])->assertSessionHasNoErrors();

        expect($this->perfil->fresh()->clavesPermisos())->toContain('TURNOS:CREAR')
            ->and($usuario->fresh()->tienePermiso('TURNOS', 'CREAR'))->toBeFalse();
    });

    test('desde esta pantalla no se crean ni se desactivan perfiles', function () {
        $antes = PerfilAcceso::count();
        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER', 'CREAR']], ['confirmado' => '1', 'estado_id' => Estado::idDe(Estado::INACTIVO), 'nombre' => 'Otro nombre']);

        expect(PerfilAcceso::count())->toBe($antes)->and($this->perfil->fresh())->nombre->toBe('Mostrador')->and($this->perfil->fresh()->estaActivo())->toBeTrue();
    });
});

describe('efecto en el siguiente pedido', function () {
    test('un usuario del perfil ve el menú nuevo y recibe 403 donde se le quitó', function () {
        $usuario = User::factory()->conPerfiles([$this->perfil])->create();
        $this->actingAs($usuario)->get('/dashboard')->assertSee(route('admin.consultorios.index'));
        $this->get(route('admin.consultorios.index'))->assertOk();

        $this->actingAs($this->admin);
        matrizPermisosGuardar($this->perfil, ['TURNOS' => ['VER'], 'PROCEDIMIENTOS' => ['VER']], ['confirmado' => '1'])->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards(); // el siguiente pedido del usuario: lo vuelve a leer, sin nada guardado
        $this->actingAs(User::find($usuario->id));
        $this->get('/dashboard')->assertDontSee(route('admin.consultorios.index'))->assertSee(route('admin.procedimientos.index'));
        $this->get(route('admin.consultorios.index'))->assertForbidden();
        $this->get(route('admin.procedimientos.index'))->assertOk();
    });

    test('el autoguardado sin el permiso responde 403 y la pantalla muestra el cartel que ya existe', function () {
        hcEscenario();
        $enfermeria = UserFactory::perfilConPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']], 'Enfermería de prueba');
        $enfermera = User::factory()->conPerfiles([$enfermeria])->personalDeSalud()->create();
        $this->actingAs($enfermera)->post(route('admin.preparacion.preparar', hcTurno()))->assertRedirect();
        $consulta = Consulta::latest('id')->firstOrFail();

        $this->actingAs($this->admin);
        matrizPermisosGuardar($enfermeria, ['PREPARACION' => ['VER']], ['confirmado' => '1'])->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::find($enfermera->id));
        $this->patchJson(route('admin.consultas.autoguardado', $consulta), ['version' => $consulta->version(), 'examen' => ['temperatura' => '37']])->assertForbidden();
        expect(file_get_contents(resource_path('js/consulta-autoguardado.js')))
            ->toContain('[401, 403, 419].includes(respuesta.status)')
            ->toContain('Su sesión venció o perdió el permiso.');
    });
});

describe('Comparar y lo predefinido', function () {
    test('la pestaña Comparar marca las celdas en que un predefinido difiere de su matriz', function () {
        $recepcion = PerfilAcceso::where('codigo', 'RECEPCION')->sole();
        $html = $this->get(route('admin.matriz-permisos.index', ['vista' => 'comparar']))->assertOk()->getContent();
        expect($html)->not->toContain('data-difiere');

        PermisosDePerfil::guardar($recepcion, [...PermisosDePerfil::claves($recepcion), 'SUCURSALES:VER']);
        $html = $this->get(route('admin.matriz-permisos.index', ['vista' => 'comparar']))->assertOk()->getContent();

        expect(substr_count($html, 'data-difiere'))->toBe(1)
            ->and($html)->toMatch('/data-perfil="'.$recepcion->id.'" data-modulo="SUCURSALES"\s+data-difiere title="Predefinido: sin permisos"/')
            // Un perfil sin código (no predefinido) nunca se marca.
            ->and($html)->not->toMatch('/data-perfil="'.$this->perfil->id.'" data-modulo="[A-Z_]+"\s+data-difiere/');
        expect(PerfilesPredefinidos::MATRIZ['RECEPCION'])->not->toHaveKey('SUCURSALES'); // la matriz predefinida no cambió
    });

    test('Restablecer a lo predefinido: muestra la matriz predefinida tildada y se guarda con la misma confirmación', function () {
        $recepcion = PerfilAcceso::where('codigo', 'RECEPCION')->sole();
        PermisosDePerfil::guardar($recepcion, ['TURNOS:VER']);

        $this->get(route('admin.matriz-permisos.index', ['perfil' => $recepcion->id]))->assertSee('Restablecer a lo predefinido');
        $html = $this->get(route('admin.matriz-permisos.index', ['perfil' => $recepcion->id, 'predefinido' => 1]))->assertOk()
            ->assertSee('Se muestran los permisos')->getContent();
        expect($html)->toMatch('/name="permisos\['.ModuloSistema::where('codigo', 'PACIENTES')->value('id').'\]\[\]" value="CREAR"[^>]*checked/');

        $predefinida = PerfilesPredefinidos::MATRIZ['RECEPCION'];
        matrizPermisosGuardar($recepcion, $predefinida)->assertOk()->assertSee('Atención: se agregan permisos delicados.');
        matrizPermisosGuardar($recepcion, $predefinida, ['confirmado' => '1', 'confirmar_delicados' => '1'])->assertSessionHasNoErrors();
        expect(PerfilesPredefinidos::diferencia($recepcion, 'RECEPCION'))->toBe(['sobran' => [], 'faltan' => []]);
    });
});
