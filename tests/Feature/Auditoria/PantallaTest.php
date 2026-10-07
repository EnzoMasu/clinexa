<?php

use App\Enums\AccionAuditoria;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\User;
use App\Support\Auditoria;
use Database\Seeders\ModulosSensiblesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->administrador()->conPersona(['apellidos' => 'Masuzzo', 'nombres' => 'Enzo'])->create();
    $this->otro = User::factory()->conPersona(['apellidos' => 'Ruiz', 'nombres' => 'Liz'])->create();
    $this->seed(ModulosSensiblesSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

/** Un registro con fecha y usuario dados (directo a la tabla, como lo escribe Auditoria). */
function evento(AccionAuditoria $accion, string $tabla, ?int $usuarioId, string $fechaUtc, array $extra = []): void
{
    DB::table('logs_auditoria')->insert([
        'usuario_id' => $usuarioId, 'tabla_afectada' => $tabla, 'accion' => $accion->value,
        'fecha_hora' => $fechaUtc, 'ip_origen' => '10.0.0.5', ...$extra,
    ]);
}

/** Texto de la tabla del listado (solo las filas), pedido como la búsqueda en vivo. */
function filas(array $filtros = []): string
{
    return test()->get(route('admin.auditoria.index', $filtros), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
}

test('sin permiso VER sobre Auditoría: 403 en el listado y en el detalle; no hay rutas de escritura', function () {
    evento(AccionAuditoria::INICIO_SESION, 'users', $this->otro->id, '2026-10-06 12:00:00');
    $id = LogAuditoria::value('id');

    $this->actingAs(User::factory()->conPermisos(['USUARIOS' => ['VER', 'EDITAR']])->create());
    $this->get(route('admin.auditoria.index'))->assertForbidden();
    $this->get(route('admin.auditoria.show', $id))->assertForbidden();

    $this->actingAs($this->admin);
    $this->post('/admin/auditoria')->assertStatus(405);
    $this->put("/admin/auditoria/{$id}")->assertStatus(405);
    $this->delete("/admin/auditoria/{$id}")->assertStatus(405);
    expect(LogAuditoria::count())->toBeGreaterThanOrEqual(1);
});

test('con permiso VER entra; el menú la muestra en Seguridad', function () {
    $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER']])->create());

    $this->get(route('admin.auditoria.index'))->assertOk()->assertSee('Auditoría');
    $this->get('/dashboard')->assertSee(route('admin.auditoria.index'))->assertSee('data-grupo-menu="Seguridad"', false);
});

test('el listado muestra fecha y hora de Paraguay, usuario (o el correo intentado), acción, módulo, registro e IP; el más reciente primero', function () {
    evento(AccionAuditoria::EDITAR, 'personas', $this->otro->id, '2026-10-06 11:00:00', ['registro_afectado_id' => '7']);
    evento(AccionAuditoria::INICIO_SESION_FALLIDO, 'users', null, '2026-10-06 12:30:00', ['detalle' => 'Correo: nadie@example.com. El correo no corresponde a ningún usuario.']);

    $this->actingAs($this->admin);
    $html = filas();

    expect($html)->toContain('06/10/2026 09:30')->toContain('06/10/2026 08:00'); // UTC-3
    $this->get(route('admin.auditoria.index'), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertSeeInOrder(['06/10/2026 09:30', 'nadie@example.com', 'Inicio de sesión fallido', 'Usuarios',
            '06/10/2026 08:00', 'Ruiz, Liz', 'Editar', 'Personas', '7', '10.0.0.5']);
});

test('filtros: usuario, sin usuario, acción, módulo, rango de fechas (días de Paraguay) y texto', function () {
    evento(AccionAuditoria::EDITAR, 'personas', $this->otro->id, '2026-10-05 02:30:00', ['registro_afectado_id' => '7']); // 04/10 23:30 en Paraguay
    evento(AccionAuditoria::CREAR, 'especialidades', $this->admin->id, '2026-10-05 15:00:00', ['registro_afectado_id' => '3']);
    evento(AccionAuditoria::INICIO_SESION_FALLIDO, 'users', null, '2026-10-06 12:00:00', ['detalle' => 'Correo: nadie@example.com. El correo no corresponde a ningún usuario.']);
    $this->actingAs($this->admin);

    $cuenta = fn (array $filtros) => substr_count(filas($filtros), 'Ver detalle');

    expect($cuenta([]))->toBe(3)
        ->and($cuenta(['usuario' => $this->otro->id]))->toBe(1)
        ->and($cuenta(['usuario' => 'ninguno']))->toBe(1)
        ->and($cuenta(['accion' => 'CREAR']))->toBe(1)
        ->and($cuenta(['modulo' => 'PERSONAS']))->toBe(1)
        ->and($cuenta(['modulo' => 'USUARIOS']))->toBe(1)
        // El evento de las 02:30 UTC del 05/10 es del 04/10 en Paraguay.
        ->and($cuenta(['desde' => '05/10/2026', 'hasta' => '05/10/2026']))->toBe(1)
        ->and($cuenta(['desde' => '04/10/2026', 'hasta' => '04/10/2026']))->toBe(1)
        ->and($cuenta(['desde' => '05/10/2026']))->toBe(2)
        ->and($cuenta(['q' => 'nadie@']))->toBe(1)
        ->and($cuenta(['q' => '7']))->toBe(1)
        // Valores inválidos se ignoran.
        ->and($cuenta(['accion' => 'ELIMINAR', 'desde' => '2026-10-05']))->toBe(3);
});

test('el detalle muestra campo por campo el valor anterior y el nuevo', function () {
    evento(AccionAuditoria::DESACTIVAR, 'especialidades', $this->admin->id, '2026-10-06 12:00:00', [
        'registro_afectado_id' => '3',
        'valor_anterior' => json_encode(['estado_id' => estadoId('ACTIVO')]),
        'valor_nuevo' => json_encode(['estado_id' => estadoId('INACTIVO')]),
    ]);
    evento(AccionAuditoria::EDITAR, 'perfiles_acceso', $this->admin->id, '2026-10-06 12:01:00', [
        'registro_afectado_id' => '2',
        'valor_anterior' => json_encode(['permisos' => []]),
        'valor_nuevo' => json_encode(['permisos' => ['PERSONAS: VER', 'TURNOS: VER']]),
    ]);
    [$desactivar, $permisos] = LogAuditoria::orderBy('id')->get()->all();
    $this->actingAs($this->admin);

    $this->get(route('admin.auditoria.show', $desactivar))->assertOk()
        ->assertSeeInOrder(['Desactivar', 'Especialidades', 'estado_id', 'ACTIVO ('.estadoId('ACTIVO').')', 'INACTIVO ('.estadoId('INACTIVO').')']);
    $this->get(route('admin.auditoria.show', $permisos))->assertOk()
        ->assertSeeInOrder(['permisos', '(ninguno)', "PERSONAS: VER\nTURNOS: VER"], false);
});

test('consultar el log también queda en el log (VER sobre Auditoría), no en las búsquedas en vivo', function () {
    evento(AccionAuditoria::INICIO_SESION, 'users', $this->otro->id, '2026-10-06 12:00:00');
    $id = LogAuditoria::value('id');
    $this->actingAs($this->admin);

    $this->get(route('admin.auditoria.index'))->assertOk();
    filas(['accion' => 'EDITAR']);
    $this->get(route('admin.auditoria.show', $id))->assertOk();

    expect(LogAuditoria::where('accion', 'VER')->orderBy('id')->get()->map(fn ($log) => [$log->tabla_afectada, $log->registro_afectado_id])->all())
        ->toBe([['logs_auditoria', null], ['logs_auditoria', (string) $id]]);
});

test('matriz de perfiles: en Auditoría solo se asignan VER y EXPORTAR; CREAR se ignora al guardar', function () {
    $this->actingAs($this->admin);
    $auditoria = ModuloSistema::where('codigo', 'AUDITORIA')->sole();
    $perfil = PerfilAcceso::create(['nombre' => 'Auditor']);

    $this->put(route('admin.perfiles-acceso.update', $perfil), [
        'nombre' => 'Auditor', 'estado_id' => estadoId('ACTIVO'), 'permisos' => [$auditoria->id => ['VER', 'CREAR', 'EDITAR']],
    ])->assertSessionHasNoErrors();

    expect($perfil->fresh()->permisos->pluck('accion')->all())->toBe(['VER'])
        ->and(Permiso::accionesDe('AUDITORIA'))->toBe(['VER', 'EXPORTAR'])
        // El Administrador mantiene las 5 en todos los módulos.
        ->and(PerfilAcceso::where('nombre', PerfilAcceso::ADMINISTRADOR)->sole()->permisos()->count())
        ->toBe(ModuloSistema::count() * count(Permiso::ACCIONES));
});

test('rendimiento con 50.000 registros: 20 por página, consultas fijas y rápido', function () {
    $acciones = AccionAuditoria::cases();
    $tablas = array_keys(Auditoria::modulosPorTabla());
    $inicio = Carbon::parse('2026-01-01 00:00:00');
    foreach (array_chunk(range(1, 50000), 1000) as $bloque) {
        DB::table('logs_auditoria')->insert(array_map(fn (int $i) => [
            'usuario_id' => $i % 3 === 0 ? null : ($i % 2 ? $this->admin->id : $this->otro->id),
            'tabla_afectada' => $tablas[$i % count($tablas)],
            'registro_afectado_id' => (string) ($i % 500),
            'accion' => $acciones[$i % count($acciones)]->value,
            'detalle' => $i % 3 === 0 ? "Correo: prueba{$i}@example.com." : null,
            'ip_origen' => '10.0.0.'.($i % 250),
            'fecha_hora' => $inicio->copy()->addMinutes($i)->format('Y-m-d H:i:s'),
        ], $bloque));
    }
    expect(LogAuditoria::count())->toBe(50000);
    $this->actingAs($this->admin);

    foreach ([[], ['usuario' => $this->otro->id], ['accion' => 'EDITAR', 'modulo' => 'PERSONAS'], ['desde' => '01/02/2026', 'hasta' => '15/02/2026'], ['q' => 'prueba123'], ['page' => 1200]] as $filtros) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = microtime(true);
        $html = filas($filtros);
        $segundos = microtime(true) - $t;

        expect(substr_count($html, 'Ver detalle'))->toBeLessThanOrEqual(20)
            ->and(count(DB::getQueryLog()))->toBeLessThan(15)
            ->and($segundos)->toBeLessThan(2.0);
    }
});
