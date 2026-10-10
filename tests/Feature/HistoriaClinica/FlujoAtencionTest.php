<?php

/*
 * El flujo de atención (App\Support\Atencion): transiciones del turno en un solo lugar (Turno::pasarA) y
 * un servicio por acción, cada uno en una transacción, con la regla revalidada adentro. "Ahora": martes
 * 06/10/2026 09:00 en Paraguay (escenario.php).
 */

use App\Enums\AccionAuditoria;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\Turno;
use App\Models\User;
use App\Support\Atencion\CerrarJornada;
use App\Support\Atencion\DeshacerAtencion;
use App\Support\Atencion\Finalizar;
use Illuminate\Support\Carbon;

beforeEach(function () {
    hcEscenario();
    // El médico también marca "No se presentó" y cierra su jornada (EDITAR sobre Turnos).
    $this->medico = hcDarPermisos($this->medico, ['TURNOS' => ['VER', 'EDITAR']]);
    $this->actingAs($this->medico);
});

afterEach(fn () => Carbon::setTestNow());

function codigoTurno(Turno $turno): string
{
    return $turno->fresh()->estado->codigo;
}

function codigoConsulta(Consulta $consulta): string
{
    return $consulta->fresh()->estado->codigo;
}

/** Los eventos (sin lecturas) de una tabla y registro, como [acción, detalle]. */
function eventosDelRegistro(string $tabla, int $id): array
{
    return LogAuditoria::where('tabla_afectada', $tabla)->where('registro_afectado_id', (string) $id)->where('accion', '!=', 'VER')
        ->orderBy('id')->get()->map(fn ($e) => [$e->accion->value, $e->detalle])->all();
}

describe('transiciones del turno (Turno::pasarA)', function () {
    test('la tabla de transiciones es la de la especificación', function () {
        expect(Turno::TRANSICIONES)->toBe([
            'PENDIENTE' => ['CONFIRMADO', 'SALTADO', 'EN_CONSULTA', 'AUSENTE', 'CANCELADO'],
            'CONFIRMADO' => ['SALTADO', 'EN_CONSULTA', 'AUSENTE', 'CANCELADO'],
            'SALTADO' => ['EN_CONSULTA', 'AUSENTE', 'CANCELADO'],
            'EN_CONSULTA' => ['ATENDIDO', 'PENDIENTE'],
        ])->and(collect(Turno::ACCIONES)->pluck(0)->all())->toBe(['CONFIRMADO', 'AUSENTE', 'CANCELADO']);
    });

    test('una transición inválida lanza una excepción y no cambia nada', function (string $desde, string $hasta) {
        $turno = hcTurno(['estado_id' => Estado::idDe($desde)]);

        expect(fn () => $turno->pasarA($hasta))->toThrow(DomainException::class);
        expect(codigoTurno($turno))->toBe($desde);
    })->with([
        ['ATENDIDO', 'PENDIENTE'], ['CANCELADO', 'CONFIRMADO'], ['AUSENTE', 'EN_CONSULTA'],
        ['EN_CONSULTA', 'CANCELADO'], ['EN_CONSULTA', 'AUSENTE'], ['SALTADO', 'CONFIRMADO'], ['PENDIENTE', 'ATENDIDO'],
    ]);

    test('cancelar o pasar a ausente anula la preparación (EN_PREPARACION) en la misma transacción', function (string $accion, string $estado) {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno))->assertRedirect();
        $consulta = Consulta::where('turno_id', $turno->id)->sole();

        $this->from(route('admin.turnos.index'))->patch(route('admin.turnos.estado', $turno), ['accion' => $accion])->assertSessionHas('status');

        expect(codigoTurno($turno))->toBe($estado)->and(codigoConsulta($consulta))->toBe('ANULADO');
    })->with([['cancelar', 'CANCELADO'], ['ausente', 'AUSENTE']]);

    test('si anular la preparación falla, el turno tampoco cambia (misma transacción)', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno));
        Consulta::updating(fn () => throw new RuntimeException('falla simulada'));

        expect(fn () => $turno->fresh()->pasarA(Estado::CANCELADO))->toThrow(RuntimeException::class, 'falla simulada');
        expect(codigoTurno($turno))->toBe('CONFIRMADO');
    });

    test('el listado de Turnos ya no ofrece "Atender": sus botones nunca llevan a SALTADO, EN_CONSULTA ni ATENDIDO', function () {
        $turno = hcTurno();
        $html = $this->get(route('admin.turnos.index'))->assertOk()->getContent();

        expect($html)->not->toContain(route('admin.atencion.atender', $turno))->not->toContain('>Atender<');
        foreach (['atender', 'saltar', 'en_consulta', 'atendido'] as $accion) {
            $this->from(route('admin.turnos.index'))->patch(route('admin.turnos.estado', $turno), ['accion' => $accion])->assertSessionHasErrors('accion');
        }
        expect(codigoTurno($turno))->toBe('CONFIRMADO');
    });
});

describe('Preparar, Marcar como lista y Reabrir', function () {
    test('Preparar crea la consulta EN_PREPARACION del turno (CREAR "Preparar"); repetirlo la retoma', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno))->assertRedirect();
        $consulta = Consulta::where('turno_id', $turno->id)->sole();

        expect(codigoConsulta($consulta))->toBe('EN_PREPARACION')
            ->and($consulta->iniciada_en)->toBeNull()
            ->and($consulta->profesional_id)->toBe($this->profesional->id)
            ->and(codigoTurno($turno))->toBe('CONFIRMADO')
            ->and(eventosDelRegistro('consultas', $consulta->id))->toBe([['CREAR', 'Preparar']]);

        $this->post(route('admin.preparacion.preparar', $turno))->assertRedirect(route('admin.preparacion.formulario', $consulta));
        expect(Consulta::count())->toBe(1);
    });

    test('no se prepara un turno de otro día, cancelado, o de un paciente inactivo', function (Closure $preparar) {
        $turno = $preparar($this);
        $this->post(route('admin.preparacion.preparar', $turno))->assertForbidden();
        expect(Consulta::count())->toBe(0);
    })->with([
        'de mañana' => [fn ($t) => hcTurno(['fecha' => '2026-10-07'])],
        'cancelado' => [fn ($t) => hcTurno(['estado_id' => Estado::idDe(Estado::CANCELADO)])],
        'paciente inactivo' => [function ($t) {
            $t->paciente->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);

            return hcTurno();
        }],
    ]);

    test('Marcar como lista y Reabrir: EDITAR con su detalle; solo con la consulta en preparación', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno));
        $consulta = Consulta::where('turno_id', $turno->id)->sole();

        $this->post(route('admin.preparacion.lista', $consulta))->assertRedirect(route('admin.preparacion.formulario', $consulta));
        expect($consulta->fresh()->preparada_en)->not->toBeNull();
        $this->post(route('admin.preparacion.reabrir', $consulta))->assertRedirect();
        expect($consulta->fresh()->preparada_en)->toBeNull()
            ->and(array_slice(eventosDelRegistro('consultas', $consulta->id), 1))->toBe([['EDITAR', 'Marcar como lista'], ['EDITAR', 'Reabrir la preparación']]);

        $this->post(route('admin.atencion.atender', $turno));
        $this->post(route('admin.preparacion.lista', $consulta))->assertForbidden();
    });
});

describe('Atender', function () {
    test('un turno de hoy: consulta EN_CURSO desde ahora y turno EN_CONSULTA; con preparación, la retoma', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno));
        $consulta = Consulta::where('turno_id', $turno->id)->sole();
        hcAutoguardar($consulta, ['con_anamnesis' => '1', 'anamnesis' => [['uid' => 'n', 'id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'Penicilina.']]])->assertOk();

        Carbon::setTestNow(now()->addMinutes(5));
        $this->post(route('admin.atencion.atender', $turno))->assertRedirect(route('admin.consultas.atencion', $consulta));

        $consulta->refresh();
        expect(codigoConsulta($consulta))->toBe('EN_CURSO')
            ->and($consulta->iniciada_en->format('Y-m-d H:i'))->toBe('2026-10-06 12:05')
            ->and(codigoTurno($turno))->toBe('EN_CONSULTA')
            ->and($consulta->bloquesAnamnesis()->sole()->contenido)->toBe('Penicilina.')
            ->and(LogAuditoria::where('tabla_afectada', 'consultas')->where('detalle', 'Atender')->count())->toBe(1)
            ->and(LogAuditoria::where('tabla_afectada', 'turnos')->where('registro_afectado_id', (string) $turno->id)->where('detalle', 'Atender')->count())->toBe(1);
    });

    test('desde PENDIENTE, CONFIRMADO o SALTADO', function (string $estado) {
        $turno = hcTurno(['estado_id' => Estado::idDe($estado)]);
        $this->post(route('admin.atencion.atender', $turno))->assertRedirect();
        expect(codigoTurno($turno))->toBe('EN_CONSULTA');
    })->with(['PENDIENTE', 'CONFIRMADO', 'SALTADO']);

    test('doble clic: el segundo envío vuelve a la misma consulta, sin crear otra', function () {
        $turno = hcTurno();
        $this->post(route('admin.atencion.atender', $turno));
        $this->post(route('admin.atencion.atender', $turno))->assertRedirect(route('admin.consultas.atencion', Consulta::sole()));
        expect(Consulta::count())->toBe(1);
    });

    test('un turno que no corresponde: 403, sin consulta', function (Closure $turno) {
        $turno = $turno($this);
        $this->post(route('admin.atencion.atender', $turno))->assertForbidden();
        expect(Consulta::count())->toBe(0);
    })->with([
        'de otro profesional' => [fn ($t) => hcTurno(['profesional_id' => $t->otroProfesional->id])],
        'de mañana' => [fn ($t) => hcTurno(['fecha' => '2026-10-07'])],
        'de ayer' => [fn ($t) => hcTurno(['fecha' => '2026-10-05'])],
        'cancelado' => [fn ($t) => hcTurno(['estado_id' => Estado::idDe(Estado::CANCELADO)])],
        'ausente' => [fn ($t) => hcTurno(['estado_id' => Estado::idDe(Estado::AUSENTE)])],
        'atendido' => [fn ($t) => hcTurno(['estado_id' => Estado::idDe(Estado::ATENDIDO)])],
    ]);

    test('consulta y turno van en la misma transacción: si falla el turno, no queda la consulta', function () {
        $turno = hcTurno();
        Turno::updating(fn () => throw new RuntimeException('falla simulada'));

        $this->withoutExceptionHandling();
        expect(fn () => $this->post(route('admin.atencion.atender', $turno)))->toThrow(RuntimeException::class, 'falla simulada');
        expect(Consulta::count())->toBe(0)->and(codigoTurno($turno))->toBe('CONFIRMADO');
    });

    test('la regla se revalida dentro de la transacción: si el turno se cancela justo antes, 403 y nada cambia', function () {
        $turno = hcTurno();
        $cancelado = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use ($turno, &$cancelado) {
            if (! $cancelado) {
                $cancelado = true;
                Turno::whereKey($turno->id)->toBase()->update(['estado_id' => Estado::idDe(Estado::CANCELADO)]); // otra ventana
            }
        });

        $this->post(route('admin.atencion.atender', $turno))->assertForbidden();
        // (En los tests, la "otra ventana" corre dentro del mismo savepoint y el rechazo también la deshace.)
        expect($cancelado)->toBeTrue()->and(Consulta::count())->toBe(0)->and(codigoTurno($turno))->not->toBe('EN_CONSULTA');
    });

    test('un turno inexistente da 404', function () {
        $this->post(route('admin.atencion.atender', 999))->assertNotFound();
    });

    test('Atender sin turno: consulta EN_CURSO sin turno (CREAR "Atender sin turno")', function () {
        $this->post(route('admin.atencion.atender-sin-turno', $this->historia))->assertRedirect();
        $consulta = Consulta::sole();

        expect(codigoConsulta($consulta))->toBe('EN_CURSO')
            ->and($consulta->turno_id)->toBeNull()
            ->and($consulta->profesional_id)->toBe($this->profesional->id)
            ->and(eventosDelRegistro('consultas', $consulta->id))->toBe([['CREAR', 'Atender sin turno']]);
    });
});

describe('No se presentó', function () {
    test('PENDIENTE o CONFIRMADO de hoy pasa a SALTADO ("Por llamar de nuevo"), con su detalle', function (string $estado) {
        $turno = hcTurno(['estado_id' => Estado::idDe($estado)]);
        $this->post(route('admin.atencion.no-se-presento', $turno))->assertRedirect(route('admin.atencion.index'));

        expect(codigoTurno($turno))->toBe('SALTADO')
            ->and(eventosDelRegistro('turnos', $turno->id))->toContain(['EDITAR', 'No se presentó']);
    })->with(['PENDIENTE', 'CONFIRMADO']);

    test('no aplica a un turno de otro profesional, de otro día, ya saltado o sin EDITAR sobre Turnos', function () {
        $this->post(route('admin.atencion.no-se-presento', hcTurno(['profesional_id' => $this->otroProfesional->id])))->assertForbidden();
        $this->post(route('admin.atencion.no-se-presento', hcTurno(['fecha' => '2026-10-07', 'hora_inicio' => '09:00', 'hora_fin' => '09:30'])))->assertForbidden();
        $this->post(route('admin.atencion.no-se-presento', $saltado = hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30', 'estado_id' => Estado::idDe(Estado::SALTADO)])))->assertForbidden();
        expect(codigoTurno($saltado))->toBe('SALTADO');

        $this->actingAs(hcConPerfil($this->medico, HC_PERMISOS_MEDICO)); // sin EDITAR sobre Turnos
        $this->post(route('admin.atencion.no-se-presento', hcTurno(['hora_inicio' => '11:00', 'hora_fin' => '11:30'])))->assertForbidden();
    });
});

describe('Cerrar jornada', function () {
    test('vista previa y cierre: los PENDIENTE, CONFIRMADO y SALTADO de hoy y de días anteriores pasan a AUSENTE; sus preparaciones se anulan', function () {
        $hoy = hcTurno();
        $ayer = hcTurno(['fecha' => '2026-10-05', 'estado_id' => Estado::idDe(Estado::PENDIENTE)]);
        $saltado = hcTurno(['hora_inicio' => '07:00', 'hora_fin' => '07:30', 'estado_id' => Estado::idDe(Estado::SALTADO)]);
        $manana = hcTurno(['fecha' => '2026-10-07']);
        $atendido = hcTurno(['hora_inicio' => '11:00', 'hora_fin' => '11:30', 'estado_id' => Estado::idDe(Estado::ATENDIDO)]);
        $ajeno = hcTurno(['profesional_id' => $this->otroProfesional->id, 'consultorio_id' => $this->consultorio->id, 'hora_inicio' => '12:00', 'hora_fin' => '12:30']);
        $this->post(route('admin.preparacion.preparar', $hoy));
        $preparacion = Consulta::where('turno_id', $hoy->id)->sole();

        $this->get(route('admin.atencion.cerrar-jornada'))->assertOk()
            ->assertSee('Estos 3 turnos pasarán a ausente')->assertSeeInOrder(['05/10/2026 08:00', '06/10/2026 07:00', '06/10/2026 08:00']);

        $this->post(route('admin.atencion.cerrar-jornada.confirmar'))->assertRedirect(route('admin.atencion.index'))
            ->assertSessionHas('status', 'Jornada cerrada: 3 turnos pasaron a ausente.');

        expect([codigoTurno($hoy), codigoTurno($ayer), codigoTurno($saltado)])->toBe(['AUSENTE', 'AUSENTE', 'AUSENTE'])
            ->and([codigoTurno($manana), codigoTurno($atendido), codigoTurno($ajeno)])->toBe(['CONFIRMADO', 'ATENDIDO', 'CONFIRMADO'])
            ->and(codigoConsulta($preparacion))->toBe('ANULADO')
            ->and(LogAuditoria::where('tabla_afectada', 'turnos')->where('detalle', 'Cierre de jornada')->count())->toBe(3)
            ->and(eventosDelRegistro('consultas', $preparacion->id))->toContain(['ANULAR', 'Cierre de jornada']);
    });

    test('con una consulta en curso se rechaza, sin cerrar nada', function () {
        $pendiente = hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30']);
        hcEnCurso();

        $this->get(route('admin.atencion.cerrar-jornada'))->assertSee(CerrarJornada::CON_CONSULTAS_EN_CURSO)->assertDontSee('>Cerrar jornada</button>', false);
        $this->from(route('admin.atencion.cerrar-jornada'))->post(route('admin.atencion.cerrar-jornada.confirmar'))
            ->assertSessionHas('error', CerrarJornada::CON_CONSULTAS_EN_CURSO);
        expect(codigoTurno($pendiente))->toBe('CONFIRMADO');
    });

    test('sin turnos que cerrar avisa; sin ser profesional, 403', function () {
        $this->post(route('admin.atencion.cerrar-jornada.confirmar'))->assertSessionHas('status', 'No había turnos para cerrar.');

        $this->actingAs(User::factory()->administrador()->create());
        $this->post(route('admin.atencion.cerrar-jornada.confirmar'))->assertForbidden();
    });
});

describe('Deshacer atención', function () {
    test('con turno y sin contenido clínico: la consulta vuelve a preparación (con lo preparado) y el turno a PENDIENTE', function () {
        $turno = hcTurno();
        $consulta = hcEnCurso($turno);
        hcAutoguardar($consulta, ['examen' => ['temperatura' => '37,5']])->assertOk();

        $this->post(route('admin.consultas.deshacer', $consulta))->assertRedirect(route('admin.atencion.index'))->assertSessionHas('status');

        expect(codigoConsulta($consulta))->toBe('EN_PREPARACION')
            ->and($consulta->fresh()->iniciada_en)->toBeNull()
            ->and((float) $consulta->fresh()->examenFisico->temperatura)->toBe(37.5)
            ->and(codigoTurno($turno))->toBe('PENDIENTE')
            ->and(eventosDelRegistro('consultas', $consulta->id))->toContain(['EDITAR', 'Deshacer atención']);
    });

    test('con contenido clínico no se deshace', function (array $datos) {
        $turno = hcTurno();
        $consulta = hcEnCurso($turno);
        hcAutoguardar($consulta, $datos)->assertOk();

        $this->from(route('admin.consultas.atencion', $consulta))->post(route('admin.consultas.deshacer', $consulta))
            ->assertSessionHas('error', DeshacerAtencion::CON_CONTENIDO);
        expect(codigoConsulta($consulta))->toBe('EN_CURSO')->and(codigoTurno($turno))->toBe('EN_CONSULTA');
    })->with([
        'motivo' => [['motivo_consulta' => 'Cefalea.']],
        'hallazgos' => [['examen' => ['hallazgos' => 'Faringe congestiva.']]],
        'diagnóstico' => [['con_diagnosticos' => '1', 'diagnosticos' => [['uid' => 'n', 'id' => '', 'activo' => '1', 'codigo_cie10' => 'R51', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => '']], 'diagnostico_principal' => '0']],
    ]);

    test('sin turno y vacía: se anula, y desaparece de todos lados (404 al abrirla)', function () {
        $consulta = hcEnCurso();

        $this->post(route('admin.consultas.deshacer', $consulta))->assertSessionHas('status', 'Atención sin turno deshecha.');

        expect(codigoConsulta($consulta))->toBe('ANULADO');
        $this->get(route('admin.consultas.show', $consulta))->assertNotFound();
        $this->get(route('admin.consultas.atencion', $consulta))->assertNotFound();
        $this->get(route('admin.consultas.detalle', $consulta), ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
        $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()->assertSee('Todavía no hay consultas en esta historia.');
        $this->get(route('admin.historias-clinicas.index'))->assertOk()->assertDontSee('06/10/2026 09:00');
    });

    test('sin turno con algo de preparación cargado no se anula', function () {
        $consulta = hcEnCurso();
        hcAutoguardar($consulta, ['examen' => ['peso' => '60']])->assertOk();

        $this->from(route('admin.consultas.atencion', $consulta))->post(route('admin.consultas.deshacer', $consulta))->assertSessionHas('error');
        expect(codigoConsulta($consulta))->toBe('EN_CURSO');
    });

    test('solo el profesional de la consulta', function () {
        $consulta = hcEnCurso();
        $this->actingAs($this->otroMedico)->post(route('admin.consultas.deshacer', $consulta))->assertForbidden();
        expect(codigoConsulta($consulta))->toBe('EN_CURSO');
    });
});

describe('Finalizar', function () {
    test('con turno: FINALIZADO y el turno ATENDIDO; va a la lectura con "Consulta finalizada."', function () {
        $turno = hcTurno();
        $consulta = hcEnCurso($turno);
        Carbon::setTestNow(now()->addMinutes(12));

        hcFinalizar($consulta, hcDatos())->assertRedirect(route('admin.consultas.show', $consulta))->assertSessionHas('status', 'Consulta finalizada.');

        expect(codigoConsulta($consulta))->toBe('FINALIZADO')
            ->and($consulta->fresh()->finalizada_en->format('H:i'))->toBe('12:12')
            ->and(codigoTurno($turno))->toBe('ATENDIDO')
            ->and($consulta->fresh()->motivo_consulta)->toBe('Dolor de garganta y fiebre desde ayer.');
    });

    test('validación completa: sin motivo no finaliza y queda EN_CURSO, con el error en su campo', function () {
        $consulta = hcEnCurso();
        hcFinalizar($consulta, hcDatos(['motivo_consulta' => '']))->assertRedirect(route('admin.consultas.atencion', $consulta))
            ->assertSessionHasErrors('motivo_consulta');
        expect(codigoConsulta($consulta))->toBe('EN_CURSO');

        // La pantalla abre la sección con el error marcada.
        $this->get(route('admin.consultas.atencion', $consulta))->assertViewHas('panelInicial', 'motivo')
            ->assertViewHas('seccionesConError', ['motivo']);
    });

    test('doble envío: el segundo encuentra la consulta cerrada (409) y no escribe nada ni deja eventos', function () {
        $consulta = hcEnCurso();
        $version = $consulta->version();
        hcFinalizar($consulta, hcDatos(), $version)->assertSessionHasNoErrors();
        $eventos = LogAuditoria::where('accion', '!=', 'VER')->count();

        hcFinalizar($consulta, hcDatos(['motivo_consulta' => 'Otro.']), $version)->assertStatus(409)->assertSee(\App\Exceptions\ConsultaCerrada::MENSAJE);
        expect(LogAuditoria::where('accion', '!=', 'VER')->count())->toBe($eventos)
            ->and($consulta->fresh()->motivo_consulta)->toBe('Dolor de garganta y fiebre desde ayer.');
    });

    test('todo en una transacción: si falla el turno al pasar a ATENDIDO, la consulta queda EN_CURSO y sin lo enviado', function () {
        $turno = hcTurno();
        $consulta = hcEnCurso($turno);
        Turno::updating(fn () => throw new RuntimeException('falla simulada'));

        $this->withoutExceptionHandling();
        expect(fn () => hcFinalizar($consulta, hcDatos()))->toThrow(RuntimeException::class, 'falla simulada');
        expect(codigoConsulta($consulta))->toBe('EN_CURSO')
            ->and($consulta->fresh()->motivo_consulta)->toBeNull()
            ->and($consulta->bloquesAnamnesis()->count())->toBe(0)
            ->and(codigoTurno($turno))->toBe('EN_CONSULTA');
    });

    test('con una receta en borrador no se finaliza: hay que emitirla o anularla antes; después sí', function (string $resolver) {
        $this->medico = hcDarPermisos($this->medico, HC_PERMISOS_RECETAS);
        $this->actingAs($this->medico);
        $consulta = hcEnCurso();
        $receta = hcReceta($consulta);
        $eventos = LogAuditoria::where('tabla_afectada', 'consultas')->where('accion', '!=', 'VER')->count();

        hcFinalizar($consulta, hcDatos())->assertRedirect(route('admin.consultas.atencion', $consulta))
            ->assertSessionHas('error', Finalizar::CON_BORRADORES);
        expect(codigoConsulta($consulta))->toBe('EN_CURSO')
            ->and($consulta->fresh()->motivo_consulta)->toBeNull()
            ->and(LogAuditoria::where('tabla_afectada', 'consultas')->where('accion', '!=', 'VER')->count())->toBe($eventos);

        $resolver === 'emitir'
            ? hcEmitir($receta)->assertSessionHasNoErrors()
            : $this->post(route('admin.recetas.anular', $receta), ['motivo' => 'Ya no hace falta'])->assertSessionHasNoErrors();
        hcFinalizar($consulta, hcDatos())->assertSessionHas('status', 'Consulta finalizada.')->assertSessionMissing('aviso');
        expect(codigoConsulta($consulta))->toBe('FINALIZADO');
    })->with(['emitiéndola' => ['emitir'], 'anulándola' => ['anular']]);

    test('con una versión vieja (otra ventana guardó) no finaliza', function () {
        $consulta = hcEnCurso();
        $vieja = $consulta->version();
        Carbon::setTestNow(now()->addSecond());
        hcAutoguardar($consulta, ['motivo_consulta' => 'Desde otra ventana.'])->assertOk();

        hcFinalizar($consulta, hcDatos(), $vieja)->assertSessionHas('error', \App\Support\Atencion\Autoguardado::VERSION_VIEJA);
        expect(codigoConsulta($consulta))->toBe('EN_CURSO');
    });

    test('solo el profesional de la consulta: otro profesional recibe 403, también fuera de EN_CURSO', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno));
        $preparacion = Consulta::sole();

        $consulta = hcEnCurso();
        $this->actingAs($this->otroMedico);
        hcFinalizar($consulta, hcDatos())->assertForbidden();
        hcFinalizar($preparacion, hcDatos())->assertForbidden();
        expect(codigoConsulta($consulta))->toBe('EN_CURSO');
    });

    test('fuera de EN_CURSO (Deshacer atención en otra pestaña): 409 con el aviso y no finaliza', function (bool $conTurno) {
        $turno = $conTurno ? hcTurno() : null;
        $consulta = hcEnCurso($turno);
        $version = $consulta->version(); // la pestaña de la atención quedó abierta con esta versión

        // Otra pestaña deshace la atención: con turno vuelve a preparación; sin turno se anula.
        $this->post(route('admin.consultas.deshacer', $consulta))->assertRedirect();
        expect(codigoConsulta($consulta))->toBe($conTurno ? 'EN_PREPARACION' : 'ANULADO');
        $eventos = LogAuditoria::where('accion', '!=', 'VER')->count();

        // Con turno vuelve a preparación ("ya no está en curso"); sin turno se anula y queda cerrada.
        hcFinalizar($consulta, hcDatos(), $version)->assertStatus(409)->assertSee($conTurno ? Finalizar::NO_EN_CURSO : \App\Exceptions\ConsultaCerrada::MENSAJE);

        expect(codigoConsulta($consulta))->toBe($conTurno ? 'EN_PREPARACION' : 'ANULADO')
            ->and($consulta->fresh()->motivo_consulta)->toBeNull()
            ->and(LogAuditoria::where('accion', '!=', 'VER')->count())->toBe($eventos);
        if ($turno) {
            expect(codigoTurno($turno))->toBe('PENDIENTE');
        }
    })->with(['con turno' => [true], 'sin turno' => [false]]);

    test('si la consulta deja de estar en curso entre el pedido y el bloqueo, el servicio también da 409', function () {
        $consulta = hcEnCurso(hcTurno());
        // Otra pestaña deshace justo antes de que Finalizar bloquee la consulta.
        $hecho = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use ($consulta, &$hecho) {
            if (! $hecho) {
                $hecho = true;
                Consulta::whereKey($consulta->id)->toBase()->update(['estado_id' => Estado::idDe(Estado::EN_PREPARACION)]);
            }
        });

        hcFinalizar($consulta, hcDatos())->assertStatus(409);
        expect($consulta->fresh()->motivo_consulta)->toBeNull();
    });
});

describe('Finalizar con el turno cambiado', function () {
    test('si el turno ya no estaba EN_CONSULTA: se finaliza, el turno no cambia, aviso visible y el detalle lo dice', function () {
        $turno = hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30']);
        $consulta = hcEnCurso($turno);
        // El turno cambió por otra vía (no se puede desde la aplicación: se simula en la base).
        Turno::whereKey($turno->id)->toBase()->update(['estado_id' => Estado::idDe(Estado::PENDIENTE)]);

        $aviso = 'El turno de las 10:00 estaba en estado PENDIENTE y no se marcó como atendido. Revíselo en Turnos.';
        hcFinalizar($consulta, hcDatos())->assertRedirect(route('admin.consultas.show', $consulta))
            ->assertSessionHas('status', 'Consulta finalizada.')->assertSessionHas('aviso', $aviso);

        expect(codigoConsulta($consulta))->toBe('FINALIZADO')
            ->and(codigoTurno($turno))->toBe('PENDIENTE')
            ->and(LogAuditoria::where('tabla_afectada', 'consultas')->where('accion', 'EDITAR')->sole()->detalle)->toBe('Finalizar (turno PENDIENTE, sin cambios)')
            ->and(LogAuditoria::where('tabla_afectada', 'turnos')->where('registro_afectado_id', (string) $turno->id)->where('detalle', 'like', 'Finalizar%')->count())->toBe(0);

        // El aviso se ve en la página a la que vuelve (una sola vez: es un mensaje "flash").
        $this->get(route('admin.consultas.show', $consulta))->assertSee($aviso);
        $this->get(route('admin.consultas.show', $consulta))->assertDontSee($aviso);
    });

    test('con el turno EN_CONSULTA: el detalle dice que pasó a ATENDIDO y no hay aviso', function () {
        $turno = hcTurno();
        $consulta = hcEnCurso($turno);

        hcFinalizar($consulta, hcDatos())->assertSessionHas('status', 'Consulta finalizada.')->assertSessionMissing('aviso');

        expect(codigoTurno($turno))->toBe('ATENDIDO')
            ->and(LogAuditoria::where('tabla_afectada', 'consultas')->where('accion', 'EDITAR')->sole()->detalle)->toBe('Finalizar (turno ATENDIDO)');
    });

    test('Turnos sigue sin ningún botón que lleve a ATENDIDO, aunque el turno tenga su consulta finalizada', function () {
        $this->medico = hcDarPermisos($this->medico, ['TURNOS' => ['VER', 'EDITAR']]);
        $this->actingAs($this->medico);
        $turno = hcTurno();
        $consulta = hcEnCurso($turno);
        Turno::whereKey($turno->id)->toBase()->update(['estado_id' => Estado::idDe(Estado::PENDIENTE)]);
        hcFinalizar($consulta, hcDatos());

        $html = $this->get(route('admin.turnos.index'))->assertOk()->getContent();
        expect($html)->not->toContain('value="atendido"')->not->toContain('>Atendido</button>')
            ->and(collect(Turno::ACCIONES)->pluck(0)->all())->not->toContain(Estado::ATENDIDO);
    });
});

describe('una consulta finalizada queda cerrada', function () {
    test('no hay edición: ni rutas, ni botón; la lectura dice que está cerrada', function () {
        $consulta = hcConsulta();
        $rutas = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->values();

        expect($rutas)->not->toContain('admin.consultas.edit')->not->toContain('admin.consultas.update')
            ->and(class_exists(\App\Support\Atencion\GuardarCambios::class))->toBeFalse();
        $this->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSee('Consulta cerrada: no puede modificarse.')->assertDontSee('Guardar cambios')->assertDontSee('>Editar<', false);
        $this->get('/admin/consultas/'.$consulta->id.'/edit')->assertNotFound();
        $this->put('/admin/consultas/'.$consulta->id, hcFilasGuardadas($consulta))->assertStatus(405);
    });

    test('la pantalla de atención de una finalizada manda a la lectura', function () {
        $consulta = hcConsulta();
        $this->get(route('admin.consultas.atencion', $consulta))->assertRedirect(route('admin.consultas.show', $consulta));
    });
});
