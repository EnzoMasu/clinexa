<?php

/*
 * Las pantallas del flujo: "Consulta" (la del profesional), "Preparación" (lista y formulario), la
 * pantalla de atención y la historia clínica con los estados nuevos. Qué muestran, qué registran como
 * lectura (VER) y qué no, y sus encabezados (SinAlmacenar). "Ahora": martes 06/10/2026 09:00.
 */

use App\Http\Controllers\Admin\AtencionController;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Support\Carbon;

const PERMISOS_PREPARACION = ['PREPARACION' => ['VER', 'CREAR', 'EDITAR']];

beforeEach(function () {
    hcEscenario();
    $this->medico = hcDarPermisos($this->medico, ['TURNOS' => ['VER', 'EDITAR']]);
    $this->actingAs($this->medico);
});

afterEach(fn () => Carbon::setTestNow());

/** Las lecturas (VER) registradas, como [tabla, registro]. */
function lecturasDelFlujo(): array
{
    return LogAuditoria::where('accion', 'VER')->orderBy('id')->get()->map(fn ($e) => [$e->tabla_afectada, $e->registro_afectado_id])->all();
}

/** Una URL como queda dentro de un JSON de Alpine (Js::from escapa las barras). */
function enJson(string $url): string
{
    return str_replace('/', '\/', $url);
}

function pacienteNuevo(string $apellidos, string $nombres, string $ficha): Paciente
{
    return Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => $apellidos, 'nombres' => $nombres, 'fecha_nacimiento' => '2000-01-01'])->id, 'nro_ficha' => $ficha]);
}

describe('pantalla Consulta', function () {
    test('quien no es profesional activo ve solo el aviso (ni el Administrador)', function () {
        $this->actingAs(User::factory()->administrador()->create());
        $this->get(route('admin.atencion.index'))->assertOk()->assertSee(AtencionController::SOLO_PROFESIONALES)
            ->assertDontSee('Agenda de hoy')->assertDontSee('Atender sin turno');

        $this->profesional->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);
        $this->actingAs($this->medico->fresh())->get(route('admin.atencion.index'))->assertSee(AtencionController::SOLO_PROFESIONALES);
    });

    test('secciones: en consulta, agenda de hoy (con "Confirmó" y la preparación), por llamar de nuevo, atendidos y ausentes', function () {
        $p2 = pacienteNuevo('Gómez', 'Ana', 'FP-0000002');
        $p3 = pacienteNuevo('López', 'Bea', 'FP-0000003');
        $p4 = pacienteNuevo('Martínez', 'Cora', 'FP-0000004');
        $p5 = pacienteNuevo('Núñez', 'Dora', 'FP-0000005');

        $enCurso = hcTurno(['hora_inicio' => '07:00', 'hora_fin' => '07:30']);
        $this->post(route('admin.atencion.atender', $enCurso));
        $confirmado = hcTurno(['paciente_id' => $p2->id]); // 08:00, CONFIRMADO
        $pendiente = hcTurno(['paciente_id' => $p3->id, 'hora_inicio' => '08:30', 'hora_fin' => '09:00', 'estado_id' => Estado::idDe(Estado::PENDIENTE)]);
        $this->post(route('admin.preparacion.preparar', $pendiente));
        $saltado = hcTurno(['paciente_id' => $p4->id, 'hora_inicio' => '09:00', 'hora_fin' => '09:30', 'estado_id' => Estado::idDe(Estado::SALTADO)]);
        $ausente = hcTurno(['paciente_id' => $p5->id, 'hora_inicio' => '09:30', 'hora_fin' => '10:00', 'estado_id' => Estado::idDe(Estado::AUSENTE)]);
        hcTurno(['profesional_id' => $this->otroProfesional->id, 'paciente_id' => $p5->id, 'hora_inicio' => '11:00', 'hora_fin' => '11:30']); // de otra profesional

        $html = $this->get(route('admin.atencion.index'))->assertOk()
            ->assertSeeInOrder(['En consulta', 'Duarte, Carmen', 'Continuar',
                'Agenda de hoy', '08:00', 'Gómez, Ana', 'Confirmó', 'Sin preparar', '08:30', 'López, Bea', 'En preparación',
                'Por llamar de nuevo', '09:00', 'Martínez, Cora', 'Pasar a ausente',
                'Atendidos hoy (0)', 'Ausentes hoy (1)', 'Núñez, Dora'])
            ->getContent();

        expect($html)->toContain(route('admin.atencion.atender', $confirmado))->toContain(route('admin.atencion.no-se-presento', $confirmado))
            ->toContain(route('admin.preparacion.preparar', $confirmado))
            ->toContain(route('admin.preparacion.formulario', Consulta::where('turno_id', $pendiente->id)->sole()))
            ->toContain(route('admin.atencion.atender', $saltado))
            ->not->toContain(route('admin.atencion.no-se-presento', $saltado))
            ->not->toContain(route('admin.atencion.atender', $ausente))
            ->and(substr_count($html, 'Confirmó'))->toBe(1)
            ->and(substr_count($html, '11:00'))->toBe(0);
    });

    test('atendidos hoy: hasta 3 códigos CIE-10, sin motivo ni otro contenido clínico', function () {
        $consulta = hcConsulta(['motivo_consulta' => 'MOTIVO-PRIVADO', 'diagnosticos' => [
            ['id' => '', 'activo' => '1', 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => ''],
            ['id' => '', 'activo' => '1', 'codigo_cie10' => 'R51', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => ''],
            ['id' => '', 'activo' => '1', 'codigo_cie10' => 'Z00.0', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => ''],
        ]]);

        $this->get(route('admin.atencion.index'))->assertOk()
            ->assertSee('Atendidos hoy (1)')->assertSee('J06.9, R51, Z00.0')->assertSee(route('admin.consultas.show', $consulta))
            ->assertDontSee('MOTIVO-PRIVADO')->assertDontSee('Odinofagia');
    });

    test('más de una consulta en curso: aparecen todas, de cualquier día, con el aviso', function () {
        hcEnCurso();
        $ayer = hcEnCurso();
        $ayer->forceFill(['iniciada_en' => Carbon::parse('2026-10-05 20:00:00', 'UTC')])->save();

        $this->get(route('admin.atencion.index'))->assertOk()
            ->assertSee('Tiene 2 consultas en curso al mismo tiempo.')->assertSee('05/10/2026 17:00')->assertSee('09:00');
    });

    test('aviso de turnos de días anteriores sin cerrar', function () {
        hcTurno(['fecha' => '2026-10-05']);
        hcTurno(['fecha' => '2026-10-02', 'estado_id' => Estado::idDe(Estado::SALTADO)]);
        hcTurno(['fecha' => '2026-10-01', 'estado_id' => Estado::idDe(Estado::ATENDIDO)]);

        $this->get(route('admin.atencion.index'))->assertSee('Tiene 2 turnos de días anteriores sin cerrar.');
    });

    test('"Atender sin turno": panel con el buscador de pacientes y la ayuda de alta y reactivación', function () {
        $this->get(route('admin.atencion.index'))->assertOk()
            ->assertSee('Atender sin turno')->assertSee(enJson(route('admin.atencion.pacientes')), false)
            ->assertSee('Si el paciente no aparece, puede que todavía no esté registrado o que esté inactivo.');

        $this->getJson(route('admin.atencion.pacientes', ['q' => 'Duar']))->assertOk()
            ->assertJsonPath('0.id', $this->historia->id)->assertJsonCount(1);
        $this->paciente->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);
        $this->getJson(route('admin.atencion.pacientes', ['q' => 'Duar']))->assertOk()->assertJsonCount(0);
    });

    test('la actualización automática devuelve solo el fragmento y no registra lecturas; la carga normal registra un VER sin registro', function () {
        hcTurno();
        $this->get(route('admin.atencion.index'))->assertOk()->assertSee('data-refresco', false)->assertSee('refrescoPeriodico', false);
        expect(lecturasDelFlujo())->toBe([['consultas', null]]);

        Carbon::setTestNow(now()->addMinutes(10));
        $fragmento = $this->get(route('admin.atencion.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertHeader('Vary', 'X-Requested-With')->getContent();
        expect($fragmento)->toStartWith('<div data-refresco')->not->toContain('<html')
            ->and(lecturasDelFlujo())->toBe([['consultas', null]]);
    });
});

describe('Preparación', function () {
    beforeEach(function () {
        $this->enfermera = User::factory()->conPermisos(PERMISOS_PREPARACION)->conPersona(['apellidos' => 'Ortiz', 'nombres' => 'Nidia'])->create();
    });

    test('la lista: turnos de hoy de todos los profesionales, con hora, paciente (edad y ficha), profesional y preparación', function () {
        $p2 = pacienteNuevo('Gómez', 'Ana', 'FP-0000002');
        $mio = hcTurno();
        $otro = hcTurno(['profesional_id' => $this->otroProfesional->id, 'paciente_id' => $p2->id, 'hora_inicio' => '07:30', 'hora_fin' => '08:00', 'consultorio_id' => $this->consultorio->id]);
        hcTurno(['fecha' => '2026-10-07', 'hora_inicio' => '10:00', 'hora_fin' => '10:30']); // mañana: no
        hcTurno(['hora_inicio' => '12:00', 'hora_fin' => '12:30', 'estado_id' => Estado::idDe(Estado::CANCELADO)]); // cancelado: no
        $this->actingAs($this->enfermera)->post(route('admin.preparacion.preparar', $mio));
        $this->post(route('admin.preparacion.lista', Consulta::sole()));

        $html = $this->get(route('admin.preparacion.index'))->assertOk()
            ->assertSeeInOrder(['07:30', 'Gómez, Ana', '26 años', 'FP-0000002', 'Insfrán, Laura', 'Sin preparar', 'Preparar',
                '08:00', 'Duarte, Carmen', '36 años', 'FP-0000001', 'Benítez, Rosa', 'Lista', 'Continuar'])
            ->getContent();
        expect(substr_count($html, '<li class="grid'))->toBe(2)->and($html)->not->toContain('10:00')->not->toContain('12:00');
        expect(lecturasDelFlujo())->toBe([]); // la lista no es contenido clínico (PREPARACION no es sensible)
    });

    test('filtro por profesional y búsqueda en vivo (fragmento)', function () {
        $p2 = pacienteNuevo('Gómez', 'Ana', 'FP-0000002');
        hcTurno();
        hcTurno(['profesional_id' => $this->otroProfesional->id, 'paciente_id' => $p2->id, 'hora_inicio' => '07:30', 'hora_fin' => '08:00']);
        $this->actingAs($this->enfermera);

        $this->get(route('admin.preparacion.index', ['profesional' => $this->otroProfesional->id]))->assertSee('Gómez, Ana')->assertDontSee('Duarte, Carmen');
        $this->get(route('admin.preparacion.index', ['q' => 'Duar']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('Duarte, Carmen')->assertDontSee('Gómez, Ana')->assertDontSee('<html', false);
        $this->get(route('admin.preparacion.index', ['q' => 'Insfr']))->assertSee('Gómez, Ana')->assertDontSee('Duarte, Carmen');
        $this->get(route('admin.preparacion.index', ['q' => 'FP-0000002']))->assertSee('Gómez, Ana');
    });

    test('el formulario: barra del paciente, anamnesis y signos, "Marcar como lista", autoguardado; registra un VER de la consulta', function () {
        $turno = hcTurno();
        $this->actingAs($this->enfermera)->post(route('admin.preparacion.preparar', $turno));
        $consulta = Consulta::sole();

        $this->get(route('admin.preparacion.formulario', $consulta))->assertOk()
            ->assertSeeInOrder(['Duarte, Carmen', '36 años', 'Femenino', 'FP-0000001', 'Turno 08:00', 'Benítez, Rosa'])
            ->assertSee('Anamnesis')->assertSee('Signos vitales')->assertSee('Marcar como lista')->assertSee('Volver')
            ->assertSee(enJson(route('admin.consultas.autoguardado', $consulta)), false)->assertSee('Guardar ahora');
        $this->get(route('admin.preparacion.formulario', $consulta), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        expect(lecturasDelFlujo())->toBe([['consultas', (string) $consulta->id]]);
    });

    test('sin PREPARACION ni ser el profesional: no entra a la lista ni al formulario', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno));
        $consulta = Consulta::sole();

        $this->actingAs(User::factory()->conPermisos(['TURNOS' => ['VER']])->create());
        $this->get(route('admin.preparacion.index'))->assertForbidden();
        $this->get(route('admin.preparacion.formulario', $consulta))->assertForbidden();
        $this->post(route('admin.preparacion.preparar', hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30'])))->assertForbidden();
    });
});

describe('pantalla de atención', function () {
    test('EN_PREPARACION va al formulario de preparación; FINALIZADA a la lectura; ANULADA no existe', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno));
        $preparacion = Consulta::sole();
        $this->get(route('admin.consultas.atencion', $preparacion))->assertRedirect(route('admin.preparacion.formulario', $preparacion));

        $finalizada = hcConsulta();
        $this->get(route('admin.consultas.atencion', $finalizada))->assertRedirect(route('admin.consultas.show', $finalizada));

        $anulada = hcEnCurso();
        $this->post(route('admin.consultas.deshacer', $anulada));
        $this->get(route('admin.consultas.atencion', $anulada))->assertNotFound();
    });

    test('barra fija, secciones, "Estudios" deshabilitado y el panel inicial (Anamnesis sin bloques; si no, Motivo y diagnósticos)', function () {
        $consulta = hcEnCurso(hcTurno());

        $this->get(route('admin.consultas.atencion', $consulta))->assertOk()
            ->assertSeeInOrder(['Duarte, Carmen', 'Turno 08:00', 'En consulta desde 09:00', 'Guardar ahora',
                'Finalizar consulta', 'Deshacer atención', 'Volver a la lista',
                'Anamnesis', 'Examen físico', 'Motivo y diagnósticos', 'Indicaciones', 'Estudios', 'Próximamente'])
            ->assertViewHas('panelInicial', 'anamnesis');

        hcAutoguardar($consulta, ['con_anamnesis' => '1', 'anamnesis' => [['uid' => 'n', 'id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'Penicilina.']]]);
        $this->get(route('admin.consultas.atencion', $consulta))->assertViewHas('panelInicial', 'motivo');

        $this->get(route('admin.consultas.atencion', hcEnCurso()))->assertSee('Urgencia');
    });

    test('registra un VER de la consulta y uno de la historia; el autoguardado no registra lecturas', function () {
        $consulta = hcEnCurso();
        LogAuditoria::query()->where('accion', 'VER')->get(); // nada antes

        $this->get(route('admin.consultas.atencion', $consulta))->assertOk();
        hcAutoguardar($consulta, ['motivo_consulta' => 'Cefalea.'])->assertOk();
        $this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'R5']))->assertOk();

        expect(collect(lecturasDelFlujo())->sort()->values()->all())->toBe([['consultas', (string) $consulta->id], ['historias_clinicas', (string) $this->historia->id]]);
    });

    test('recetas: lista y acciones en otra pestaña (target _blank, rel noopener noreferrer)', function () {
        $this->medico = hcDarPermisos($this->medico, HC_PERMISOS_RECETAS);
        $this->actingAs($this->medico);
        $consulta = hcEnCurso();
        $receta = hcReceta($consulta);

        $html = $this->get(route('admin.consultas.atencion', $consulta))->assertOk()->getContent();
        foreach ([route('admin.recetas.create', $consulta), route('admin.recetas.edit', $receta), route('admin.recetas.vista-previa', $receta)] as $url) {
            expect($html)->toContain('href="'.$url.'" target="_blank" rel="noopener noreferrer"');
        }

        // Sin VER sobre RECETAS: ni la sección.
        $this->actingAs(hcConPerfil($this->medico, HC_PERMISOS_MEDICO));
        $this->get(route('admin.consultas.atencion', $consulta))->assertOk()->assertDontSee('Nueva receta')->assertDontSee(route('admin.recetas.edit', $receta));
    });

    test('historial del paciente: hasta 20 consultas FINALIZADAS anteriores, más recientes primero, con popup sin "Editar"', function () {
        foreach (range(1, 22) as $n) {
            Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC')->addDays($n));
            hcConsulta(['motivo_consulta' => sprintf('Control número %02d', $n)]);
        }
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        $this->post(route('admin.preparacion.preparar', hcTurno())); // una en preparación: no va
        $consulta = hcEnCurso();

        $this->get(route('admin.consultas.atencion', $consulta))->assertOk()
            ->assertSee('Historial del paciente')->assertSeeInOrder(['Control número 22', 'Control número 21', 'Control número 03'])
            ->assertDontSee('Control número 02')->assertDontSee('Control número 01')
            ->assertSee('sinEditar: true', false)->assertSee('Ver historia completa')
            ->assertViewHas('historial', fn ($historial) => $historial->count() === 20);
    });

    test('sin consultas anteriores lo dice', function () {
        $this->get(route('admin.consultas.atencion', hcEnCurso()))->assertSee('No hay consultas anteriores.');
    });
});

describe('historia clínica con el flujo nuevo', function () {
    test('pastillas "En curso" y "En preparación"; las anuladas no aparecen; cantidad y última consulta solo de las finalizadas', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00', 'UTC'));
        hcConsulta(['motivo_consulta' => 'La finalizada']);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        $this->post(route('admin.preparacion.preparar', hcTurno()));
        hcEnCurso();
        $this->post(route('admin.consultas.deshacer', hcEnCurso())); // anulada

        $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()
            ->assertSee('En curso')->assertSee('En preparación')->assertSee('La finalizada')
            ->assertViewHas('consultas', fn ($consultas) => $consultas->total() === 3);

        $this->get(route('admin.historias-clinicas.index'))->assertOk()
            ->assertViewHas('historias', fn ($historias) => $historias->first()->consultas_count === 1)
            ->assertSee('01/10/2026 09:00');
    });

    test('"Atender sin turno" en la historia y en el listado es un POST', function () {
        $this->get(route('admin.historias-clinicas.show', $this->historia))
            ->assertSee('action="'.route('admin.atencion.atender-sin-turno', $this->historia).'"', false);
        $this->get(route('admin.historias-clinicas.index'))
            ->assertSee('action="'.route('admin.atencion.atender-sin-turno', $this->historia).'"', false);
    });
});

describe('encabezados SinAlmacenar', function () {
    test('no-store y nosniff en todas las pantallas y respuestas del flujo', function () {
        $turno = hcTurno();
        $enfermera = User::factory()->conPermisos(PERMISOS_PREPARACION)->create();
        $this->actingAs($enfermera)->post(route('admin.preparacion.preparar', $turno));
        $preparacion = Consulta::sole();
        $this->actingAs($this->medico);
        $consulta = hcEnCurso();

        foreach ([
            $this->get(route('admin.atencion.index')),
            $this->get(route('admin.atencion.index'), ['X-Requested-With' => 'XMLHttpRequest']),
            $this->getJson(route('admin.atencion.pacientes', ['q' => 'Duar'])),
            $this->get(route('admin.atencion.cerrar-jornada')),
            $this->get(route('admin.consultas.atencion', $consulta)),
            hcAutoguardar($consulta, ['motivo_consulta' => 'x']),
            $this->get(route('admin.preparacion.formulario', $preparacion)),
            $this->actingAs($enfermera)->get(route('admin.preparacion.index')),
        ] as $respuesta) {
            $respuesta->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        }
    });
});

describe('menú', function () {
    test('grupo Clínica: Consulta, Preparación e Historias clínicas, cada una con su permiso', function () {
        $this->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['data-grupo-menu="Clínica"', route('admin.atencion.index'), route('admin.historias-clinicas.index')], false)
            ->assertDontSee(route('admin.preparacion.index'));

        $this->actingAs(User::factory()->conPermisos(PERMISOS_PREPARACION)->create());
        $this->get('/dashboard')->assertSee(route('admin.preparacion.index'))->assertDontSee(route('admin.atencion.index'))
            ->assertDontSee('Atención sin turno');
    });
});
