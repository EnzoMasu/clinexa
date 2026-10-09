<?php

use App\Enums\AccionAuditoria;
use App\Models\Especialidad;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\Persona;
use App\Models\User;
use Database\Seeders\ModulosSensiblesSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->admin = User::factory()->administrador()->create();
    $this->seed(ModulosSensiblesSeeder::class);
    $this->actingAs($this->admin);
});

afterEach(fn () => Carbon::setTestNow());

function lecturas()
{
    return LogAuditoria::where('accion', AccionAuditoria::VER->value)->orderBy('id')->get();
}

test('el seeder marca los módulos sensibles sin desmarcar los marcados a mano', function () {
    ModuloSistema::where('codigo', 'TURNOS')->update(['es_sensible' => true]); // a mano

    $this->seed(ModulosSensiblesSeeder::class);

    expect(ModuloSistema::where('es_sensible', true)->orderBy('codigo')->pluck('codigo')->all())
        ->toBe(['AUDITORIA', 'HISTORIA_CLINICA', 'PACIENTES', 'PERFILES_ACCESO', 'PERSONAS', 'RECETAS', 'TURNOS', 'USUARIOS']);
});

test('abrir el listado de un módulo sensible registra un VER sin registro', function () {
    $this->get(route('admin.personas.index'))->assertOk();

    expect(lecturas()->sole())
        ->tabla_afectada->toBe('personas')->registro_afectado_id->toBeNull()->usuario_id->toBe($this->admin->id);
});

test('abrir la edición de un registro registra un VER con ese registro', function () {
    $persona = Persona::factory()->create();

    $this->get(route('admin.personas.edit', $persona))->assertOk();
    $this->get(route('admin.usuarios.edit', $this->admin))->assertOk();

    expect(lecturas()->map(fn ($log) => [$log->tabla_afectada, $log->registro_afectado_id])->all())
        ->toBe([['personas', (string) $persona->id], ['users', (string) $this->admin->id]]);
});

test('no se registra en módulos no sensibles, ni en formularios de alta', function () {
    $especialidad = Especialidad::create(['nombre' => 'Cardiología']);

    $this->get(route('admin.especialidades.index'))->assertOk();
    $this->get(route('admin.especialidades.edit', $especialidad))->assertOk();
    $this->get(route('admin.personas.create'))->assertOk();

    expect(lecturas())->toBeEmpty();
});

test('no se registra en las peticiones AJAX: búsqueda en vivo, paginación, buscadores', function () {
    $this->get(route('admin.personas.index', ['q' => 'ana']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    $this->get(route('admin.usuarios.index', ['page' => 2]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    $this->getJson(route('admin.pacientes.personas-disponibles', ['q' => 'ana']))->assertOk();
    $this->getJson(route('admin.usuarios.personas-disponibles', ['q' => 'ana']))->assertOk();
    $this->getJson(route('verificar-unico', ['campo' => 'persona.documento', 'valor' => '1234']))->assertOk();

    expect(lecturas())->toBeEmpty();
});

test('no se repite un VER idéntico dentro de 5 minutos', function () {
    Carbon::setTestNow('2026-10-06 12:00:00');
    $persona = Persona::factory()->create();

    $this->get(route('admin.personas.index'));
    $this->get(route('admin.personas.index'));
    $this->get(route('admin.personas.edit', $persona));
    $this->get(route('admin.personas.edit', $persona));
    expect(lecturas())->toHaveCount(2);

    Carbon::setTestNow('2026-10-06 12:04:59');
    $this->get(route('admin.personas.index'));
    expect(lecturas())->toHaveCount(2);

    Carbon::setTestNow('2026-10-06 12:05:30');
    $this->get(route('admin.personas.index'));
    expect(lecturas())->toHaveCount(3);

    // Otro usuario sí se registra aunque sea dentro de los 5 minutos.
    $this->actingAs(User::factory()->administrador()->create())->get(route('admin.personas.index'));
    expect(lecturas())->toHaveCount(4);
});

test('una página que no se pudo mostrar (sin permiso o inexistente) no registra lectura', function () {
    $this->get(route('admin.personas.edit', 999999))->assertNotFound();

    $this->actingAs(User::factory()->conPermisos([])->create())->get(route('admin.personas.index'))->assertForbidden();

    expect(lecturas())->toBeEmpty();
});
