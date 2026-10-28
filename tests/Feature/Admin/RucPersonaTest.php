<?php

/*
 * RUC de la persona (docs/ruc.md): obligatorio para la jurídica (que no tiene CI ni pasaporte), opcional para la física, con el
 * dígito verificador del módulo 11 de la DNIT, único, y apagable con clinexa.validar_digito_ruc. Lo ya guardado no se rechaza: se
 * marca "falta RUC" / "falta CI". La migración traslada el RUC que estaba como documento sin tocar el documento. Prefijo "rucPersona".
 */

use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Support\Ruc;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());

    $this->ci = TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
    $this->ruc = TipoDocumento::create(['codigo' => 'RUC', 'nombre' => 'RUC']);
    $this->paraguay = Pais::firstOrCreate(['nombre' => 'Paraguay'], ['codigo' => 'PY']);
    ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI', 'RUC']);
});

function rucPersonaFisica(array $cambios = []): array
{
    return [
        'tipo_persona' => 'FISICA', 'tipo_documento_id' => test()->ci->id, 'nro_documento' => '1234567',
        'apellidos' => 'González', 'nombres' => 'María', 'fecha_nacimiento' => '1990-05-10', 'pais_nacionalidad_id' => test()->paraguay->id,
        'email' => 'maria@example.com', 'telefono' => '0981 000 000', 'direccion' => 'Asunción', ...$cambios,
    ];
}

function rucPersonaJuridica(array $cambios = []): array
{
    return [
        'tipo_persona' => 'JURIDICA', 'ruc' => '80080099-0', 'razon_social' => 'Laboratorio Central S.A.',
        'email' => 'info@labcentral.com', 'telefono' => '021 000 000', 'direccion' => 'Asunción', ...$cambios,
    ];
}

function rucPersonaGuardar(array $datos)
{
    return test()->post(route('admin.personas.store'), enFormulario($datos));
}

test('los casos verificados a mano dan lo esperado', function () {
    expect(Ruc::esValido('44444401-7'))->toBeTrue()
        ->and(Ruc::esValido('80080099-0'))->toBeTrue()
        ->and(Ruc::esValido('44444401-6'))->toBeFalse()
        ->and(Ruc::esValido('80080099-1'))->toBeFalse()
        ->and(Ruc::conDigito('9000007'))->toBe('9000007-2')
        ->and(Ruc::esValido('1234567'))->toBeFalse();
});

test('después de 10 dígitos los pesos vuelven a empezar en 2', function () {
    // 12345678901: de derecha a izquierda 1,0,9,8,7,6,5,4,3,2,1 con pesos 2..11 y 2.
    $suma = 1 * 2 + 0 * 3 + 9 * 4 + 8 * 5 + 7 * 6 + 6 * 7 + 5 * 8 + 4 * 9 + 3 * 10 + 2 * 11 + 1 * 2;
    $resto = $suma % 11;

    expect(Ruc::digito('12345678901'))->toBe($resto > 1 ? 11 - $resto : 0);
});

test('jurídica sin RUC: rechazada', function () {
    rucPersonaGuardar(rucPersonaJuridica(['ruc' => '']))->assertSessionHasErrors(['ruc' => 'Una persona jurídica necesita su RUC.']);

    expect(Persona::count())->toBe(1); // solo la del administrador de prueba
});

test('jurídica con CI: rechazada', function () {
    rucPersonaGuardar(rucPersonaJuridica(['tipo_documento_id' => $this->ci->id, 'nro_documento' => '1234567']))
        ->assertSessionHasErrors(['tipo_documento_id', 'nro_documento']);
});

test('jurídica con RUC válido: aceptada, sin documento', function () {
    rucPersonaGuardar(rucPersonaJuridica())->assertSessionHasNoErrors();

    expect(Persona::where('ruc', '80080099-0')->sole())->tipo_documento_id->toBeNull()->nro_documento->toBeNull();
});

test('RUC con dígito inválido: rechazado, y los dos casos verificados válidos: aceptados', function () {
    rucPersonaGuardar(rucPersonaJuridica(['ruc' => '80080099-1']))->assertSessionHasErrors(['ruc' => 'El dígito verificador del RUC no es correcto. Revise el número.']);
    rucPersonaGuardar(rucPersonaJuridica(['ruc' => '44444401-6']))->assertSessionHasErrors('ruc');

    rucPersonaGuardar(rucPersonaJuridica(['ruc' => '44444401-7']))->assertSessionHasNoErrors();
    rucPersonaGuardar(rucPersonaJuridica(['ruc' => '80080099-0', 'razon_social' => 'Otra S.A.']))->assertSessionHasNoErrors();
});

test('el formato del RUC: 6 a 8 dígitos, guion y un dígito', function (string $ruc) {
    rucPersonaGuardar(rucPersonaJuridica(['ruc' => $ruc]))->assertSessionHasErrors('ruc');
})->with(['sin guion' => '800800990', 'muy corto' => '12345-6', 'muy largo' => '123456789-1', 'con letra' => '8008009A-0', 'dos dígitos' => '80080099-10']);

test('física con CI y sin RUC: aceptada', function () {
    rucPersonaGuardar(rucPersonaFisica())->assertSessionHasNoErrors();

    expect(Persona::where('nro_documento', '1234567')->sole())->ruc->toBeNull();
});

test('física con RUC: se valida igual, y no tiene que coincidir con la CI', function () {
    rucPersonaGuardar(rucPersonaFisica(['ruc' => '44444401-6']))->assertSessionHasErrors('ruc');

    rucPersonaGuardar(rucPersonaFisica(['ruc' => '44444401-7']))->assertSessionHasNoErrors();
    expect(Persona::where('nro_documento', '1234567')->sole())->ruc->toBe('44444401-7');
});

test('física sin documento: sigue siendo obligatorio', function () {
    rucPersonaGuardar(rucPersonaFisica(['tipo_documento_id' => '', 'nro_documento' => '', 'ruc' => '44444401-7']))
        ->assertSessionHasErrors(['tipo_documento_id', 'nro_documento']);
});

test('RUC duplicado: rechazado; editar a la misma persona con su RUC, no', function () {
    rucPersonaGuardar(rucPersonaJuridica())->assertSessionHasNoErrors();
    rucPersonaGuardar(rucPersonaJuridica(['razon_social' => 'Copia S.A.']))->assertSessionHasErrors(['ruc' => 'Ya hay una persona registrada con ese RUC.']);
    rucPersonaGuardar(rucPersonaFisica(['ruc' => '80080099-0']))->assertSessionHasErrors('ruc');

    $persona = Persona::where('ruc', '80080099-0')->sole();
    $this->put(route('admin.personas.update', $persona), enFormulario(rucPersonaJuridica(['razon_social' => 'Nuevo nombre S.A.', 'estado_id' => estadoId('ACTIVO')])))
        ->assertSessionHasNoErrors();
    expect($persona->fresh()->razon_social)->toBe('Nuevo nombre S.A.');
});

test('dos personas sin RUC no chocan: el índice único es parcial', function () {
    Persona::factory()->count(2)->create();

    expect(Persona::whereNull('ruc')->count())->toBeGreaterThanOrEqual(2);
});

test('validación del dígito apagada por configuración: el formato se sigue exigiendo', function () {
    config(['clinexa.validar_digito_ruc' => false]);

    rucPersonaGuardar(rucPersonaJuridica(['ruc' => '80080099-1']))->assertSessionHasNoErrors();
    rucPersonaGuardar(rucPersonaJuridica(['ruc' => '8008', 'razon_social' => 'Otra S.A.']))->assertSessionHasErrors('ruc');
});

test('el alta y la edición de una persona con RUC quedan en Auditoría', function () {
    rucPersonaGuardar(rucPersonaJuridica())->assertSessionHasNoErrors();
    $persona = Persona::where('ruc', '80080099-0')->sole();
    $this->put(route('admin.personas.update', $persona), enFormulario(rucPersonaJuridica(['ruc' => '44444401-7', 'estado_id' => estadoId('ACTIVO')])))->assertSessionHasNoErrors();

    $logs = LogAuditoria::where('tabla_afectada', 'personas')->where('registro_afectado_id', $persona->id)->orderBy('id')->get();
    expect($logs->pluck('accion.value')->all())->toBe(['CREAR', 'EDITAR'])
        ->and($logs->last()->valor_nuevo)->toMatchArray(['ruc' => '44444401-7']);
});

test('el listado marca "falta RUC" en la jurídica y "falta CI" en la física con RUC por único documento', function () {
    Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Vieja S.A.', 'apellidos' => null, 'nombres' => null, 'tipo_documento_id' => null, 'nro_documento' => null]);
    Persona::factory()->create(['tipo_documento_id' => $this->ruc->id, 'nro_documento' => '421964-3', 'ruc' => '421964-3', 'apellidos' => 'Zorrilla', 'nombres' => 'Clara']);
    Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Nueva S.A.', 'apellidos' => null, 'nombres' => null, 'tipo_documento_id' => null, 'nro_documento' => null, 'ruc' => '80080099-0']);

    $html = $this->get(route('admin.personas.index'))->assertOk()->assertSee('falta RUC')->assertSee('falta CI')->assertSee('RUC 80080099-0')->assertSee('RUC 421964-3')->getContent();

    expect(substr_count($html, 'falta RUC'))->toBe(1)->and(substr_count($html, 'falta CI'))->toBe(1);
});

test('una persona legacy sin RUC se edita sin romperse y pide el RUC', function () {
    $vieja = Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Vieja S.A.', 'apellidos' => null, 'nombres' => null, 'tipo_documento_id' => $this->ruc->id, 'nro_documento' => '80012345-6']);

    $this->get(route('admin.personas.edit', $vieja))->assertOk();
    $this->put(route('admin.personas.update', $vieja), enFormulario(rucPersonaJuridica(['ruc' => '', 'estado_id' => estadoId('ACTIVO')])))->assertSessionHasErrors('ruc');
    $this->put(route('admin.personas.update', $vieja), enFormulario(rucPersonaJuridica(['estado_id' => estadoId('ACTIVO')])))->assertSessionHasNoErrors();

    // El documento viejo no se toca.
    expect($vieja->fresh())->ruc->toBe('80080099-0')->nro_documento->toBe('80012345-6');
});

test('documentoPrincipal: RUC para la jurídica; CI o pasaporte para la física, o su RUC si no tiene otro', function () {
    $juridica = Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'X S.A.', 'tipo_documento_id' => null, 'nro_documento' => null, 'ruc' => '80080099-0']);
    $fisica = Persona::factory()->create(['nro_documento' => '1234567', 'ruc' => '44444401-7']);
    $soloRuc = Persona::factory()->create(['tipo_documento_id' => $this->ruc->id, 'nro_documento' => '421964-3', 'ruc' => '421964-3']);
    $nada = Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Y S.A.', 'tipo_documento_id' => null, 'nro_documento' => null]);

    expect($juridica->documentoPrincipal())->toBe('RUC 80080099-0')
        ->and($fisica->documentoPrincipal())->toBe('CI 1234567')
        ->and($soloRuc->documentoPrincipal())->toBe('RUC 421964-3')
        ->and($nada->documentoPrincipal())->toBe('');
});

/*
 * Migración: el RUC que estaba como documento pasa al campo nuevo sin tocar el documento; sin el tipo RUC en el catálogo y en
 * --pretend no hace nada; las demás personas quedan intactas.
 */
function rucPersonaMigracion(): object
{
    return require base_path('database/migrations/2026_11_01_100001_ruc_de_la_persona.php');
}

function rucPersonaVolverAntes(): void
{
    // SQLite rehace la tabla al cambiar columnas y no puede soltarla con filas referenciadas (PostgreSQL, que es la real, sí).
    DB::statement('PRAGMA defer_foreign_keys = ON');
    rucPersonaMigracion()->down();
}

function rucPersonaSubir(): void
{
    DB::statement('PRAGMA defer_foreign_keys = ON');
    rucPersonaMigracion()->up();
}

test('la migración traslada el RUC guardado como documento y no toca el documento ni a las demás personas', function () {
    $fisica = Persona::factory()->create(['nro_documento' => '1234567']);
    $luigi = Persona::factory()->create(['tipo_documento_id' => $this->ruc->id, 'nro_documento' => '4441089-1']);
    $aseguradora = Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Seguros S.A.', 'tipo_documento_id' => $this->ruc->id, 'nro_documento' => '80900001-6']);
    $antes = Persona::orderBy('id')->get()->map->getAttributes()->map(fn ($a) => collect($a)->except('ruc', 'updated_at')->all())->all();

    rucPersonaVolverAntes();
    expect(Illuminate\Support\Facades\Schema::hasColumn('personas', 'ruc'))->toBeFalse();
    rucPersonaSubir();

    expect($luigi->fresh())->ruc->toBe('4441089-1')->nro_documento->toBe('4441089-1')->tipo_documento_id->toBe($this->ruc->id)
        ->and($aseguradora->fresh())->ruc->toBe('80900001-6')->nro_documento->toBe('80900001-6')
        ->and($fisica->fresh()->ruc)->toBeNull();
    $despues = Persona::orderBy('id')->get()->map->getAttributes()->map(fn ($a) => collect($a)->except('ruc', 'updated_at')->all())->all();
    expect($despues)->toEqual($antes);
});

test('la migración corre contra un catálogo sin el tipo RUC y en --pretend', function () {
    $fisica = Persona::factory()->create(['nro_documento' => '1234567']);
    DB::table('tipos_documento')->where('codigo', 'RUC')->delete();

    rucPersonaVolverAntes();
    DB::pretend(fn () => rucPersonaMigracion()->up());
    expect(Illuminate\Support\Facades\Schema::hasColumn('personas', 'ruc'))->toBeFalse(); // la simulación no cambia nada

    rucPersonaSubir();
    expect($fisica->fresh()->ruc)->toBeNull()->and(Persona::whereNotNull('ruc')->count())->toBe(0);
});
