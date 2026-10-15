<?php

/*
 * Certificado de reposo médico (docs/reposo-medico.md): quién emite, ve y anula (y quién no: Recepción, Administrador,
 * otro médico), inmutabilidad y huella, anulación con motivo, auditoría, contenido (diagnóstico solo si se eligió; nunca
 * motivo ni síntomas), fechas ("desde" no anterior a hoy, "hasta" calculado) y prórroga. "Ahora": martes 06/10/2026
 * 09:00. Datos inventados. Funciones con el prefijo "rm".
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\ReposoInmutable;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\ReposoMedico;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Reposo\PdfReposo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const RM_PERMISOS = ['REPOSO_MEDICO' => ['VER', 'CREAR', 'EDITAR']];

beforeEach(function () {
    hcEscenario();
    // La Dra. Benítez (profesional de las consultas) y el Dr. Insfrán reciben el módulo completo, como el perfil Médico.
    $this->medico = hcDarPermisos($this->medico, RM_PERMISOS);
    $this->otroMedico = hcDarPermisos($this->otroMedico, RM_PERMISOS);
    $this->admin = User::factory()->administrador()->create();
    $this->supervisor = User::factory()->conPermisos(['REPOSO_MEDICO' => ['VER'], 'CORRECCIONES_HC' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])
        ->conPersona(['apellidos' => 'Rojas', 'nombres' => 'Elena'])->create();
    $this->recepcion = User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'PACIENTES' => ['VER']])
        ->conPersona(['apellidos' => 'Acosta', 'nombres' => 'Marta'])->create();
    $this->actingAs($this->medico);
});

afterEach(fn () => Carbon::setTestNow());

/** Emite un certificado de la consulta con estos datos (sobre los de siempre), como el usuario indicado o el médico. */
function rmEmitir(Consulta $consulta, array $datos = [], ?User $usuario = null)
{
    return test()->actingAs($usuario ?? test()->medico)->post(route('admin.reposos.emitir', $consulta), [
        'dias' => '5', 'desde' => '06/10/2026', 'modalidad' => 'ABSOLUTO', ...$datos,
    ]);
}

function rmReposo(Consulta $consulta, array $datos = []): ReposoMedico
{
    rmEmitir($consulta, $datos)->assertSessionHasNoErrors()->assertSessionHas('status');

    return ReposoMedico::latest('id')->firstOrFail();
}

describe('quién emite', function () {
    test('solo el profesional de la consulta, con la consulta abierta o cerrada', function (string $estado) {
        $consulta = $estado === 'abierta' ? hcConsultaEnCurso() : hcConsulta();

        $respuesta = rmEmitir($consulta);
        $reposo = ReposoMedico::sole();
        $respuesta->assertRedirect(route('admin.reposos.show', $reposo));
        expect($reposo->numero)->toBe('RM-0000001')->and($reposo->usuario_id)->toBe($this->medico->id)
            ->and($reposo->estaEmitido())->toBeTrue()->and($reposo->consulta_id)->toBe($consulta->id);
    })->with(['abierta', 'cerrada']);

    test('no emiten otro médico, el Administrador, Supervisión médica (ni con CREAR) ni Recepción', function () {
        $consulta = hcConsulta();
        $supervisorConCrear = hcDarPermisos($this->supervisor, ['REPOSO_MEDICO' => ['CREAR']]);

        foreach ([$this->otroMedico, $this->admin, $supervisorConCrear, $this->recepcion] as $usuario) {
            rmEmitir($consulta, usuario: $usuario)->assertForbidden();
        }
        expect(ReposoMedico::count())->toBe(0);
    });

    test('la pantalla ofrece el formulario solo al profesional; el enlace "Reposo médico" sale en la consulta abierta', function () {
        $consulta = hcConsulta();

        $this->actingAs($this->medico)->get(route('admin.reposos.consulta', $consulta))->assertOk()->assertSee('Emitir certificado');
        $this->actingAs($this->admin)->get(route('admin.reposos.consulta', $consulta))->assertOk()->assertDontSee('Emitir certificado');
        $this->actingAs($this->medico)->get(route('admin.consultas.show', $consulta))->assertOk()->assertSee(route('admin.reposos.consulta', $consulta), false);

        $abierta = hcConsultaEnCurso();
        $this->actingAs($this->medico)->get(route('admin.consultas.atencion', $abierta))->assertOk()->assertSee(route('admin.reposos.consulta', $abierta), false);
    });
});

describe('quién ve y quién anula', function () {
    test('ven y sacan el PDF: el profesional, el Administrador y Supervisión médica; no otro médico ni Recepción', function () {
        $consulta = hcConsulta();
        $reposo = rmReposo($consulta);

        foreach ([$this->medico, $this->admin, $this->supervisor] as $usuario) {
            $this->actingAs($usuario)->get(route('admin.reposos.consulta', $consulta))->assertOk();
            $this->actingAs($usuario)->get(route('admin.reposos.show', $reposo))->assertOk()->assertSee('RM-0000001');
            $this->actingAs($usuario)->get(route('admin.reposos.pdf', $reposo))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        foreach ([$this->otroMedico, $this->recepcion] as $usuario) {
            $this->actingAs($usuario)->get(route('admin.reposos.consulta', $consulta))->assertForbidden();
            $this->actingAs($usuario)->get(route('admin.reposos.show', $reposo))->assertForbidden();
            $this->actingAs($usuario)->get(route('admin.reposos.pdf', $reposo))->assertForbidden();
        }
    });

    test('anulan con motivo el profesional que lo emitió y el Administrador; no otro médico, Supervisión ni Recepción', function () {
        $consulta = hcConsulta();
        $primero = rmReposo($consulta);
        $segundo = rmReposo($consulta, ['desde' => '20/10/2026']);

        foreach ([$this->otroMedico, $this->supervisor, $this->recepcion] as $usuario) {
            $this->actingAs($usuario)->post(route('admin.reposos.anular', $primero), ['motivo' => 'Motivo cualquiera'])->assertForbidden();
        }
        foreach (['', '   ', 'abc'] as $malo) {
            $this->actingAs($this->medico)->post(route('admin.reposos.anular', $primero), ['motivo' => $malo])->assertSessionHasErrors('motivo');
        }
        expect($primero->fresh()->estaEmitido())->toBeTrue();

        $huella = $primero->huella;
        $this->actingAs($this->medico)->post(route('admin.reposos.anular', $primero), ['motivo' => 'Días mal indicados'])
            ->assertRedirect(route('admin.reposos.show', $primero))->assertSessionHas('status');
        $this->actingAs($this->admin)->post(route('admin.reposos.anular', $segundo), ['motivo' => 'Emitido por error'])->assertSessionHas('status');

        $primero = $primero->fresh();
        expect($primero->estaAnulado())->toBeTrue()->and($primero->motivo_anulacion)->toBe('Días mal indicados')
            ->and($primero->huella)->toBe($huella)->and($primero->huellaValida())->toBeTrue()
            ->and($segundo->fresh()->estaAnulado())->toBeTrue();
        // No se anula dos veces; el anulado se sigue viendo, con su motivo y su PDF marcado ANULADO.
        $this->actingAs($this->medico)->post(route('admin.reposos.anular', $primero), ['motivo' => 'Otra vez más'])->assertForbidden();
        $this->actingAs($this->medico)->get(route('admin.reposos.show', $primero))->assertOk()->assertSee('Días mal indicados');
        expect(PdfReposo::html($primero))->toContain('ANULADO');
        expect(ReposoMedico::count())->toBe(2);
    });
});

describe('inmutabilidad, huella y auditoría', function () {
    test('emitido no se modifica ni se borra; su huella es el sha256 del snapshot', function () {
        $reposo = rmReposo(hcConsulta());

        expect($reposo->huella)->toMatch('/^[0-9a-f]{64}$/')->and($reposo->huellaValida())->toBeTrue()
            ->and($reposo->snapshot['numero'])->toBe($reposo->numero);
        expect(fn () => $reposo->update(['snapshot' => [...$reposo->snapshot, 'dias' => 30]]))->toThrow(ReposoInmutable::class)
            ->and(fn () => $reposo->update(['dias' => 30]))->toThrow(ReposoInmutable::class)
            ->and(fn () => $reposo->update(['huella' => str_repeat('a', 64)]))->toThrow(ReposoInmutable::class)
            ->and(fn () => $reposo->update(['motivo_anulacion' => 'Solo el motivo']))->toThrow(ReposoInmutable::class)
            ->and(fn () => $reposo->delete())->toThrow(ReposoInmutable::class);
        expect(ReposoMedico::count())->toBe(1)->and($reposo->fresh()->huellaValida())->toBeTrue();
    });

    test('si el contenido no coincide con la huella, avisa y no genera el PDF (ni lo registra)', function () {
        $reposo = rmReposo(hcConsulta());
        DB::table('reposos_medicos')->where('id', $reposo->id)->update(['snapshot' => json_encode([...$reposo->snapshot, 'dias' => 30])]);

        $this->actingAs($this->medico)->get(route('admin.reposos.show', $reposo))->assertOk()
            ->assertSee('La huella de este certificado no coincide con su contenido.')->assertDontSee(route('admin.reposos.pdf', $reposo), false);
        $this->actingAs($this->medico)->get(route('admin.reposos.pdf', $reposo))->assertRedirect(route('admin.reposos.show', $reposo));
        expect(LogAuditoria::where('tabla_afectada', 'reposos_medicos')->where('accion', AccionAuditoria::EXPORTAR->value)->count())->toBe(0);
    });

    test('auditoría: CREAR, cada PDF (EXPORTAR) y ANULAR con motivo; sin snapshot; contenido clínico oculto a quien no ve la historia', function () {
        $reposo = rmReposo(hcConsulta());

        $respuesta = $this->actingAs($this->admin)->get(route('admin.reposos.pdf', $reposo))->assertOk();
        expect($respuesta->headers->get('Cache-Control'))->toContain('no-store')->and($respuesta->headers->get('Pragma'))->toBe('no-cache');
        $this->actingAs($this->medico)->get(route('admin.reposos.pdf', $reposo))->assertOk();
        $this->actingAs($this->medico)->post(route('admin.reposos.anular', $reposo), ['motivo' => 'Se emitió por error']);

        $eventos = LogAuditoria::where('tabla_afectada', 'reposos_medicos')->where('accion', '!=', 'VER')->orderBy('id')->get();
        expect($eventos->map(fn ($e) => $e->accion->value)->all())->toBe(['CREAR', 'EXPORTAR', 'EXPORTAR', 'ANULAR'])
            ->and($eventos[1]->detalle)->toBe('PDF del certificado de reposo RM-0000001')
            ->and($eventos[1]->usuario_id)->toBe($this->admin->id)
            ->and($eventos->last()->detalle)->toBe('Motivo: Se emitió por error')
            ->and(json_encode($eventos->pluck('valor_nuevo')))->not->toContain('snapshot');
        expect(Auditoria::permisoDeContenido('reposos_medicos'))->toBe('HISTORIA_CLINICA');
    });
});

describe('contenido', function () {
    test('sin elegir, el diagnóstico no sale; nunca salen el motivo de consulta ni los síntomas', function () {
        $reposo = rmReposo(hcConsulta());
        $html = PdfReposo::html($reposo);

        expect($reposo->snapshot['diagnosticos'])->toBe([]);
        foreach (['J06.9', 'Rinofaringitis', 'Dolor de garganta', 'fiebre', 'Odinofagia', 'Penicilina', '38,2', '38.2'] as $clinico) {
            expect($html)->not->toContain($clinico)->and(json_encode($reposo->snapshot, JSON_UNESCAPED_UNICODE))->not->toContain($clinico);
        }
        expect($html)->toContain('Carmen Duarte')->toContain('FP-0000001')->toContain('Rosa Benítez')->toContain('MP-1')
            ->toContain('Reposo absoluto')->toContain('BORRADOR SUJETO A REVISIÓN')->toContain('Aclaración y sello')
            ->toContain('Se extiende a solicitud del/de la paciente');
    });

    test('elegido, el diagnóstico sale con su CIE-10; uno de otra consulta o retirado se rechaza', function () {
        $consulta = hcConsulta();
        $diagnostico = $consulta->diagnosticos()->sole();
        $otro = hcConsulta()->diagnosticos()->sole();

        rmEmitir($consulta, ['diagnosticos' => [$otro->id]])->assertSessionHasErrors('diagnosticos');
        expect(ReposoMedico::count())->toBe(0);

        $reposo = rmReposo($consulta, ['diagnosticos' => [$diagnostico->id]]);
        expect($reposo->snapshot['diagnosticos'])->toBe([['codigo' => 'J06.9', 'descripcion' => 'Rinofaringitis aguda']])
            ->and(PdfReposo::html($reposo))->toContain('J06.9 — Rinofaringitis aguda')->not->toContain('Dolor de garganta');
    });

    test('"hasta" se calcula con ambas fechas inclusive; sin tope de días; "desde" anterior a hoy se rechaza', function () {
        $consulta = hcConsulta();

        expect(rmReposo($consulta, ['dias' => '5'])->hasta->format('Y-m-d'))->toBe('2026-10-10')
            ->and(rmReposo($consulta, ['dias' => '1', 'desde' => '07/10/2026'])->hasta->format('Y-m-d'))->toBe('2026-10-07')
            ->and(rmReposo($consulta, ['dias' => '400', 'desde' => '31/12/2026'])->hasta->format('Y-m-d'))->toBe('2028-02-03');

        $antes = ReposoMedico::count();
        foreach ([['desde' => '05/10/2026'], ['dias' => '0'], ['dias' => '-3'], ['dias' => '2.5'], ['dias' => ''], ['desde' => '2026-10-06'],
            ['modalidad' => 'INVENTADA'], ['modalidad' => 'OTRA', 'modalidad_otra' => '']] as $malo) {
            rmEmitir($consulta, $malo)->assertSessionHasErrors();
        }
        expect(ReposoMedico::count())->toBe($antes);

        $otra = rmReposo($consulta, ['modalidad' => 'OTRA', 'modalidad_otra' => 'Sin esfuerzos físicos', 'empleador' => 'Empresa Inventada S.A.', 'observaciones' => 'Control en una semana.']);
        expect(PdfReposo::html($otra))->toContain('Sin esfuerzos físicos')->toContain('Empresa Inventada S.A.')->toContain('Control en una semana.')
            ->toContain('06/10/2026'); // fecha de atención
        // Con todos los datos (y un diagnóstico), entra en una sola hoja, con las líneas de firma.
        $pdf = $this->actingAs($this->medico)->get(route('admin.reposos.pdf', rmReposo($consulta, ['modalidad' => 'OTRA', 'modalidad_otra' => 'Sin esfuerzos físicos',
            'empleador' => 'Empresa Inventada S.A.', 'observaciones' => "Control en una semana.
Hidratación.", 'diagnosticos' => [$consulta->diagnosticos()->sole()->id]])))->getContent();
        expect(preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf))->toBe(1);
    });

    test('el texto libre sale escapado', function () {
        $reposo = rmReposo(hcConsulta(), ['observaciones' => '<script>alert(1)</script>']);

        expect(PdfReposo::html($reposo))->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    });
});

describe('prórroga', function () {
    test('vale si el anterior está emitido, es del mismo paciente y la prórroga empieza después de su "hasta"', function () {
        $consulta = hcConsulta();
        $anterior = rmReposo($consulta); // 06/10 al 10/10

        rmEmitir($consulta, ['prorroga_de' => 'RM-0000099', 'desde' => '11/10/2026'])->assertSessionHasErrors('prorroga_de');
        rmEmitir($consulta, ['prorroga_de' => $anterior->numero, 'desde' => '10/10/2026'])->assertSessionHasErrors('prorroga_de');
        expect(ReposoMedico::count())->toBe(1);

        $prorroga = rmReposo($consulta, ['prorroga_de' => $anterior->numero, 'desde' => '11/10/2026', 'dias' => '3']);
        expect($prorroga->prorroga_de_id)->toBe($anterior->id)->and($prorroga->snapshot['prorroga_de'])->toBe('RM-0000001')
            ->and(PdfReposo::html($prorroga))->toContain('RM-0000001');
    });

    test('no vale de un certificado anulado ni de otro paciente', function () {
        $consulta = hcConsulta();
        $anulado = rmReposo($consulta);
        $this->post(route('admin.reposos.anular', $anulado), ['motivo' => 'Emitido por error']);

        // Otro paciente, atendido por la misma profesional.
        $otraPaciente = epPaciente(['apellidos' => 'Gómez', 'nombres' => 'Lidia'], 'FP-0000002');
        $this->historia = $otraPaciente->historiaClinica;
        $deOtra = rmReposo(hcConsulta());

        rmEmitir($consulta, ['prorroga_de' => $anulado->numero, 'desde' => '20/10/2026'])->assertSessionHasErrors('prorroga_de');
        rmEmitir($consulta, ['prorroga_de' => $deOtra->numero, 'desde' => '20/10/2026'])->assertSessionHasErrors('prorroga_de');
        expect(ReposoMedico::count())->toBe(2)->and(ReposoMedico::where('estado_id', Estado::idDe(Estado::EMITIDO))->count())->toBe(1);
    });
});
