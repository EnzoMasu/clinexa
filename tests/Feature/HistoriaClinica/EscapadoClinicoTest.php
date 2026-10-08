<?php

use App\Enums\AccionAuditoria;
use App\Models\LogAuditoria;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Contenido clínico con HTML y JS: se guarda tal cual y se muestra siempre escapado ({{ }}), en la
 * vista de lectura, el formulario y el detalle de auditoría. Los saltos de línea los da el CSS
 * (whitespace-pre-line), nunca {!! !!}.
 */

beforeEach(function () {
    hcEscenario();
    $this->img = '<img src=x onerror=alert(1)>';
    $this->script = '"><script>alert(1)</script>';
});

afterEach(fn () => Carbon::setTestNow());

function sinHtmlCrudo($respuesta)
{
    return $respuesta->assertDontSee(test()->img, false)->assertDontSee('<script>alert(1)</script>', false);
}

test('HTML y JS en motivo, anamnesis, hallazgos y descripción adicional: escapados en la lectura, el formulario y la auditoría', function () {
    $consulta = hcConsulta([
        'motivo_consulta' => "Motivo {$this->img}\nsegunda línea",
        'anamnesis' => [['id' => '', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => "Bloque {$this->script}"]],
        'examen' => ['hallazgos' => "Hallazgo {$this->img}"],
        'diagnosticos' => [['id' => '', 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => "Detalle {$this->script}"]],
    ]);
    expect($consulta->motivo_consulta)->toBe("Motivo {$this->img}\nsegunda línea"); // se guarda tal cual

    // Vista de lectura y fragmento del popup (el mismo partial): escapado y con el salto de línea por CSS.
    $detalle = $this->get(route('admin.consultas.detalle', $consulta), ['X-Requested-With' => 'XMLHttpRequest']);
    foreach ([$this->get(route('admin.consultas.show', $consulta)), $detalle] as $lectura) {
        sinHtmlCrudo($lectura->assertOk())
            ->assertSee('Motivo &lt;img src=x onerror=alert(1)&gt;', false)
            ->assertSee('Bloque &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('Hallazgo &lt;img src=x onerror=alert(1)&gt;', false)
            ->assertSee('Detalle &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('whitespace-pre-line', false);
    }

    // Historia del paciente: el motivo resumido en la fila, escapado.
    sinHtmlCrudo($this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk())
        ->assertSee('Motivo &lt;img src=x onerror=alert(1)&gt;', false);

    // Formulario de edición: motivo y hallazgos escapados en el textarea; anamnesis y diagnósticos como
    // JSON con < > " codificados para Alpine (dentro de JSON.parse('...'), por eso la barra doble).
    sinHtmlCrudo($this->get(route('admin.consultas.edit', $consulta))->assertOk())
        ->assertSee('Motivo &lt;img src=x onerror=alert(1)&gt;', false)
        ->assertSee('Hallazgo &lt;img src=x onerror=alert(1)&gt;', false)
        ->assertSee('Bloque \\\\u0022\\\\u003E\\\\u003Cscript\\\\u003Ealert(1)\\\\u003C\\\\\\/script\\\\u003E', false)
        ->assertSee('Detalle \\\\u0022\\\\u003E\\\\u003Cscript\\\\u003Ealert(1)', false)
        ->assertDontSee('x-html', false);

    // Detalle de auditoría (con permiso clínico): cada lista antes/después, escapada.
    $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());
    $eventos = LogAuditoria::where('tabla_afectada', 'consultas')->whereIn('accion', [AccionAuditoria::CREAR->value, AccionAuditoria::EDITAR->value])->orderBy('id')->get();
    expect($eventos)->toHaveCount(4);

    $esperado = [
        'Motivo &lt;img src=x onerror=alert(1)&gt;',
        'Alergias: Bloque &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;',
        'Hallazgos: Hallazgo &lt;img src=x onerror=alert(1)&gt;',
        'J06.9 — Rinofaringitis aguda (PRESUNTIVO, principal): Detalle &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;',
    ];
    foreach ($eventos as $i => $evento) {
        sinHtmlCrudo($this->get(route('admin.auditoria.show', $evento))->assertOk())->assertSee($esperado[$i], false);
    }
});

test('un error de validación vuelve a mostrar lo escrito, escapado', function () {
    $this->from(route('admin.consultas.create', $this->historia))
        ->post(route('admin.consultas.store', $this->historia), [...hcDatos(['examen' => ['hallazgos' => $this->img, 'temperatura' => '99']]), 'motivo_consulta' => $this->script])
        ->assertSessionHasErrors('examen.temperatura');

    sinHtmlCrudo($this->get(route('admin.consultas.create', $this->historia))->assertOk())
        ->assertSee('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
});

test('las vistas clínicas no usan {!! !!}', function () {
    foreach (['consultas/show', 'consultas/_contenido', 'consultas/form', 'historias-clinicas/show', 'historias-clinicas/_tabla', 'auditoria/show'] as $vista) {
        expect(file_get_contents(resource_path("views/admin/{$vista}.blade.php")))->not->toContain('{!!');
    }
});
