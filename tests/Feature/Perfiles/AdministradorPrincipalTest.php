<?php

/*
 * Administrador principal inamovible (App\Support\AdministradorPrincipal). Para cada protección (a: estado,
 * b: perfiles, c: persona, d: datos de su persona e invitaciones) y cada actor (un segundo administrador, el
 * propio principal y la consola): por formulario, por servicio y por modelo, sin cambios en la base. La consola
 * queda afuera de las guardas (como las otras), así que ahí se verifica que SÍ puede. Datos inventados.
 */

use App\Exceptions\AccionRechazada;
use App\Exceptions\AdministradorPrincipalInamovible;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Support\AdministradorPrincipal;
use App\Support\ContextoAuditoria;
use App\Support\PerfilesPredefinidos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
    ModuloSistema::query()->exists() || (new Database\Seeders\ModuloSistemaSeeder)->run();
    ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI']);
    $this->principal = User::factory()->principal()->conPersona(['apellidos' => 'Ríos', 'nombres' => 'Aurelio', 'nro_documento' => '7100001'])->create();
    $this->segundo = User::factory()->administrador()->conPersona(['apellidos' => 'Vera', 'nombres' => 'Julián', 'nro_documento' => '7100002'])->create();
    PerfilesPredefinidos::asegurar();
    $this->recepcion = PerfilAcceso::where('codigo', 'RECEPCION')->sole();
});

/** Lo que no debe cambiar del principal: su usuario, su persona y sus perfiles. */
function administradorPrincipalFoto(User $principal): array
{
    return [
        DB::table('users')->where('id', $principal->id)->first(['estado_id', 'email', 'persona_id', 'administrador_principal']),
        DB::table('personas')->where('id', $principal->persona_id)->first(),
        DB::table('usuario_perfil')->where('usuario_id', $principal->id)->orderBy('perfil_acceso_id')->pluck('perfil_acceso_id')->all(),
    ];
}

/** Corre $paso como si fuera dentro de un pedido web del actor (servicio o modelo llamado desde la aplicación). */
function administradorPrincipalEnPedidoWeb(User $actor, Closure $paso): mixed
{
    test()->actingAs($actor);
    $contexto = app(ContextoAuditoria::class);
    $contexto->pedidoWeb = true;
    try {
        return $paso();
    } finally {
        $contexto->pedidoWeb = false;
    }
}

/** El actor por nombre: el segundo administrador o el propio principal. */
function administradorPrincipalActor(string $actor): User
{
    return $actor === 'segundo' ? test()->segundo : test()->principal;
}

describe('a) estado', function () {
    test('no se desactiva ni se bloquea: formulario, servicio y modelo', function (string $actor) {
        $quien = administradorPrincipalActor($actor);
        $foto = administradorPrincipalFoto($this->principal);
        $this->actingAs($quien);

        $this->from(route('admin.usuarios.index'))->patch(route('admin.usuarios.desactivar', $this->principal))->assertSessionHas('error', AdministradorPrincipalInamovible::MENSAJE);
        $this->from(route('admin.usuarios.index'))->put(route('admin.usuarios.update', $this->principal), ['perfiles' => $this->principal->perfiles->modelKeys(), 'estado_id' => Estado::idDe(Estado::BLOQUEADO)])
            ->assertSessionHas('error', AdministradorPrincipalInamovible::MENSAJE);
        if ($actor === 'principal') {
            $this->from(route('profile.edit'))->patch(route('profile.desactivar'), ['password' => 'password'])
                ->assertSessionHasErrorsIn('desactivarCuenta', ['cuenta' => AdministradorPrincipalInamovible::MENSAJE]);
        }

        administradorPrincipalEnPedidoWeb($quien, function () {
            expect(fn () => $this->principal->fresh()->desactivar())->toThrow(AdministradorPrincipalInamovible::class)
                ->and(fn () => $this->principal->fresh()->update(['estado_id' => Estado::idDe(Estado::BLOQUEADO)]))->toThrow(AdministradorPrincipalInamovible::class);
        });

        expect(administradorPrincipalFoto($this->principal))->toEqual($foto);
        $this->assertAuthenticated();
    })->with(['segundo', 'principal']);

    test('la consola queda afuera: desde ella sí se puede (como las otras guardas)', function () {
        $this->principal->fresh()->update(['estado_id' => Estado::idDe(Estado::BLOQUEADO)]);

        expect($this->principal->fresh()->estado->codigo)->toBe(Estado::BLOQUEADO);
    });
});

describe('b) perfiles', function () {
    test('no se le asignan ni se le quitan perfiles: formulario, servicio y modelo', function (string $actor) {
        $quien = administradorPrincipalActor($actor);
        $admin = PerfilAcceso::where('codigo', 'ADMINISTRADOR')->value('id');
        $foto = administradorPrincipalFoto($this->principal);
        $this->actingAs($quien);

        foreach ([[$admin, $this->recepcion->id], [$this->recepcion->id], []] as $perfiles) {
            $this->from(route('admin.usuarios.edit', $this->principal))->put(route('admin.usuarios.update', $this->principal), ['perfiles' => $perfiles, 'estado_id' => Estado::idDe(Estado::ACTIVO)])
                ->assertSessionHas('error', AdministradorPrincipalInamovible::MENSAJE);
        }

        administradorPrincipalEnPedidoWeb($quien, function () use ($admin) {
            $principal = $this->principal->fresh();
            expect(fn () => $principal->sincronizarAuditado('perfiles', [$this->recepcion->id]))->toThrow(AdministradorPrincipalInamovible::class)
                ->and(fn () => $principal->perfiles()->attach($this->recepcion->id))->toThrow(AdministradorPrincipalInamovible::class)
                ->and(fn () => $principal->perfiles()->detach($admin))->toThrow(AdministradorPrincipalInamovible::class)
                // Y el perfil que tiene (el Administrador) no se desactiva.
                ->and(fn () => PerfilAcceso::find($admin)->desactivar())->toThrow(AdministradorPrincipalInamovible::class);
        });

        expect(administradorPrincipalFoto($this->principal))->toEqual($foto)
            ->and($this->principal->fresh()->esAdministrador())->toBeTrue();
    })->with(['segundo', 'principal']);

    test('la consola queda afuera: desde ella sí se le cambian los perfiles', function () {
        $this->principal->perfiles()->attach($this->recepcion->id);

        expect($this->principal->fresh()->perfiles->pluck('codigo')->all())->toContain('RECEPCION', 'ADMINISTRADOR');
    });

    test('el formulario del principal no ofrece casillas, estado ni invitación; el listado lo marca y no ofrece desactivarlo', function () {
        $this->actingAs($this->segundo);

        $this->get(route('admin.usuarios.edit', $this->principal))->assertOk()
            ->assertSee('Es el administrador principal y no puede modificarse.')
            ->assertDontSee('name="perfiles[]"', false)->assertDontSee('name="estado_id"', false)
            ->assertDontSee(route('admin.usuarios.invitacion', $this->principal));
        $this->get(route('admin.usuarios.index'))->assertOk()
            ->assertSeeInOrder(['Ríos, Aurelio', 'Administrador principal'])
            ->assertDontSee(route('admin.usuarios.desactivar', $this->principal));
        // A otro administrador (que no es principal ni uno mismo) sí se le ofrece desactivarlo.
        $otro = User::factory()->administrador()->create();
        $this->get(route('admin.usuarios.index'))->assertSee(route('admin.usuarios.desactivar', $otro));
    });
});

describe('c) persona', function () {
    test('su persona no se desactiva: formulario y modelo', function (string $actor) {
        $quien = administradorPrincipalActor($actor);
        $foto = administradorPrincipalFoto($this->principal);
        $this->actingAs($quien);

        $this->from(route('admin.personas.index'))->patch(route('admin.personas.desactivar', $this->principal->persona))
            ->assertSessionHas('error', AdministradorPrincipalInamovible::MENSAJE);
        $this->get(route('admin.personas.index'))->assertDontSee(route('admin.personas.desactivar', $this->principal->persona));
        administradorPrincipalEnPedidoWeb($quien, fn () => expect(fn () => $this->principal->persona->fresh()->desactivar())->toThrow(AdministradorPrincipalInamovible::class));

        expect(administradorPrincipalFoto($this->principal))->toEqual($foto);
    })->with(['segundo', 'principal']);

    test('la consola queda afuera: desde ella sí se desactiva su persona', function () {
        $this->principal->persona->desactivar();

        expect($this->principal->persona->fresh()->estaActivo())->toBeFalse();
    });
});

describe('d) datos de su persona e invitaciones', function () {
    /** Lo que manda el formulario de Persona con los datos actuales y cambios. */
    function administradorPrincipalFormularioPersona(Persona $persona, array $cambios = []): array
    {
        return [
            'tipo_persona' => 'FISICA', 'tipo_documento_id' => $persona->tipo_documento_id, 'nro_documento' => $persona->nro_documento,
            'apellidos' => $persona->apellidos, 'nombres' => $persona->nombres, 'fecha_nacimiento' => $persona->fecha_nacimiento?->format('d/m/Y'),
            'sexo' => $persona->sexo, 'email' => $persona->email, 'telefono' => $persona->telefono, 'direccion' => $persona->direccion,
            'estado_id' => $persona->estado_id, ...$cambios,
        ];
    }

    test('otro (un segundo administrador, o Recepción con permiso sobre Personas) no edita sus datos ni le manda una invitación', function (string $actor) {
        $quien = $actor === 'segundo' ? $this->segundo : User::factory()->conPerfiles([$this->recepcion])->create();
        $foto = administradorPrincipalFoto($this->principal);
        $persona = $this->principal->persona;
        $this->actingAs($quien);

        $this->get(route('admin.personas.edit', $persona))->assertRedirect(route('admin.personas.index'))->assertSessionHas('error', AdministradorPrincipalInamovible::MENSAJE);
        $this->get(route('admin.personas.index'))->assertDontSee(route('admin.personas.edit', $persona));
        $this->from(route('admin.personas.index'))->put(route('admin.personas.update', $persona), administradorPrincipalFormularioPersona($persona, ['email' => 'otro.correo@clinexa.test']))
            ->assertSessionHas('error', AdministradorPrincipalInamovible::MENSAJE);

        administradorPrincipalEnPedidoWeb($quien, function () use ($persona) {
            expect(fn () => $persona->fresh()->update(['email' => 'otro.correo@clinexa.test']))->toThrow(AdministradorPrincipalInamovible::class)
                ->and(fn () => $persona->fresh()->update(['nombres' => 'Otro']))->toThrow(AdministradorPrincipalInamovible::class)
                ->and(fn () => $this->principal->fresh()->update(['email' => 'otro.correo@clinexa.test']))->toThrow(AdministradorPrincipalInamovible::class)
                ->and(fn () => $this->principal->fresh()->enviarLinkContrasena())->toThrow(AdministradorPrincipalInamovible::class);
        });
        if ($actor === 'segundo') {
            $this->from(route('admin.usuarios.index'))->post(route('admin.usuarios.invitacion', $this->principal))->assertSessionHas('error', AdministradorPrincipalInamovible::MENSAJE);
        }

        expect(administradorPrincipalFoto($this->principal))->toEqual($foto);
        Notification::assertNothingSent();
        expect(DB::table('password_reset_tokens')->count())->toBe(0);
    })->with(['segundo', 'recepción']);

    test('el propio principal edita sus datos y cambia su contraseña; la invitación desde Usuarios, tampoco a sí mismo', function () {
        $persona = $this->principal->persona;
        $this->actingAs($this->principal);

        $this->get(route('admin.personas.edit', $persona))->assertOk();
        $this->put(route('admin.personas.update', $persona), administradorPrincipalFormularioPersona($persona, ['telefono' => '0981 555 777', 'email' => 'aurelio.rios@clinexa.test']))
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        expect($persona->fresh()->telefono)->toBe('0981 555 777')->and($this->principal->fresh()->email)->toBe('aurelio.rios@clinexa.test');

        $this->from(route('profile.edit'))->put(route('password.update'), ['current_password' => 'password', 'password' => 'Nueva$Clave2026', 'password_confirmation' => 'Nueva$Clave2026'])
            ->assertSessionHasNoErrors();
        expect(\Illuminate\Support\Facades\Hash::check('Nueva$Clave2026', $this->principal->fresh()->password))->toBeTrue();

        $this->from(route('admin.usuarios.index'))->post(route('admin.usuarios.invitacion', $this->principal))->assertSessionHas('error', AdministradorPrincipalInamovible::MENSAJE);
        // Su camino para recuperar la contraseña es "¿Olvidó su contraseña?": el link le llega a SU correo.
        auth()->logout();
        $this->post(route('password.email'), ['email' => 'aurelio.rios@clinexa.test'])->assertSessionHasNoErrors();
        Notification::assertSentTo($this->principal->fresh(), \Illuminate\Auth\Notifications\ResetPassword::class);
    });

    test('la consola queda afuera: desde ella sí se cambian sus datos', function () {
        $this->principal->persona->update(['telefono' => '0971 000 111']);

        expect($this->principal->persona->fresh()->telefono)->toBe('0971 000 111');
    });
});

describe('segundo administrador y el perfil Administrador', function () {
    test('un segundo administrador modifica a otro administrador que no es principal (sin tocarle el perfil Administrador)', function () {
        $otro = User::factory()->administrador()->create();
        $admin = PerfilAcceso::where('codigo', 'ADMINISTRADOR')->value('id');
        $this->actingAs($this->segundo);

        $this->put(route('admin.usuarios.update', $otro), ['perfiles' => [$admin, $this->recepcion->id], 'estado_id' => Estado::idDe(Estado::BLOQUEADO)])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        expect($otro->fresh()->estado->codigo)->toBe(Estado::BLOQUEADO)
            ->and($otro->fresh()->perfiles->modelKeys())->toEqualCanonicalizing([$admin, $this->recepcion->id]);
        $this->patch(route('admin.usuarios.desactivar', $otro))->assertSessionMissing('error');
        expect($otro->fresh()->estado->codigo)->toBe(Estado::INACTIVO);
    });

    test('el segundo no se desactiva ni cambia sus propios perfiles, como cualquiera', function () {
        $this->actingAs($this->segundo);

        $this->from(route('admin.usuarios.index'))->patch(route('admin.usuarios.desactivar', $this->segundo))->assertSessionHas('error', 'No puede desactivar su propio usuario.');
        $this->put(route('admin.usuarios.update', $this->segundo), ['perfiles' => [$this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHas('error', \App\Exceptions\PerfilesPropios::MENSAJE);
        expect($this->segundo->fresh()->esAdministrador())->toBeTrue();
    });

    test('solo el principal asigna o quita el perfil Administrador: formulario y modelo', function () {
        $admin = PerfilAcceso::where('codigo', 'ADMINISTRADOR')->sole();
        $usuario = User::factory()->conPerfiles([$this->recepcion])->create();
        $otroAdmin = User::factory()->administrador()->create();

        // El segundo: ni asignarlo, ni quitarlo, ni al crear un usuario.
        $this->actingAs($this->segundo);
        $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [$admin->id, $this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHasErrors(['perfiles' => AdministradorPrincipal::SOLO_EL_PRINCIPAL]);
        $this->put(route('admin.usuarios.update', $otroAdmin), ['perfiles' => [$this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])
            ->assertSessionHasErrors(['perfiles' => AdministradorPrincipal::SOLO_EL_PRINCIPAL]);
        $this->post(route('admin.usuarios.store'), ['persona_id' => Persona::factory()->create()->id, 'perfiles' => [$admin->id]])
            ->assertSessionHasErrors(['perfiles' => AdministradorPrincipal::SOLO_EL_PRINCIPAL]);
        administradorPrincipalEnPedidoWeb($this->segundo, fn () => expect(fn () => $usuario->perfiles()->attach($admin->id))->toThrow(AccionRechazada::class, AdministradorPrincipal::SOLO_EL_PRINCIPAL));
        // En su formulario, la casilla Administrador está fija.
        expect($this->get(route('admin.usuarios.edit', $usuario))->getContent())->toMatch('/name="perfiles\[\]" value="'.$admin->id.'"[^>]*\sdisabled[\s>]/');
        expect($usuario->fresh()->esAdministrador())->toBeFalse()->and($otroAdmin->fresh()->esAdministrador())->toBeTrue();

        // El principal, sí.
        $this->actingAs($this->principal);
        $this->put(route('admin.usuarios.update', $usuario), ['perfiles' => [$admin->id, $this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])->assertSessionHasNoErrors();
        $this->put(route('admin.usuarios.update', $otroAdmin), ['perfiles' => [$this->recepcion->id], 'estado_id' => Estado::idDe(Estado::ACTIVO)])->assertSessionHasNoErrors();
        expect($usuario->fresh()->esAdministrador())->toBeTrue()->and($otroAdmin->fresh()->esAdministrador())->toBeFalse();
        expect(AdministradorPrincipal::SOLO_EL_PRINCIPAL_ASIGNA_ADMINISTRADOR)->toBeTrue();
    });

    test('el último administrador sigue de respaldo (sin principal, p. ej. en una instalación nueva)', function () {
        $this->principal->forceFill(['administrador_principal' => false])->save(); // desde la consola
        DB::table('usuario_perfil')->where('usuario_id', $this->principal->id)->delete();
        $gestor = User::factory()->conPermisos(['USUARIOS' => ['VER', 'EDITAR', 'DESACTIVAR']])->create();

        $this->actingAs($gestor)->from(route('admin.usuarios.index'))->patch(route('admin.usuarios.desactivar', $this->segundo))
            ->assertSessionHas('error', \App\Exceptions\UltimoAdministrador::MENSAJE);
        expect($this->segundo->fresh()->estaActivo())->toBeTrue();
    });
});

describe('la marca', function () {
    test('no se asigna por mass assignment ni desde el modelo en un pedido web', function () {
        $usuario = User::factory()->create();

        $usuario->fill(['administrador_principal' => true]);
        expect($usuario->isDirty('administrador_principal'))->toBeFalse();
        expect(fn () => User::create(['persona_id' => Persona::factory()->create()->id, 'email' => 'x@clinexa.test', 'password' => 'x', 'administrador_principal' => true]))
            ->not->toThrow(Throwable::class);
        expect(User::where('email', 'x@clinexa.test')->sole()->administrador_principal)->toBeFalse();

        administradorPrincipalEnPedidoWeb($this->principal, function () use ($usuario) {
            expect(fn () => $usuario->forceFill(['administrador_principal' => true])->save())->toThrow(AccionRechazada::class, AdministradorPrincipal::MARCA_SOLO_POR_CONSOLA)
                ->and(fn () => $this->principal->fresh()->forceFill(['administrador_principal' => false])->save())->toThrow(AccionRechazada::class);
        });
        expect(User::where('administrador_principal', true)->pluck('id')->all())->toBe([$this->principal->id]);
    });

    test('a lo sumo uno: el modelo lo rechaza y el índice único parcial también', function () {
        expect(fn () => $this->segundo->fresh()->forceFill(['administrador_principal' => true])->save())->toThrow(LogicException::class, AdministradorPrincipal::YA_HAY_OTRO);
        expect(fn () => DB::table('users')->where('id', $this->segundo->id)->update(['administrador_principal' => true]))
            ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
        // Muchos en false, sin problema (el índice es parcial).
        User::factory()->count(3)->create();
        expect(User::where('administrador_principal', false)->count())->toBeGreaterThan(3);
    });
});

describe('backfill de la migración', function () {
    beforeEach(fn () => $this->migracion = require database_path('migrations/2026_10_12_100001_administrador_principal.php'));

    test('exactamente uno: marca al usuario de esa CI con el perfil Administrador', function () {
        DB::table('users')->update(['administrador_principal' => false]);

        $this->migracion->marcar('7100002');

        expect(User::where('administrador_principal', true)->pluck('id')->all())->toBe([$this->segundo->id]);
    });

    test('ninguno (CI inexistente, o la persona sin el perfil Administrador): falla con un mensaje claro y no marca nada', function (string $documento) {
        User::factory()->conPersona(['nro_documento' => '7100009'])->conPerfiles([$this->recepcion])->create();
        DB::table('users')->update(['administrador_principal' => false]);

        expect(fn () => $this->migracion->marcar($documento))
            ->toThrow(RuntimeException::class, "Administrador principal: se esperaba exactamente un usuario con el perfil Administrador y la CI {$documento}, y hay 0. No se marcó a nadie.");
        expect(User::where('administrador_principal', true)->count())->toBe(0);
    })->with(['9999999', '7100009']);

    test('dos (la base ya lo impide con sus índices únicos; la migración igual lo frena)', function () {
        // Para armar el caso, sin los índices únicos de persona y de usuario.
        DB::statement('DROP INDEX personas_tipo_documento_id_nro_documento_unique');
        DB::statement('DROP INDEX users_persona_id_unique');
        DB::table('users')->update(['administrador_principal' => false]);
        DB::table('personas')->where('id', $this->segundo->persona_id)->update(['nro_documento' => '7100001']);

        expect(fn () => $this->migracion->marcar('7100001'))->toThrow(RuntimeException::class, 'y hay 2. No se marcó a nadie.');
        expect(User::where('administrador_principal', true)->count())->toBe(0);
    });

    test('una base sin usuarios (instalación nueva) no marca a nadie ni falla', function () {
        DB::table('usuario_perfil')->delete();
        DB::table('users')->delete();

        $this->migracion->marcar('7100001');

        expect(DB::table('users')->count())->toBe(0);
    });
});

describe('traspaso (clinexa:traspasar-principal)', function () {
    test('con la confirmación correcta: mueve la marca en una transacción y deja un evento de auditoría', function () {
        $this->artisan('clinexa:traspasar-principal', ['email' => $this->segundo->email])
            ->expectsQuestion('Para confirmar, escriba el correo del nuevo administrador principal', mb_strtoupper($this->segundo->email))
            ->assertSuccessful();

        expect(User::where('administrador_principal', true)->pluck('id')->all())->toBe([$this->segundo->id]);
        $evento = LogAuditoria::where('tabla_afectada', 'users')->where('registro_afectado_id', (string) $this->segundo->id)->sole();
        expect($evento)->accion->value->toBe('EDITAR')->usuario_id->toBeNull()
            ->valor_anterior->toBe(['administrador_principal' => false])->valor_nuevo->toBe(['administrador_principal' => true])
            ->detalle->toBe("Traspaso del administrador principal por consola (antes: usuario #{$this->principal->id}).");
    });

    test('sin confirmación, o con otro correo: no traspasa nada', function (?string $respuesta) {
        $this->artisan('clinexa:traspasar-principal', ['email' => $this->segundo->email])
            ->expectsQuestion('Para confirmar, escriba el correo del nuevo administrador principal', $respuesta)
            ->expectsOutput('El correo no coincide: no se traspasó nada.')
            ->assertFailed();

        expect(User::where('administrador_principal', true)->pluck('id')->all())->toBe([$this->principal->id])
            ->and(LogAuditoria::count())->toBe(0);
    })->with([null, 'otro@clinexa.test']);

    test('exige que el nuevo esté activo y tenga el perfil Administrador', function (Closure $preparar, string $mensaje) {
        $nuevo = $preparar($this);

        $this->artisan('clinexa:traspasar-principal', ['email' => $nuevo->email])
            ->expectsQuestion('Para confirmar, escriba el correo del nuevo administrador principal', $nuevo->email)
            ->expectsOutput("{$mensaje} No se traspasó nada.")
            ->assertFailed();

        expect(User::where('administrador_principal', true)->pluck('id')->all())->toBe([$this->principal->id]);
    })->with([
        'sin el perfil Administrador' => [fn ($t) => User::factory()->conPerfiles([$t->recepcion])->create(), 'El nuevo administrador principal tiene que tener el perfil Administrador.'],
        'bloqueado' => [fn ($t) => tap($t->segundo)->update(['estado_id' => Estado::idDe(Estado::BLOQUEADO)]), 'El nuevo administrador principal tiene que estar activo (usuario y persona).'],
        'ya es el principal' => [fn ($t) => $t->principal, 'Ese usuario ya es el administrador principal.'],
    ]);

    test('es la única vía: no hay ruta web que mueva la marca', function () {
        $rutas = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(fn ($ruta) => $ruta->uri().' '.$ruta->getActionName())->filter(fn ($r) => str_contains(mb_strtolower($r), 'principal'));

        expect($rutas->all())->toBe([]);
    });
});

describe('auditoría', function () {
    test('un intento rechazado de modificar al principal no genera cambios ni eventos', function () {
        $foto = administradorPrincipalFoto($this->principal);
        $this->actingAs($this->segundo);

        $this->patch(route('admin.usuarios.desactivar', $this->principal));
        $this->put(route('admin.usuarios.update', $this->principal), ['perfiles' => [$this->recepcion->id], 'estado_id' => Estado::idDe(Estado::BLOQUEADO)]);
        $this->patch(route('admin.personas.desactivar', $this->principal->persona));
        $this->put(route('admin.personas.update', $this->principal->persona), ['email' => 'otro@clinexa.test']);
        $this->post(route('admin.usuarios.invitacion', $this->principal));

        expect(administradorPrincipalFoto($this->principal))->toEqual($foto)
            ->and(LogAuditoria::whereIn('tabla_afectada', ['users', 'personas'])->exists())->toBeFalse();
    });
});
