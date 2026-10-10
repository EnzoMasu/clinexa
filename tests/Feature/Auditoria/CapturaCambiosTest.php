<?php

use App\Enums\AccionAuditoria;
use App\Models\CatalogoCIE10;
use App\Models\CategoriaProveedor;
use App\Models\Especialidad;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Proveedor;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Support\ContextoAuditoria;
use Database\Seeders\DatosRealesClinicaSeeder;
use Database\Seeders\GeografiaSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->administrador()->create();
    $this->actingAs($this->admin);
});

/** Los registros de una tabla (y opcionalmente de un registro), del más viejo al más nuevo. */
function logsDe(string $tabla, int|string|null $id = null)
{
    return LogAuditoria::where('tabla_afectada', $tabla)
        ->when($id !== null, fn ($query) => $query->where('registro_afectado_id', (string) $id))
        ->orderBy('id')->get();
}

describe('crear, editar y desactivar', function () {
    test('especialidades: antes y después de cada acción', function () {
        $this->post(route('admin.especialidades.store'), ['nombre' => 'Cardiología', 'descripcion' => 'Corazón']);
        $especialidad = Especialidad::where('nombre', 'Cardiología')->sole();

        $this->put(route('admin.especialidades.update', $especialidad), ['nombre' => 'Cardiología clínica', 'descripcion' => 'Corazón', 'estado_id' => estadoId('ACTIVO')]);
        $this->patch(route('admin.especialidades.desactivar', $especialidad));

        [$crear, $editar, $desactivar] = logsDe('especialidades', $especialidad->id)->all();

        expect($crear)->accion->toBe(AccionAuditoria::CREAR)->valor_anterior->toBeNull()
            ->usuario_id->toBe($this->admin->id)->ip_origen->toBe('127.0.0.1')
            ->and($crear->valor_nuevo)->toMatchArray(['nombre' => 'Cardiología', 'descripcion' => 'Corazón', 'estado_id' => estadoId('ACTIVO')])
            ->and($crear->valor_nuevo)->not->toHaveKeys(['created_at', 'updated_at'])
            // Al editar, solo lo que cambió.
            ->and($editar)->accion->toBe(AccionAuditoria::EDITAR)
            ->valor_anterior->toBe(['nombre' => 'Cardiología'])
            ->valor_nuevo->toBe(['nombre' => 'Cardiología clínica'])
            ->and($desactivar)->accion->toBe(AccionAuditoria::DESACTIVAR)
            ->valor_anterior->toBe(['estado_id' => estadoId('ACTIVO')])
            ->valor_nuevo->toBe(['estado_id' => estadoId('INACTIVO')]);
    });

    test('CIE-10: la clave es el código (texto)', function () {
        $this->post(route('admin.cie10.store'), ['codigo' => 'J06.9', 'descripcion' => 'IVRS aguda', 'capitulo' => 'X']);
        $cie = CatalogoCIE10::findOrFail('J06.9');
        $this->put(route('admin.cie10.update', $cie), ['descripcion' => 'IVRS aguda, no especificada', 'capitulo' => 'X', 'estado_id' => estadoId('ACTIVO')]);
        $this->patch(route('admin.cie10.desactivar', $cie));

        expect(logsDe('catalogo_cie10', 'J06.9')->pluck('accion')->all())
            ->toBe([AccionAuditoria::CREAR, AccionAuditoria::EDITAR, AccionAuditoria::DESACTIVAR])
            ->and(logsDe('catalogo_cie10', 'J06.9')[1]->valor_nuevo)->toBe(['descripcion' => 'IVRS aguda, no especificada']);
    });

    test('personas: reactivar es un EDITAR', function () {
        $persona = Persona::factory()->inactiva()->create();

        $this->put(route('admin.personas.update', $persona), enFormulario([
            ...$persona->only(['tipo_persona', 'tipo_documento_id', 'nro_documento', 'apellidos', 'nombres', 'email', 'telefono', 'direccion']),
            'fecha_nacimiento' => $persona->fecha_nacimiento->format('Y-m-d'), 'estado_id' => estadoId('ACTIVO'),
        ]))->assertSessionHasNoErrors();

        expect(logsDe('personas', $persona->id)->sole())
            ->accion->toBe(AccionAuditoria::EDITAR)
            ->valor_nuevo->toBe(['estado_id' => estadoId('ACTIVO')]);
    });

    test('pasar un usuario a BLOQUEADO se registra como BLOQUEO', function () {
        $otro = User::factory()->create();

        $this->put(route('admin.usuarios.update', $otro), ['perfiles' => $otro->perfiles->modelKeys(), 'estado_id' => estadoId('BLOQUEADO')])->assertSessionHasNoErrors();

        expect(logsDe('users', $otro->id)->sole())->accion->toBe(AccionAuditoria::BLOQUEO)
            ->valor_nuevo->toMatchArray(['estado_id' => estadoId('BLOQUEADO')]); // y lo demás que cambió en el mismo guardado
    });
});

describe('campos sensibles', function () {
    test('cambiar la contraseña desde el perfil no deja la contraseña ni su hash en ningún registro', function () {
        $this->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'Nueva$Clave2026x', 'password_confirmation' => 'Nueva$Clave2026x',
        ])->assertSessionHasNoErrors();

        $hash = $this->admin->fresh()->password;
        $todo = LogAuditoria::all()->map(fn ($log) => json_encode([$log->valor_anterior, $log->valor_nuevo, $log->detalle]))->join(' ');

        expect($todo)->not->toContain($hash)->not->toContain('Nueva$Clave2026x')->not->toContain('password')
            // Ni un EDITAR del usuario: solo cambiaron campos excluidos.
            ->and(logsDe('users', $this->admin->id)->where('accion', AccionAuditoria::EDITAR))->toBeEmpty();
    });

    test('dar de alta un usuario no guarda su contraseña ni tokens', function () {
        $persona = Persona::factory()->create();
        $this->post(route('admin.usuarios.store'), ['persona_id' => $persona->id, 'perfiles' => $this->admin->perfiles->modelKeys()]);

        $crear = logsDe('users')->where('accion', AccionAuditoria::CREAR)->sole();
        expect($crear->valor_nuevo)->toHaveKeys(['persona_id', 'email'])
            ->not->toHaveKeys(['password', 'remember_token']);
    });

    test('si solo cambian campos excluidos (ultimo_acceso) no se registra nada', function () {
        $this->get('/dashboard');
        $this->admin->forceFill(['ultimo_acceso' => now()]);

        // Dentro de un pedido web, con el usuario autenticado.
        app(ContextoAuditoria::class)->pedidoWeb = true;
        $this->admin->save();
        app(ContextoAuditoria::class)->pedidoWeb = false;

        expect(LogAuditoria::count())->toBe(0);
    });
});

describe('cuándo no se registra', function () {
    test('un rollback no deja registro', function () {
        app(ContextoAuditoria::class)->pedidoWeb = true;

        try {
            DB::transaction(function () {
                Especialidad::create(['nombre' => 'Se deshace']);
                expect(LogAuditoria::count())->toBe(1);
                throw new RuntimeException('falla después de guardar');
            });
        } catch (RuntimeException) {
        }

        app(ContextoAuditoria::class)->pedidoWeb = false;
        expect(Especialidad::where('nombre', 'Se deshace')->exists())->toBeFalse()
            ->and(LogAuditoria::count())->toBe(0);
    });

    test('seeders, comandos de consola y código fuera de un pedido web no generan log', function () {
        $this->seed([GeografiaSeeder::class, DatosRealesClinicaSeeder::class]);
        Artisan::call('db:seed', ['--class' => 'ModulosSensiblesSeeder']);
        Especialidad::create(['nombre' => 'Fuera de un pedido']);

        expect(LogAuditoria::count())->toBe(0);
    });

    test('sin usuario autenticado tampoco', function () {
        auth()->logout();
        app(ContextoAuditoria::class)->pedidoWeb = true;
        Especialidad::create(['nombre' => 'Sin usuario']);
        app(ContextoAuditoria::class)->pedidoWeb = false;

        expect(LogAuditoria::count())->toBe(0);
    });
});

describe('tablas pivote', function () {
    test('la matriz de permisos de un perfil queda registrada con la lista de antes y la de después', function () {
        $perfil = PerfilAcceso::create(['nombre' => 'Recepción']);
        $personas = ModuloSistema::where('codigo', 'PERSONAS')->sole();
        $turnos = ModuloSistema::where('codigo', 'TURNOS')->sole();

        $this->put(route('admin.perfiles-acceso.update', $perfil), [
            'nombre' => 'Recepción', 'estado_id' => estadoId('ACTIVO'),
            'permisos' => [$personas->id => ['VER', 'CREAR'], $turnos->id => ['VER']],
        ])->assertSessionHasNoErrors();
        $this->put(route('admin.perfiles-acceso.update', $perfil), [
            'nombre' => 'Recepción', 'estado_id' => estadoId('ACTIVO'), 'permisos' => [$personas->id => ['VER']],
        ]);

        $cambios = logsDe('perfiles_acceso', $perfil->id);
        expect($cambios)->toHaveCount(2)
            ->and($cambios[0])->accion->toBe(AccionAuditoria::EDITAR)
            ->valor_anterior->toBe(['permisos' => []])
            ->valor_nuevo->toBe(['permisos' => ['PERSONAS: CREAR', 'PERSONAS: VER', 'TURNOS: VER']])
            ->and($cambios[1]->valor_nuevo)->toBe(['permisos' => ['PERSONAS: VER']]);
    });

    test('especialidades del profesional y categorías del proveedor', function () {
        $eco = Especialidad::create(['nombre' => 'Ecografía']);
        $profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);
        $this->put(route('admin.profesionales.update', $profesional), enFormulario([
            'matricula' => 'MP-1', 'estado_id' => estadoId('ACTIVO'), 'con_especialidades' => '1',
            'especialidades' => [['especialidad_id' => $eco->id, 'fecha_desde' => '2015-03-01', 'nro_matricula_especialidad' => 'GO-77']],
        ]))->assertSessionHasNoErrors();

        expect(logsDe('profesionales', $profesional->id)->sole()->valor_nuevo)
            ->toBe(['especialidades' => ['Ecografía (desde 01/03/2015, matrícula GO-77)']]);

        $equipos = CategoriaProveedor::create(['nombre' => 'Equipos médicos']);
        $proveedor = Proveedor::create(['persona_id' => Persona::factory()->create()->id]);
        $this->put(route('admin.proveedores.update', $proveedor), ['estado_id' => estadoId('ACTIVO'), 'con_categorias' => '1', 'categorias' => [$equipos->id]]);

        expect(logsDe('proveedores', $proveedor->id)->sole())
            ->valor_anterior->toBe(['categorias' => []])->valor_nuevo->toBe(['categorias' => ['Equipos médicos']]);
    });

    test('tipos de documento: también queda registrado el tipo que pierde el predeterminado', function () {
        $personas = ModuloSistema::where('codigo', 'PERSONAS')->sole();
        $ci = TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
        $ruc = TipoDocumento::create(['codigo' => 'RUC', 'nombre' => 'RUC']);
        $personas->configurarTiposDocumento(['CI', 'RUC']);

        $this->put(route('admin.tipos-documento.update', $ruc), [
            'codigo' => 'RUC', 'nombre' => 'RUC', 'estado_id' => estadoId('ACTIVO'),
            'modulos' => [$personas->id], 'predeterminado' => [$personas->id],
        ])->assertSessionHasNoErrors();

        expect(logsDe('tipos_documento', $ruc->id)->sole()->valor_nuevo)->toBe(['modulos' => ['Personas (predeterminado)']])
            ->and(logsDe('tipos_documento', $ci->id)->sole())
            ->valor_anterior->toBe(['modulos' => ['Personas (predeterminado)']])
            ->valor_nuevo->toBe(['modulos' => ['Personas']]);
    });

    test('guardar sin cambiar la matriz no registra nada', function () {
        $perfil = PerfilAcceso::create(['nombre' => 'Recepción']);
        $this->put(route('admin.perfiles-acceso.update', $perfil), ['nombre' => 'Recepción', 'estado_id' => estadoId('ACTIVO'), 'permisos' => []]);

        expect(logsDe('perfiles_acceso', $perfil->id))->toBeEmpty();
    });
});

test('rendimiento: un solo INSERT por operación y ninguna consulta extra en los listados', function () {
    Especialidad::insert(collect(range(1, 30))->map(fn ($i) => ['nombre' => "Especialidad {$i}", 'estado_id' => estadoId('ACTIVO'), 'created_at' => now(), 'updated_at' => now()])->all());

    DB::enableQueryLog();
    $this->post(route('admin.especialidades.store'), ['nombre' => 'Una más']);
    $inserciones = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'insert into "logs_auditoria"'));
    expect($inserciones)->toHaveCount(1);

    DB::flushQueryLog();
    $this->get(route('admin.especialidades.index'))->assertOk();
    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'logs_auditoria')))->toBeEmpty();
});
