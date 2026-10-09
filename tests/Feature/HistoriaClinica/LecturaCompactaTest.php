<?php

use App\Models\CatalogoCIE10;
use App\Models\Consulta;
use App\Models\LogAuditoria;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Lectura de consultas: página compacta, lista corta en la historia y el fragmento del popup
 * (ConsultaController::detalle), con su registro de lectura y sus encabezados.
 */

beforeEach(fn () => hcEscenario());

afterEach(fn () => Carbon::setTestNow());

/** El fragmento del popup, pedido como lo pide el JS (AJAX). */
function pedirDetalle(Consulta $consulta)
{
    return test()->get(route('admin.consultas.detalle', $consulta), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html']);
}

function lecturasDeConsultas(): array
{
    return LogAuditoria::where('accion', 'VER')->where('tabla_afectada', 'consultas')->orderBy('id')
        ->get()->map(fn ($e) => [$e->usuario_id, $e->registro_afectado_id])->all();
}

describe('fragmento del detalle', function () {
    test('con VER: 200 con el mismo contenido que la página completa', function () {
        $consulta = hcConsulta(['examen' => ['hallazgos' => 'Faringe eritematosa.', 'temperatura' => '38,2']]);

        $fragmento = pedirDetalle($consulta)->assertOk()->assertSee('Faringe eritematosa.')->assertDontSee('<html', false);
        $pagina = $this->get(route('admin.consultas.show', $consulta))->assertOk();

        expect(articuloDe($fragmento->getContent()))->not->toBe('')
            ->toBe(trim($fragmento->getContent()))
            ->toBe(articuloDe($pagina->getContent()));
    });

    test('sin VER: 403, y no registra lectura', function () {
        $consulta = hcConsulta();
        $this->actingAs(User::factory()->conPermisos(['PACIENTES' => ['VER']])->create());

        pedirDetalle($consulta)->assertForbidden();
        expect(lecturasDeConsultas())->toBe([]);
    });

    test('una consulta inexistente: 404, y no registra lectura', function () {
        $this->get(route('admin.consultas.detalle', ['consulta' => 999]), ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();

        expect(LogAuditoria::where('accion', 'VER')->count())->toBe(0);
    });

    test('pegada en el navegador (sin AJAX) redirige a la página completa, sin registrar en esa respuesta', function () {
        $consulta = hcConsulta();

        $this->get(route('admin.consultas.detalle', $consulta))->assertRedirect(route('admin.consultas.show', $consulta));
        expect(lecturasDeConsultas())->toBe([]);

        // La página completa registra la lectura una sola vez.
        $this->get(route('admin.consultas.show', $consulta))->assertOk();
        expect(lecturasDeConsultas())->toBe([[$this->medico->id, (string) $consulta->id]]);
    });

    test('encabezados: no-store, private y nosniff (también en la página y la historia)', function () {
        $consulta = hcConsulta();

        foreach ([pedirDetalle($consulta), $this->get(route('admin.consultas.show', $consulta)), $this->get(route('admin.historias-clinicas.show', $this->historia)),
            $this->get(route('admin.historias-clinicas.index')), $this->get(route('admin.consultas.edit', $consulta))] as $respuesta) {
            $respuesta->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            expect($respuesta->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')->not->toContain('public');
        }
    });

    test('"Editar" en el popup solo si puede modificarla: la URL viaja solo para el profesional que atiende', function () {
        $consulta = hcConsulta();

        pedirDetalle($consulta)->assertSee('data-url-editar="'.route('admin.consultas.edit', $consulta).'"', false);
        $this->actingAs($this->otroMedico);
        pedirDetalle($consulta)->assertOk()->assertDontSee('data-url-editar', false);
    });
});

describe('lectura (VER) por el fragmento', function () {
    test('se registra sobre consultas con el id, no se repite en 5 minutos, y cada consulta registra la suya', function () {
        $primera = hcConsulta();
        $segunda = hcConsulta();

        pedirDetalle($primera)->assertOk();
        pedirDetalle($primera)->assertOk(); // dentro de 5 minutos
        pedirDetalle($segunda)->assertOk(); // "Siguiente"
        $this->get(route('admin.consultas.show', $primera))->assertOk(); // misma consulta, misma regla

        expect(lecturasDeConsultas())->toBe([[$this->medico->id, (string) $primera->id], [$this->medico->id, (string) $segunda->id]]);

        Carbon::setTestNow(now()->addMinutes(6));
        pedirDetalle($primera)->assertOk();
        expect(lecturasDeConsultas())->toHaveCount(3);
    });

    test('los buscadores y la búsqueda en vivo siguen sin registrar', function () {
        hcConsulta();
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->get(route('admin.historias-clinicas.index', ['q' => 'Duarte']), $ajax)->assertOk();
        $this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'J06']))->assertOk();
        $this->get(route('admin.historias-clinicas.show', [$this->historia, 'page' => 1]), $ajax)->assertOk();

        expect(LogAuditoria::where('accion', 'VER')->count())->toBe(0);
    });
});

describe('página compacta', function () {
    test('omite las secciones vacías y muestra solo los signos cargados, con sus unidades', function () {
        $consulta = hcConsulta(['anamnesis' => [], 'diagnosticos' => [], 'diagnostico_principal' => '',
            'examen' => ['frecuencia_cardiaca' => '88', 'temperatura' => '38,2', 'saturacion_oxigeno' => '97']]);

        $html = $this->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSee('FC</abbr> 88 lpm', false)->assertSee('T</abbr> 38,2 °C', false)->assertSee('SatO₂</abbr> 97 %', false)
            ->assertDontSee('mmHg')->assertDontSee(' kg')->assertDontSee(' rpm')
            ->assertDontSee('Anamnesis')->assertDontSee('Diagnósticos')->assertDontSee('Hallazgos')
            ->assertDontSee('Sin anamnesis')->assertDontSee('Sin diagnósticos')
            ->getContent();

        // Sin ningún signo, tampoco la fila de pastillas.
        $sinExamen = hcConsulta(['examen' => []]);
        $this->get(route('admin.consultas.show', $sinExamen))->assertOk()->assertDontSee('Signos vitales')->assertSee('Anamnesis');

        expect($html)->toContain('Sin turno (urgencia)');
    });

    test('encabezado, botones y el historial de cambios plegado', function () {
        $consulta = hcConsulta();
        [$auditor] = hcMedico(['apellidos' => 'Paredes', 'nombres' => 'Juan'], 'MP-9', ['HISTORIA_CLINICA' => ['VER'], 'AUDITORIA' => ['VER']]);

        $this->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSeeInOrder(['Editar', 'Volver a la historia', 'Duarte, Carmen', 'Ficha FP-0000001', '06/10/2026', 'Benítez, Rosa', 'Sin turno (urgencia)', 'Motivo de consulta']);

        $html = $this->actingAs($auditor)->get(route('admin.consultas.show', $consulta))->assertOk()->assertDontSee('>Editar</a>', false)->getContent();
        expect($html)->toMatch('#<details class="[^"]*">\s*<summary[^>]*>Historial de cambios</summary>#')
            ->not->toMatch('#<details[^>]*\bopen\b#');
    });

    test('con turno, el encabezado lo muestra; al finalizar se va a la página con "Consulta finalizada." y al editar con "Consulta guardada."', function () {
        $turno = hcTurno();
        hcGuardarNueva([], $turno)->assertRedirect()->assertSessionHas('status', 'Consulta finalizada.');
        $consulta = Consulta::sole();

        $this->get(route('admin.consultas.show', $consulta))->assertSee('Turno del 06/10/2026, 08:00')->assertDontSee('Sin turno (urgencia)');

        hcActualizar($consulta, hcFilasGuardadas($consulta))->assertRedirect(route('admin.consultas.show', $consulta))->assertSessionHas('status', 'Consulta guardada.');
    });
});

describe('lista de la historia', function () {
    test('cada fila: fecha y hora, profesional, motivo cortado, hasta 3 códigos más "+N", y "Urgencia" sin turno', function () {
        foreach (['A01', 'A02', 'A03', 'A04', 'A05'] as $codigo) {
            CatalogoCIE10::create(['codigo' => $codigo, 'descripcion' => "Código {$codigo}", 'capitulo' => 'I']);
        }
        $motivoLargo = 'Dolor abdominal '.str_repeat('intermitente ', 20).'FINAL-DEL-MOTIVO';
        hcConsulta(['motivo_consulta' => $motivoLargo, 'diagnosticos' => collect(['A01', 'A02', 'A03', 'A04', 'A05'])
            ->map(fn ($c) => ['id' => '', 'codigo_cie10' => $c, 'tipo' => 'CONFIRMADO'])->all(), 'diagnostico_principal' => '0']);
        Carbon::setTestNow(now()->addHour());
        hcGuardarNueva([], hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30']))->assertSessionHasNoErrors();

        $html = $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()
            ->assertSeeInOrder(['06/10/2026 10:00', 'Benítez, Rosa', 'Dolor de garganta', 'J06.9', '06/10/2026 09:00', 'Benítez, Rosa', 'Urgencia', 'Dolor abdominal', 'A01', 'A02', 'A03', '+2'])
            ->assertDontSee('FINAL-DEL-MOTIVO')->assertDontSee('A04')
            ->getContent();

        expect(substr_count($html, '>Urgencia</span>'))->toBe(1); // la del turno no la tiene
    });

    test('el HTML no trae el contenido completo de ninguna consulta: ni motivo entero, ni anamnesis, ni hallazgos, ni descripciones', function () {
        hcConsulta([
            'motivo_consulta' => 'Motivo corto '.str_repeat('x', 100).' SECRETO-MOTIVO',
            'anamnesis' => [['id' => '', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'SECRETO-ANAMNESIS']],
            'examen' => ['hallazgos' => 'SECRETO-HALLAZGO', 'presion_arterial' => '135/85'],
            'diagnosticos' => [['id' => '', 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => 'SECRETO-DIAGNOSTICO']],
        ]);

        $html = $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()->getContent();

        foreach (['SECRETO-MOTIVO', 'SECRETO-ANAMNESIS', 'SECRETO-HALLAZGO', 'SECRETO-DIAGNOSTICO', '135/85', 'Rinofaringitis aguda', 'Penicilina', 'Alergias'] as $dato) {
            expect($html)->not->toContain($dato);
        }
    });

    test('sin JavaScript cada fila es un enlace a la página completa', function () {
        $consultas = collect([hcConsulta(), hcConsulta()]);

        $html = $this->get(route('admin.historias-clinicas.show', $this->historia))->getContent();

        foreach ($consultas as $consulta) {
            expect($html)->toMatch('#<a href="'.preg_quote(route('admin.consultas.show', $consulta), '#').'" data-consulta-id="'.$consulta->id.'"#');
        }
    });

    test('los ids para anterior y siguiente son solo los de la página actual, en el orden mostrado', function () {
        foreach (range(1, 23) as $n) {
            Consulta::create(['historia_clinica_id' => $this->historia->id, 'profesional_id' => $this->profesional->id, 'motivo_consulta' => "Motivo {$n}"]);
            Carbon::setTestNow(now()->addMinute());
        }
        $ordenadas = Consulta::orderByDesc('fecha_hora')->orderByDesc('id')->pluck('id');
        $idsDe = fn ($respuesta) => preg_match('/data-ids-consultas="([\d,]+)"/', $respuesta->getContent(), $m) ? array_map('intval', explode(',', $m[1])) : [];

        $pagina1 = $this->get(route('admin.historias-clinicas.show', $this->historia));
        $pagina2 = $this->get(route('admin.historias-clinicas.show', [$this->historia, 'page' => 2]));

        expect($idsDe($pagina1))->toBe($ordenadas->take(20)->values()->all())
            ->and($idsDe($pagina2))->toBe($ordenadas->slice(20)->values()->all())
            // Lo mismo que recibe el popup (Alpine), y nada más.
            ->and($pagina1->getContent())->toContain('ids: JSON.parse(\'['.implode(',', $ordenadas->take(20)->all()).']\')');
    });
});
