<?php

/*
 * Autoguardado (PATCH JSON, AtencionController::autoguardar + App\Support\Atencion\Autoguardado):
 * validación de borrador, filas incompletas, control de versión, estados, permisos, límite de pedidos
 * y auditoría. Lo del navegador (espera de 3 s, un envío cada 10 s, reintentos, carteles) está en
 * resources/js/consulta-autoguardado.js.
 */

use App\Enums\AccionAuditoria;
use App\Models\Consulta;
use App\Models\LogAuditoria;
use App\Support\Atencion\Autoguardado;
use App\Support\Atencion\FormularioConsulta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    hcEscenario();
    $this->consulta = hcEnCurso();
});

afterEach(fn () => Carbon::setTestNow());

function bloque(string $uid, string $contenido, string $id = ''): array
{
    return ['uid' => $uid, 'id' => $id, 'activo' => '1', 'tipo_bloque_anamnesis_id' => test()->enfermedadActual->id, 'contenido' => $contenido];
}

test('guarda, devuelve la versión nueva, la hora y el id de cada fila nueva por su uid', function () {
    $antes = $this->consulta->version();
    Carbon::setTestNow(now()->addSecond());

    $respuesta = hcAutoguardar($this->consulta, ['motivo_consulta' => 'Cefalea.', 'con_anamnesis' => '1', 'anamnesis' => [bloque('n-a', 'Dolor de cabeza.')]])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $bloque = $this->consulta->bloquesAnamnesis()->sole();
    expect($respuesta->json('version'))->toBe($this->consulta->fresh()->version())->not->toBe($antes)
        ->and($respuesta->json('guardado'))->toBe('06/10/2026 09:00')
        ->and($respuesta->json('ids'))->toBe(['anamnesis' => ['n-a' => $bloque->id]])
        ->and($respuesta->json('incompletas'))->toBe([])
        ->and($this->consulta->fresh()->motivo_consulta)->toBe('Cefalea.');

    // El siguiente envío ya manda la fila con su id: no se duplica.
    hcAutoguardar($this->consulta, ['con_anamnesis' => '1', 'anamnesis' => [bloque('n-a', 'Dolor de cabeza intenso.', (string) $bloque->id)]])->assertOk();
    expect($this->consulta->bloquesAnamnesis()->sole()->contenido)->toBe('Dolor de cabeza intenso.');
});

test('validación de borrador: nada obligatorio; las filas incompletas no se guardan y se informan por su uid', function () {
    $respuesta = hcAutoguardar($this->consulta, [
        'motivo_consulta' => '',
        'con_anamnesis' => '1',
        'anamnesis' => [bloque('n-1', 'Completa.'), bloque('n-2', ''), ['uid' => 'n-3', 'id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => '', 'contenido' => 'Sin tipo']],
        'con_diagnosticos' => '1',
        'diagnosticos' => [['uid' => 'n-4', 'id' => '', 'activo' => '1', 'codigo_cie10' => '', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => '']],
        'diagnostico_principal' => '0',
        'con_indicaciones' => '1',
        'indicaciones' => [['uid' => 'n-5', 'id' => '', 'activo' => '1', 'tipo_indicacion_id' => '', 'descripcion' => '  ']],
    ])->assertOk();

    expect($respuesta->json('incompletas'))->toBe(['anamnesis' => ['n-2', 'n-3'], 'diagnosticos' => ['n-4'], 'indicaciones' => ['n-5']])
        ->and($this->consulta->bloquesAnamnesis()->pluck('contenido')->all())->toBe(['Completa.'])
        ->and($this->consulta->diagnosticos()->count())->toBe(0)
        ->and($this->consulta->indicaciones()->count())->toBe(0);

    // El aviso de la fila, en la pantalla.
    $this->get(route('admin.consultas.atencion', $this->consulta))->assertSee(FormularioConsulta::FILA_INCOMPLETA);
});

test('un dato inválido (no incompleto) es un 422 con el error en su campo, sin guardar nada', function () {
    hcAutoguardar($this->consulta, ['motivo_consulta' => 'Algo', 'examen' => ['temperatura' => '99']])
        ->assertStatus(422)->assertJsonValidationErrors(['examen.temperatura']);

    expect($this->consulta->fresh()->motivo_consulta)->toBeNull();
});

test('versión vieja (otra ventana guardó): 409 con el aviso, sin pisar nada', function () {
    $vieja = $this->consulta->version();
    Carbon::setTestNow(now()->addSecond());
    hcAutoguardar($this->consulta, ['motivo_consulta' => 'Ventana 1.'], $vieja)->assertOk();

    hcAutoguardar($this->consulta, ['motivo_consulta' => 'Ventana 2.'], $vieja)
        ->assertStatus(409)->assertJson(['message' => Autoguardado::VERSION_VIEJA]);
    expect($this->consulta->fresh()->motivo_consulta)->toBe('Ventana 1.');
});

test('409 si la consulta ya no está en preparación ni en curso (finalizada o anulada)', function () {
    hcFinalizar($this->consulta, hcDatos())->assertSessionHasNoErrors();
    hcAutoguardar($this->consulta, ['motivo_consulta' => 'Tarde.'])->assertStatus(409)->assertJson(['message' => Autoguardado::NO_ABIERTA]);

    $anulada = hcEnCurso();
    $this->post(route('admin.consultas.deshacer', $anulada));
    hcAutoguardar($anulada, ['examen' => ['peso' => '60']])->assertNotFound();
});

test('sin sesión, 401; otro profesional, 403; sin CSRF, 419 (la pantalla lo muestra sin navegar)', function () {
    $this->actingAs($this->otroMedico);
    hcAutoguardar($this->consulta, ['motivo_consulta' => 'x'])->assertForbidden();

    auth()->logout();
    hcAutoguardar($this->consulta, ['motivo_consulta' => 'x'])->assertUnauthorized();

    // Con la verificación de CSRF activa (los tests la apagan): un token vencido es 419.
    $this->actingAs($this->medico);
    $this->withMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    $this->app->instance('env', 'produccion-simulada');
    $this->patch(route('admin.consultas.autoguardado', $this->consulta), ['_token' => 'vencido', 'version' => $this->consulta->version()], ['Accept' => 'application/json'])
        ->assertStatus(419);
    expect($this->consulta->fresh()->motivo_consulta)->toBeNull();
});

test('hasta 30 pedidos por minuto por usuario: el 31 es 429; a otro usuario no lo afecta', function () {
    RateLimiter::clear('autoguardado');
    for ($i = 1; $i <= 30; $i++) {
        hcAutoguardar($this->consulta, ['examen' => ['frecuencia_cardiaca' => (string) (60 + $i)]])->assertOk();
    }
    hcAutoguardar($this->consulta, ['examen' => ['frecuencia_cardiaca' => '99']])->assertStatus(429);
    expect($this->consulta->fresh()->examenFisico->frecuencia_cardiaca)->toBe(90);

    // Otro usuario (una enfermera con PREPARACION) tiene su propio límite.
    $enfermera = \App\Models\User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->create();
    $this->actingAs($enfermera);
    hcAutoguardar($this->consulta, ['examen' => ['peso' => '60']])->assertOk();

    // Al minuto siguiente vuelve a poder.
    $this->actingAs($this->medico);
    Carbon::setTestNow(now()->addSeconds(61));
    hcAutoguardar($this->consulta, ['examen' => ['frecuencia_cardiaca' => '99']])->assertOk();
});

test('auditoría: cada guardado efectivo es un EDITAR "Borrador (autoguardado)" por sección; sin cambios, nada; nunca un VER', function () {
    $antes = LogAuditoria::max('id');

    hcAutoguardar($this->consulta, ['motivo_consulta' => 'Cefalea.', 'examen' => ['peso' => '60']])->assertOk();
    $eventos = LogAuditoria::where('id', '>', $antes)->orderBy('id')->get();
    expect($eventos->map(fn ($e) => [$e->tabla_afectada, $e->accion, $e->detalle])->all())->toBe([
        ['consultas', AccionAuditoria::EDITAR, 'Borrador (autoguardado)'],
        ['consultas', AccionAuditoria::EDITAR, 'Borrador (autoguardado)'],
    ])->and($eventos->pluck('usuario_id')->unique()->all())->toBe([$this->medico->id]);

    $antes = LogAuditoria::max('id');
    hcAutoguardar($this->consulta, ['motivo_consulta' => 'Cefalea.', 'examen' => ['peso' => '60']])->assertOk();
    expect(LogAuditoria::where('id', '>', $antes)->count())->toBe(0);
});

test('el autor del cambio queda en el evento y en la fila ("Cargado por" / "modificado por")', function () {
    $enfermera = \App\Models\User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->conPersona(['apellidos' => 'Ortiz', 'nombres' => 'Nidia'])->create();
    $this->actingAs($enfermera);
    $id = hcAutoguardar($this->consulta, ['con_anamnesis' => '1', 'anamnesis' => [bloque('n-1', 'Fiebre.')], 'examen' => ['peso' => '60']])->json('ids.anamnesis.n-1');
    expect(LogAuditoria::where('detalle', 'Borrador (autoguardado)')->latest('id')->value('usuario_id'))->toBe($enfermera->id);

    $this->actingAs($this->medico);
    hcAutoguardar($this->consulta, ['con_anamnesis' => '1', 'anamnesis' => [bloque('g-'.$id, 'Fiebre de 3 días.', (string) $id)], 'examen' => ['peso' => '60', 'hallazgos' => 'Normal.']])->assertOk();

    // Bloques: la autoría va en los datos de la fila (Alpine la muestra con x-text); signos y hallazgos, en el HTML.
    $this->get(route('admin.consultas.atencion', $this->consulta))->assertOk()
        ->assertViewHas('datos', fn ($datos) => $datos['filasAnamnesis'][0]['autor'] === 'Cargado por Nidia Ortiz, modificado por Rosa Benítez'
            && $datos['autorSignos'] === 'Cargado por Nidia Ortiz' && $datos['autorHallazgos'] === 'Cargado por Rosa Benítez')
        ->assertSee('Cargado por Nidia Ortiz') // signos vitales
        ->assertSee('Cargado por Rosa Benítez'); // hallazgos

    hcFinalizar($this->consulta, [...hcDatos(), 'anamnesis' => [bloque('g-'.$id, 'Fiebre de 3 días.', (string) $id)], 'examen' => ['peso' => '60', 'hallazgos' => 'Normal.']])->assertSessionHasNoErrors();
    $this->get(route('admin.consultas.show', $this->consulta))->assertSee('Cargado por Nidia Ortiz, modificado por Rosa Benítez');
});
