<?php

/*
 * Pantalla Consulta del profesional con entre pacientes y urgencias: los botones y sus contadores (cada 15 s, sin
 * contenido clínico ni VER), los popups (Entre pacientes sin VER; Urgencias con VER por consulta, regla de 5 minutos),
 * Atender los tres tipos y la confirmación de una urgencia con otra consulta en curso. "Ahora": martes 06/10/2026
 * 09:00 en Paraguay (12:00 UTC). Datos inventados.
 */

use App\Enums\AccionAuditoria;
use App\Enums\TipoTurno;
use App\Http\Controllers\Admin\AtencionController;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\Turno;
use App\Models\User;
use App\Support\Atencion\Atender;
use Illuminate\Support\Carbon;

beforeEach(function () {
    epEscenario();
    $this->actingAs($this->medico);
    $this->ajax = ['X-Requested-With' => 'XMLHttpRequest'];
});

afterEach(fn () => Carbon::setTestNow());

function pantallaContadores(): array
{
    return test()->getJson(route('admin.atencion.contadores'), test()->ajax)->assertOk()->json();
}

function pantallaVer(): int
{
    return LogAuditoria::where('accion', AccionAuditoria::VER->value)->count();
}

describe('contadores', function () {
    test('entre pacientes: PENDIENTE, CONFIRMADO y SALTADO de hoy, propios', function () {
        foreach ([Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO] as $i => $estado) {
            epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => "Cuenta{$i}", 'nombres' => 'Ana'], "FP-10{$i}")->id, 'estado_id' => Estado::idDe($estado)]);
        }
        foreach ([Estado::EN_CONSULTA, Estado::ATENDIDO, Estado::CANCELADO, Estado::AUSENTE] as $i => $estado) {
            epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => "NoCuenta{$i}", 'nombres' => 'Ana'], "FP-20{$i}")->id, 'estado_id' => Estado::idDe($estado)]);
        }
        epTurno(cambios: ['profesional_id' => $this->otroProfesional->id]); // ajeno
        epTurno(cambios: ['fecha' => '2026-10-05']); // de ayer
        hcTurno(); // con horario

        expect(pantallaContadores()['entre_pacientes'])->toBe(3);
    });

    test('urgencias: URGENCIA o triaje urgente, una vez por turno, sin estados no pendientes, solo propias', function () {
        $urgencia = epTurno(TipoTurno::URGENCIA);
        epTriaje($urgencia, urgente: true, observacion: 'Dolor'); // URGENCIA y además triaje urgente: cuenta UNA vez
        epTriaje(hcTurno(['paciente_id' => $this->otraPaciente->id]), urgente: true); // normal marcado urgente
        epTriaje(hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30', 'paciente_id' => epPaciente(['apellidos' => 'Normal', 'nombres' => 'Sin'], 'FP-300')->id])); // triaje no urgente
        epTriaje(epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => 'Entre', 'nombres' => 'Urgida'], 'FP-301')->id]), urgente: true); // entre pacientes urgente: cuenta
        foreach ([Estado::EN_CONSULTA, Estado::ATENDIDO, Estado::CANCELADO, Estado::AUSENTE] as $i => $estado) {
            epTurno(TipoTurno::URGENCIA, ['paciente_id' => epPaciente(['apellidos' => "Cerrada{$i}", 'nombres' => 'Ana'], "FP-40{$i}")->id, 'estado_id' => Estado::idDe($estado)]);
        }
        epTurno(TipoTurno::URGENCIA, ['profesional_id' => $this->otroProfesional->id]); // ajena
        // Triaje urgente anulado: no cuenta.
        $anulada = epTriaje(hcTurno(['hora_inicio' => '11:00', 'hora_fin' => '11:30', 'paciente_id' => epPaciente(['apellidos' => 'Anulada', 'nombres' => 'Ana'], 'FP-500')->id]), urgente: true);
        $anulada->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO)])->save();

        $antes = pantallaVer();
        $contadores = pantallaContadores();

        expect($contadores['urgencias'])->toBe(3)
            ->and($contadores['entre_pacientes'])->toBe(1)
            ->and(array_keys($contadores))->toBe(['entre_pacientes', 'urgencias', 'ultima'])
            ->and(array_keys($contadores['ultima']))->toBe(['id', 'nombre'])
            ->and(json_encode($contadores))->not->toContain('Dolor')
            ->and(pantallaVer())->toBe($antes); // no registra VER
    });

    test('la última urgencia es la más reciente (registro o marca del triaje)', function () {
        $vieja = epTurno(TipoTurno::URGENCIA, ['llegada_en' => '2026-10-06 11:00:00']); // 08:00 en Paraguay
        $marcada = hcTurno(['paciente_id' => $this->otraPaciente->id]);
        epTriaje($marcada, urgente: true, marcaUtc: '2026-10-06 11:30:00');

        expect(pantallaContadores()['ultima'])->toBe(['id' => $marcada->id, 'nombre' => 'Gómez, Lidia']);

        $nueva = epTurno(TipoTurno::URGENCIA, ['paciente_id' => epPaciente(['apellidos' => 'Nueva', 'nombres' => 'Eva'], 'FP-600')->id, 'llegada_en' => '2026-10-06 11:45:00']);
        expect(pantallaContadores()['ultima'])->toBe(['id' => $nueva->id, 'nombre' => 'Nueva, Eva']);
    });

    test('quien no es profesional activo: 403; sesión vencida: no responde datos', function () {
        $this->actingAs(User::factory()->conPermisos(['HISTORIA_CLINICA' => ['VER']])->create());
        $this->getJson(route('admin.atencion.contadores'), $this->ajax)->assertForbidden();

        auth()->logout();
        $this->getJson(route('admin.atencion.contadores'), $this->ajax)->assertUnauthorized();
    });
});

describe('pantalla', function () {
    test('los tres botones con sus N; la agenda de hoy solo con turnos con horario', function () {
        hcTurno();
        epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);
        epTurno(TipoTurno::URGENCIA, ['paciente_id' => epPaciente(['apellidos' => 'Urgida', 'nombres' => 'Dora'], 'FP-700')->id]);

        $html = $this->get(route('admin.atencion.index'))->assertOk()
            ->assertSeeInOrder(['Atender sin turno', 'Entre pacientes (', '1', ')', 'Urgencias (', '1', ')'], false)
            ->getContent();

        $agenda = substr($html, strpos($html, 'titulo-agenda'), strpos($html, 'titulo-por-llamar') - strpos($html, 'titulo-agenda'));
        expect($agenda)->toContain('Duarte, Carmen')->not->toContain('Gómez, Lidia')->not->toContain('Urgida, Dora');
    });

    test('en consulta y atendidos muestran la pastilla del tipo', function () {
        $turno = epTurno();
        hcFinalizar(hcEnCurso($turno), hcDatos())->assertSessionHasNoErrors();
        $urgencia = epTurno(TipoTurno::URGENCIA, ['paciente_id' => $this->otraPaciente->id]);
        hcEnCurso($urgencia);

        $this->get(route('admin.atencion.index'))->assertOk()
            ->assertSeeInOrder(['En consulta', 'tipo-urgencia', 'Gómez, Lidia', 'Atendidos hoy', 'tipo-entre-pacientes', 'Duarte, Carmen'], false);
        $this->get(route('admin.consultas.atencion', Consulta::where('turno_id', $urgencia->id)->sole()))->assertOk()->assertSee('data-pastilla="tipo-urgencia"', false);
    });
});

describe('popups', function () {
    test('Entre pacientes: por orden de carga, con edad, ficha, hora de carga, triaje y signos; sin observación ni VER', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-06 11:10:00', 'UTC'));
        $primero = epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);
        epTriaje($primero, alterados: true, observacion: 'OBSERVACION-NO-VA');
        Carbon::setTestNow(Carbon::parse('2026-10-06 11:20:00', 'UTC'));
        $segundo = epTurno();
        epTriaje($segundo, terminado: false);
        Carbon::setTestNow(Carbon::parse('2026-10-06 11:30:00', 'UTC'));
        epTurno(cambios: ['paciente_id' => epPaciente(['apellidos' => 'Tercera', 'nombres' => 'Iris'], 'FP-800')->id, 'estado_id' => Estado::idDe(Estado::SALTADO)]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $antes = pantallaVer();
        $this->get(route('admin.atencion.entre-pacientes'), $this->ajax)->assertOk()
            ->assertHeader('Cache-Control')
            ->assertSeeInOrder(['08:10', 'Gómez, Lidia', '41 años', 'Ficha FP-0000002', 'Con triaje', 'Signos alterados', 'Atender', 'No se presentó',
                '08:20', 'Duarte, Carmen', '36 años', 'En triaje', 'Atender', 'No se presentó',
                '08:30', 'Tercera, Iris', 'No se presentó', 'Sin triaje', 'Atender', 'Pasar a ausente'])
            ->assertDontSee('OBSERVACION-NO-VA');
        expect(pantallaVer())->toBe($antes);

        // Sin AJAX: a la pantalla.
        $this->get(route('admin.atencion.entre-pacientes'))->assertRedirect(route('admin.atencion.index'));
    });

    test('Urgencias: origen, hora de registro o marca, triaje, signos y observación; la más antigua primero', function () {
        $recepcion = epTurno(TipoTurno::URGENCIA, ['llegada_en' => '2026-10-06 11:40:00']);
        epTriaje($recepcion, urgente: true, observacion: 'Sangrado abundante.', alterados: true, marcaUtc: '2026-10-06 11:50:00');
        $triaje = hcTurno(['paciente_id' => $this->otraPaciente->id]);
        epTriaje($triaje, urgente: true, observacion: 'Fiebre alta.', marcaUtc: '2026-10-06 11:20:00');
        epTurno(TipoTurno::URGENCIA, ['paciente_id' => epPaciente(['apellidos' => 'Sin', 'nombres' => 'Triaje'], 'FP-900')->id, 'llegada_en' => '2026-10-06 11:55:00']);

        $this->get(route('admin.atencion.urgencias'), $this->ajax)->assertOk()
            ->assertSeeInOrder(['08:20', 'Gómez, Lidia', 'Triaje', 'Con triaje', 'Atender', 'Fiebre alta.',
                '08:40', 'Duarte, Carmen', 'Recepción', 'Con triaje', 'Signos alterados', 'Atender', 'Sangrado abundante.',
                '08:55', 'Sin, Triaje', 'Recepción', 'Sin triaje', 'Atender']);
    });

    test('Urgencias registra un VER por consulta cuya observación se muestra, con la regla de 5 minutos', function () {
        $a = epTriaje(epTurno(TipoTurno::URGENCIA), urgente: true, observacion: 'Una.');
        $b = epTriaje(hcTurno(['paciente_id' => $this->otraPaciente->id]), urgente: true, observacion: 'Otra.');
        epTriaje(epTurno(TipoTurno::URGENCIA, ['paciente_id' => epPaciente(['apellidos' => 'Sin', 'nombres' => 'Obs'], 'FP-901')->id]), urgente: false);

        $lecturas = fn () => LogAuditoria::where('accion', AccionAuditoria::VER->value)->where('tabla_afectada', 'consultas')
            ->where('detalle', AtencionController::LECTURA_URGENCIAS)->orderBy('registro_afectado_id')->pluck('registro_afectado_id')->map(fn ($id) => (int) $id)->all();

        $this->get(route('admin.atencion.urgencias'), $this->ajax)->assertOk();
        expect($lecturas())->toBe([$a->id, $b->id]);

        Carbon::setTestNow(now()->addMinutes(3));
        $this->get(route('admin.atencion.urgencias'), $this->ajax)->assertOk();
        expect($lecturas())->toHaveCount(2);

        Carbon::setTestNow(now()->addMinutes(3)); // 6 minutos después de la primera
        $this->get(route('admin.atencion.urgencias'), $this->ajax)->assertOk();
        expect($lecturas())->toHaveCount(4);
    });

    test('solo lo propio: el otro profesional no ve los míos', function () {
        epTriaje(epTurno(TipoTurno::URGENCIA), urgente: true, observacion: 'SOLO-DE-BENITEZ');
        epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]);

        $this->actingAs($this->otroMedico);
        $this->get(route('admin.atencion.urgencias'), $this->ajax)->assertOk()->assertDontSee('SOLO-DE-BENITEZ')->assertSee('No hay urgencias por atender.');
        $this->get(route('admin.atencion.entre-pacientes'), $this->ajax)->assertOk()->assertDontSee('Gómez, Lidia');
        expect(pantallaContadores())->toMatchArray(['entre_pacientes' => 0, 'urgencias' => 0, 'ultima' => null]);
    });
});

describe('atender', function () {
    test('los tres tipos con el mismo servicio, sin validar la hora; el turno pasa a EN_CONSULTA', function (TipoTurno $tipo) {
        $turno = $tipo === TipoTurno::NORMAL ? hcTurno(['hora_inicio' => '11:00', 'hora_fin' => '11:30']) : epTurno($tipo);

        $this->post(route('admin.atencion.atender', $turno))->assertRedirect();
        expect($turno->fresh()->estado->codigo)->toBe(Estado::EN_CONSULTA)
            ->and(Consulta::where('turno_id', $turno->id)->sole()->enCurso())->toBeTrue();
    })->with([TipoTurno::NORMAL, TipoTurno::ENTRE_PACIENTES, TipoTurno::URGENCIA]);

    test('una urgencia con otra consulta en curso pide confirmación; el servidor la exige y queda en la auditoría', function () {
        $enCurso = hcEnCurso(hcTurno());
        $urgencia = epTurno(TipoTurno::URGENCIA, ['paciente_id' => $this->otraPaciente->id]);

        $this->post(route('admin.atencion.atender', $urgencia))->assertRedirect(route('admin.atencion.index'))
            ->assertSessionHas('confirmar_urgencia', ['turno' => $urgencia->id, 'mensaje' => 'Tiene una consulta en curso con Carmen Duarte. ¿Atender la urgencia igualmente?']);
        expect($urgencia->fresh()->estado->codigo)->toBe(Estado::PENDIENTE)
            ->and(Consulta::where('turno_id', $urgencia->id)->exists())->toBeFalse();

        $this->followingRedirects()->post(route('admin.atencion.atender', $urgencia))
            ->assertSee('Tiene una consulta en curso con Carmen Duarte. ¿Atender la urgencia igualmente?')
            ->assertSee('Atender la urgencia igualmente');

        $this->post(route('admin.atencion.atender', $urgencia), ['confirmar_urgencia' => '1'])->assertRedirect();
        expect($urgencia->fresh()->estado->codigo)->toBe(Estado::EN_CONSULTA)
            ->and($enCurso->fresh()->enCurso())->toBeTrue(); // la otra queda abierta

        $evento = LogAuditoria::where('tabla_afectada', 'turnos')->where('registro_afectado_id', $urgencia->id)->where('accion', 'EDITAR')->latest('id')->first();
        expect($evento->detalle)->toBe("Atender. Urgencia con la consulta #{$enCurso->id} en curso, confirmado");
    });

    test('un turno marcado urgente por el triaje también pide confirmación; uno normal, no', function () {
        hcEnCurso(hcTurno());
        $normal = hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30', 'paciente_id' => $this->otraPaciente->id]);
        $marcado = hcTurno(['hora_inicio' => '11:00', 'hora_fin' => '11:30', 'paciente_id' => epPaciente(['apellidos' => 'Marcada', 'nombres' => 'Ana'], 'FP-950')->id]);
        epTriaje($marcado, urgente: true);

        $this->post(route('admin.atencion.atender', $marcado))->assertSessionHas('confirmar_urgencia');
        $this->post(route('admin.atencion.atender', $normal))->assertSessionMissing('confirmar_urgencia');
        expect($normal->fresh()->estado->codigo)->toBe(Estado::EN_CONSULTA);
    });

    test('turno de otro profesional: 403 al atender; y el servicio no confirma nada ajeno', function () {
        $ajena = epTurno(TipoTurno::URGENCIA, ['profesional_id' => $this->otroProfesional->id]);
        $this->post(route('admin.atencion.atender', $ajena))->assertForbidden();
        $this->post(route('admin.atencion.atender', $ajena), ['confirmar_urgencia' => '1'])->assertForbidden();
        $this->post(route('admin.atencion.no-se-presento', epTurno(cambios: ['profesional_id' => $this->otroProfesional->id, 'paciente_id' => $this->otraPaciente->id])))->assertForbidden();
        expect($ajena->fresh()->estado->codigo)->toBe(Estado::PENDIENTE);
    });

    test('la constante del mensaje es la del pedido', function () {
        expect(Atender::CONFIRMAR_URGENCIA)->toBe('Tiene una consulta en curso con %s. ¿Atender la urgencia igualmente?');
    });
});
