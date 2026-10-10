<?php

use App\Enums\AccionAuditoria;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => hcEscenario());

afterEach(fn () => Carbon::setTestNow());

function eventosDe(string $tabla, ?AccionAuditoria $accion = null)
{
    return LogAuditoria::where('tabla_afectada', $tabla)->when($accion, fn ($q) => $q->where('accion', $accion->value))->orderBy('id')->get();
}

describe('cambios: un solo historial, el de la consulta', function () {
    test('al atender y finalizar: CREAR de la consulta ("Atender sin turno") y UN EDITAR "Finalizar" con todas las secciones (solo sus filas) y el estado; nada en las tablas hijas', function () {
        $consulta = hcConsulta();

        $creacion = eventosDe('consultas', AccionAuditoria::CREAR)->sole();
        expect($creacion->detalle)->toBe('Atender sin turno')
            ->and($creacion->valor_nuevo)->toMatchArray(['historia_clinica_id' => $consulta->historia_clinica_id, 'turno_id' => null, 'estado_id' => Estado::idDe(Estado::EN_CURSO)])
            ->and($creacion->valor_nuevo)->not->toHaveKey('motivo_consulta');

        $finalizar = eventosDe('consultas', AccionAuditoria::EDITAR)->sole();
        [$b1, $b2] = $consulta->bloquesAnamnesis()->orderBy('orden')->pluck('id')->all();
        $d1 = $consulta->diagnosticos()->value('id');
        expect($finalizar->detalle)->toBe('Finalizar')
            ->and($finalizar->registro_afectado_id)->toBe((string) $consulta->id)
            ->and($finalizar->valor_nuevo)->toBe([
                'motivo_consulta' => 'Dolor de garganta y fiebre desde ayer.',
                'bloquesAnamnesis' => ["Bloque #{$b1}" => '1. Enfermedad actual: Odinofagia de 24 horas.', "Bloque #{$b2}" => '2. Alergias: Penicilina.'],
                'examenFisico' => ['Presión arterial' => '120/80 mmHg', 'Frecuencia cardíaca' => '88 lpm', 'Temperatura' => '38,2 °C', 'Peso' => '61,5 kg', 'Talla' => '165 cm'],
                'diagnosticos' => ["Diagnóstico #{$d1}" => 'J06.9 — Rinofaringitis aguda (PRESUNTIVO, principal)'],
                'estado_id' => Estado::idDe(Estado::FINALIZADO),
                'finalizada_en' => '2026-10-06 12:00:00.000000',
            ])
            // Filas nuevas: no existían antes.
            ->and($finalizar->valor_anterior['bloquesAnamnesis'])->toBe(["Bloque #{$b1}" => null, "Bloque #{$b2}" => null])
            ->and($finalizar->valor_anterior['motivo_consulta'])->toBeNull();

        foreach (['bloques_anamnesis', 'examenes_fisicos', 'diagnosticos'] as $tabla) {
            expect(eventosDe($tabla))->toBeEmpty();
        }
    });

    test('al editar una consulta en curso: UN evento de autoguardado con solo las filas que cambiaron (retirar, tipo, principal, descripción)', function () {
        $consulta = hcConsultaEnCurso();
        $antes = LogAuditoria::max('id');
        Carbon::setTestNow(now()->addMinute());

        $datos = hcFilasGuardadas($consulta);
        $datos['anamnesis'][1]['activo'] = '0';
        $datos['diagnosticos'][0]['tipo'] = 'CONFIRMADO';
        $datos['diagnosticos'][0]['descripcion_adicional'] = 'Con placas.';
        hcActualizar($consulta, $datos)->assertSessionHasNoErrors();

        $b2 = $consulta->bloquesAnamnesis()->orderBy('orden')->skip(1)->value('id');
        $d1 = $consulta->diagnosticos()->value('id');
        $nuevo = LogAuditoria::where('id', '>', $antes)->where('accion', AccionAuditoria::EDITAR->value)->sole();
        expect($nuevo->tabla_afectada)->toBe('consultas')->and($nuevo->detalle)->toBe('Borrador (autoguardado)')
            ->and([$nuevo->valor_anterior, $nuevo->valor_nuevo])->toBe([
                ['bloquesAnamnesis' => ["Bloque #{$b2}" => '2. Alergias: Penicilina.'], 'diagnosticos' => ["Diagnóstico #{$d1}" => 'J06.9 — Rinofaringitis aguda (PRESUNTIVO, principal)']],
                ['bloquesAnamnesis' => ["Bloque #{$b2}" => '2. Alergias: Penicilina. (retirado)'], 'diagnosticos' => ["Diagnóstico #{$d1}" => 'J06.9 — Rinofaringitis aguda (CONFIRMADO, principal): Con placas.']],
            ]);
    });

    test('con la cadena de eventos se reconstruye el estado de cada sección en cada momento', function () {
        $consulta = hcEnCurso();
        $bloque = fn ($uid, $texto, $id = '', $activo = '1') => ['uid' => $uid, 'id' => (string) $id, 'activo' => $activo, 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => $texto];
        $id = hcAutoguardar($consulta, ['con_anamnesis' => '1', 'anamnesis' => [$bloque('n-1', 'Penicilina.')], 'examen' => ['peso' => '60']])->json('ids.anamnesis.n-1');
        Carbon::setTestNow(now()->addSeconds(10));
        hcAutoguardar($consulta, ['con_anamnesis' => '1', 'anamnesis' => [$bloque('g', 'Penicilina y AINES.', $id), $bloque('n-2', 'Látex.')], 'examen' => ['peso' => '60', 'talla' => '160']]);
        Carbon::setTestNow(now()->addSeconds(10));
        $id2 = $consulta->bloquesAnamnesis()->where('id', '!=', $id)->value('id');
        hcAutoguardar($consulta, ['con_anamnesis' => '1', 'anamnesis' => [$bloque('g', 'Penicilina y AINES.', $id), $bloque('g2', 'Látex.', $id2, '0')], 'examen' => ['peso' => '61', 'talla' => '160']]);

        // Reconstrucción: aplicar en orden los "después" de cada evento sobre un estado vacío.
        $estado = [];
        foreach (eventosDe('consultas', AccionAuditoria::EDITAR) as $evento) {
            foreach ($evento->valor_nuevo as $seccion => $filas) {
                if (is_array($filas)) {
                    $estado[$seccion] = array_filter(array_replace($estado[$seccion] ?? [], $filas), fn ($texto) => $texto !== null);
                }
            }
        }
        $actual = $consulta->fresh()->relacionesAuditadas();
        expect($estado['bloquesAnamnesis'])->toBe($actual['bloquesAnamnesis']($consulta->fresh()->bloquesAnamnesis))
            ->and($estado['examenFisico'])->toBe($consulta->fresh()->examenFisico->descripcionPorCampo())
            ->and($estado['bloquesAnamnesis'])->toBe(["Bloque #{$id}" => '1. Alergias: Penicilina y AINES.', "Bloque #{$id2}" => '2. Alergias: Látex. (retirado)'])
            ->and(eventosDe('consultas', AccionAuditoria::EDITAR))->toHaveCount(3); // uno por guardado
    });
    test('guardar sin cambios no deja registros', function () {
        $consulta = hcConsultaEnCurso();
        $antes = LogAuditoria::where('accion', '!=', AccionAuditoria::VER->value)->count();

        hcActualizar($consulta, hcFilasGuardadas($consulta))->assertSessionHasNoErrors();

        expect(LogAuditoria::where('accion', '!=', AccionAuditoria::VER->value)->count())->toBe($antes);
    });
});

describe('historial de cambios en la consulta', function () {
    test('lo ve quien tiene VER sobre Auditoría (además de la historia clínica), y esa lectura queda registrada una vez', function () {
        $consulta = hcConsulta();
        [$auditor] = hcMedico(['apellidos' => 'Paredes', 'nombres' => 'Juan'], 'MP-9', ['HISTORIA_CLINICA' => ['VER'], 'AUDITORIA' => ['VER']]);
        $this->actingAs($auditor);

        $this->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSee('Historial de cambios')->assertSeeInOrder(['Crear', 'Editar'])->assertSee('anamnesis')->assertSee('diagnósticos');
        $this->get(route('admin.consultas.show', $consulta))->assertOk(); // dentro de 5 minutos: no se repite

        expect(LogAuditoria::where('tabla_afectada', 'logs_auditoria')->where('accion', 'VER')->get()->map(fn ($e) => [$e->usuario_id, $e->registro_afectado_id, $e->detalle])->all())
            ->toBe([[$auditor->id, null, "Historial de cambios de la consulta {$consulta->id}"]]);
    });

    test('sin VER sobre Auditoría no aparece ni se registra', function () {
        $consulta = hcConsulta();

        $this->get(route('admin.consultas.show', $consulta))->assertOk()->assertDontSee('Historial de cambios');
        expect(eventosDe('logs_auditoria'))->toBeEmpty();
    });
});

describe('contenido clínico en la pantalla de auditoría', function () {
    test('sin VER sobre la historia clínica: quién, cuándo, la acción y los campos, pero no los valores', function () {
        $consulta = hcConsulta();
        $evento = eventosDe('consultas', AccionAuditoria::EDITAR)->first(fn ($e) => array_key_exists('bloquesAnamnesis', $e->valor_nuevo));

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER']])->create());
        $this->get(route('admin.auditoria.show', $evento))->assertOk()
            ->assertSee('Contenido clínico: se requiere permiso de lectura sobre Historia Clínica')
            ->assertSee('Benítez, Rosa')->assertSee('Editar')->assertSee('bloquesAnamnesis')->assertSee('consultas #'.$consulta->id)
            // Ni el texto ni la identificación de las filas cambiadas, ni el detalle del examen.
            ->assertDontSee('Odinofagia')->assertDontSee('Penicilina')->assertDontSee('Bloque #')->assertDontSee('Diagnóstico #')
            ->assertDontSee('38,2')->assertDontSee('J06.9');

        // El mismo evento lleva el motivo: tampoco se ve.
        $motivo = eventosDe('consultas', AccionAuditoria::EDITAR)->first(fn ($e) => array_key_exists('motivo_consulta', $e->valor_nuevo));
        $this->get(route('admin.auditoria.show', $motivo))->assertOk()
            ->assertSee('motivo_consulta')->assertDontSee('Dolor de garganta');

        // El buscador tampoco encuentra el evento por el texto ni por la identificación de una fila.
        foreach (['Odinofagia', 'Bloque #', 'Penicilina'] as $texto) {
            $this->get(route('admin.auditoria.index', ['q' => $texto]))->assertOk()->assertDontSee(route('admin.auditoria.show', $evento));
        }
    });

    test('con VER sobre la historia clínica se ven los valores', function () {
        hcConsulta();
        $evento = eventosDe('consultas', AccionAuditoria::EDITAR)->first(fn ($e) => array_key_exists('bloquesAnamnesis', $e->valor_nuevo));

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());
        $this->get(route('admin.auditoria.show', $evento))->assertOk()
            ->assertDontSee('Contenido clínico: se requiere')->assertSee('Odinofagia de 24 horas.');
    });

    test('los eventos que no son clínicos se ven como siempre', function () {
        $evento = LogAuditoria::create(['usuario_id' => null, 'tabla_afectada' => 'personas', 'registro_afectado_id' => '1', 'accion' => AccionAuditoria::EDITAR,
            'valor_anterior' => ['apellidos' => 'Duarte'], 'valor_nuevo' => ['apellidos' => 'Duarte Rojas'], 'fecha_hora' => now()]);

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER']])->create());
        $this->get(route('admin.auditoria.show', $evento))->assertOk()->assertSee('Duarte Rojas')->assertDontSee('Contenido clínico');
    });

    test('el buscador no encuentra eventos clínicos por su texto si no tiene VER sobre la historia clínica', function () {
        $clinico = LogAuditoria::create(['usuario_id' => $this->medico->id, 'tabla_afectada' => 'consultas', 'registro_afectado_id' => '77', 'accion' => AccionAuditoria::EDITAR,
            'detalle' => 'Sospecha de embarazo', 'fecha_hora' => now()]);
        $comun = LogAuditoria::create(['usuario_id' => $this->medico->id, 'tabla_afectada' => 'personas', 'registro_afectado_id' => '78', 'accion' => AccionAuditoria::EDITAR,
            'detalle' => 'Sospecha de duplicado', 'fecha_hora' => now()]);
        $detalle = fn ($log) => route('admin.auditoria.show', $log);

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER']])->create());
        $this->get(route('admin.auditoria.index', ['q' => 'Sospecha']))->assertOk()->assertSee($detalle($comun))->assertDontSee($detalle($clinico));
        $this->get(route('admin.auditoria.index', ['q' => 'embarazo']))->assertOk()->assertDontSee($detalle($clinico));
        // Por número de registro sí (no revela contenido), y sin buscar aparece en el listado.
        $this->get(route('admin.auditoria.index', ['q' => '77']))->assertSee($detalle($clinico));
        $this->get(route('admin.auditoria.index'))->assertSee($detalle($clinico));

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());
        $this->get(route('admin.auditoria.index', ['q' => 'embarazo']))->assertSee($detalle($clinico));
    });

    test('el buscador nunca busca dentro de los valores (antes/después)', function () {
        hcConsulta();

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());
        $this->get(route('admin.auditoria.index', ['q' => 'Penicilina']))->assertOk()->assertDontSee('Ver detalle');
    });
});

describe('lecturas (VER)', function () {
    test('listado e historia registran VER de historias_clinicas; la consulta, VER de consultas; sin repetir en 5 minutos', function () {
        $consulta = hcConsulta();
        $hc = (string) $this->historia->id;

        $this->get(route('admin.historias-clinicas.index'))->assertOk();
        $this->get(route('admin.historias-clinicas.index', ['q' => 'Duarte']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk(); // búsqueda en vivo: no
        $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk();
        $this->get(route('admin.consultas.show', $consulta))->assertOk();
        // Repetidas dentro de 5 minutos: no se registran de nuevo.
        $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk();
        $this->get(route('admin.consultas.show', $consulta))->assertOk();

        expect(LogAuditoria::where('accion', 'VER')->orderBy('id')->get()->map(fn ($e) => [$e->tabla_afectada, $e->registro_afectado_id, $e->usuario_id])->all())
            ->toBe([
                ['historias_clinicas', null, $this->medico->id],
                ['historias_clinicas', $hc, $this->medico->id],
                ['consultas', (string) $consulta->id, $this->medico->id],
            ]);

        // Pasados los 5 minutos, sí.
        Carbon::setTestNow(now()->addMinutes(6));
        $this->get(route('admin.consultas.show', $consulta))->assertOk();
        expect(LogAuditoria::where('accion', 'VER')->where('tabla_afectada', 'consultas')->count())->toBe(2);
    });

    test('la pantalla de auditoría muestra el módulo Historia clínica para esos eventos', function () {
        $consulta = hcConsulta();
        $this->get(route('admin.consultas.show', $consulta))->assertOk();

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());
        $this->get(route('admin.auditoria.index', ['modulo' => 'HISTORIA_CLINICA']))->assertOk()->assertSee('Historia clínica')
            ->assertSee(route('admin.auditoria.show', LogAuditoria::where('tabla_afectada', 'consultas')->where('accion', 'VER')->sole()));
    });
});
