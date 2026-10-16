<?php

/*
 * Orden de estudio (docs/ordenes-estudio.md): quién emite, ve y anula (y quién no: Recepción, Administrador, otro médico),
 * inmutabilidad y huella, anulación con motivo, auditoría, contenido (diagnóstico solo si se eligió; nunca motivo ni
 * síntomas), estudio escrito a mano, varios estudios y el catálogo (un estudio desactivado no se ofrece, pero la orden ya
 * emitida lo conserva). "Ahora": el del escenario de la historia clínica. Datos inventados. Funciones con el prefijo "oe".
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\OrdenEstudioInmutable;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Estudio;
use App\Models\LogAuditoria;
use App\Models\OrdenEstudio;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\OrdenEstudio\PdfOrden;
use App\Support\PerfilesPredefinidos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

const OE_PERMISOS = ['ORDENES_ESTUDIO' => ['VER', 'CREAR', 'EDITAR'], 'ESTUDIOS' => ['VER']];

beforeEach(function () {
    hcEscenario();
    // La Dra. Benítez (profesional de las consultas) y el Dr. Insfrán reciben el módulo, como el perfil Médico.
    $this->medico = hcDarPermisos($this->medico, OE_PERMISOS);
    $this->otroMedico = hcDarPermisos($this->otroMedico, OE_PERMISOS);
    $this->admin = User::factory()->administrador()->create();
    $this->supervisor = User::factory()->conPermisos(['ORDENES_ESTUDIO' => ['VER'], 'CORRECCIONES_HC' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])
        ->conPersona(['apellidos' => 'Rojas', 'nombres' => 'Elena'])->create();
    $this->recepcion = User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'PACIENTES' => ['VER']])
        ->conPersona(['apellidos' => 'Acosta', 'nombres' => 'Marta'])->create();
    $this->hemograma = oeEstudio('Estudio inventado A');
    $this->actingAs($this->medico);
});

afterEach(fn () => Carbon::setTestNow());

function oeEstudio(string $nombre, string $estado = Estado::ACTIVO): Estudio
{
    return Estudio::create(['nombre' => $nombre, 'estado_id' => Estado::idDe($estado)]);
}

/** Emite una orden de la consulta con estos datos (por defecto, el estudio A del catálogo), como el usuario indicado o el médico. */
function oeEmitir(Consulta $consulta, ?array $datos = null, ?User $usuario = null)
{
    return test()->actingAs($usuario ?? test()->medico)->post(route('admin.ordenes-estudio.emitir', $consulta), $datos ?? ['estudios' => [test()->hemograma->id]]);
}

function oeOrden(Consulta $consulta, ?array $datos = null): OrdenEstudio
{
    oeEmitir($consulta, $datos)->assertSessionHasNoErrors()->assertSessionHas('status');

    return OrdenEstudio::latest('id')->firstOrFail();
}

describe('quién emite', function () {
    test('solo el profesional de la consulta, con la consulta abierta o cerrada', function (string $estado) {
        $consulta = $estado === 'abierta' ? hcConsultaEnCurso() : hcConsulta();

        $respuesta = oeEmitir($consulta);
        $orden = OrdenEstudio::sole();
        $respuesta->assertRedirect(route('admin.ordenes-estudio.show', $orden));
        expect($orden->numero)->toBe('OE-0000001')->and($orden->usuario_id)->toBe($this->medico->id)
            ->and($orden->estaEmitida())->toBeTrue()->and($orden->consulta_id)->toBe($consulta->id);
    })->with(['abierta', 'cerrada']);

    test('no emiten otro médico, el Administrador, Supervisión médica (ni con CREAR) ni Recepción', function () {
        $consulta = hcConsulta();
        $supervisorConCrear = hcDarPermisos($this->supervisor, ['ORDENES_ESTUDIO' => ['CREAR']]);

        foreach ([$this->otroMedico, $this->admin, $supervisorConCrear, $this->recepcion] as $usuario) {
            oeEmitir($consulta, usuario: $usuario)->assertForbidden();
        }
        expect(OrdenEstudio::count())->toBe(0);
    });

    test('la pantalla ofrece el formulario solo al profesional; el enlace sale en la consulta abierta y en la cerrada', function () {
        $consulta = hcConsulta();

        $this->actingAs($this->medico)->get(route('admin.ordenes-estudio.consulta', $consulta))->assertOk()->assertSee('Emitir orden');
        $this->actingAs($this->admin)->get(route('admin.ordenes-estudio.consulta', $consulta))->assertOk()->assertDontSee('Emitir orden');
        $this->actingAs($this->medico)->get(route('admin.consultas.show', $consulta))->assertOk()->assertSee(route('admin.ordenes-estudio.consulta', $consulta), false);

        $abierta = hcConsultaEnCurso();
        $this->actingAs($this->medico)->get(route('admin.consultas.atencion', $abierta))->assertOk()->assertSee(route('admin.ordenes-estudio.consulta', $abierta), false);
        $this->actingAs($this->recepcion)->get(route('admin.ordenes-estudio.consulta', $abierta))->assertForbidden();
    });
});

describe('quién ve y quién anula', function () {
    test('ven y sacan el PDF: el profesional, el Administrador y Supervisión médica; no otro médico ni Recepción', function () {
        $consulta = hcConsulta();
        $orden = oeOrden($consulta);

        foreach ([$this->medico, $this->admin, $this->supervisor] as $usuario) {
            $this->actingAs($usuario)->get(route('admin.ordenes-estudio.consulta', $consulta))->assertOk();
            $this->actingAs($usuario)->get(route('admin.ordenes-estudio.show', $orden))->assertOk()->assertSee('OE-0000001');
            $this->actingAs($usuario)->get(route('admin.ordenes-estudio.pdf', $orden))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        foreach ([$this->otroMedico, $this->recepcion] as $usuario) {
            $this->actingAs($usuario)->get(route('admin.ordenes-estudio.consulta', $consulta))->assertForbidden();
            $this->actingAs($usuario)->get(route('admin.ordenes-estudio.show', $orden))->assertForbidden();
            $this->actingAs($usuario)->get(route('admin.ordenes-estudio.pdf', $orden))->assertForbidden();
        }
    });

    test('anulan con motivo el profesional que la emitió y el Administrador; no otro médico, Supervisión ni Recepción', function () {
        $consulta = hcConsulta();
        $primera = oeOrden($consulta);
        $segunda = oeOrden($consulta);

        foreach ([$this->otroMedico, $this->supervisor, $this->recepcion] as $usuario) {
            $this->actingAs($usuario)->post(route('admin.ordenes-estudio.anular', $primera), ['motivo' => 'Motivo cualquiera'])->assertForbidden();
        }
        foreach (['', '   ', 'abc'] as $malo) {
            $this->actingAs($this->medico)->post(route('admin.ordenes-estudio.anular', $primera), ['motivo' => $malo])->assertSessionHasErrors('motivo');
        }
        expect($primera->fresh()->estaEmitida())->toBeTrue();

        $huella = $primera->huella;
        $this->actingAs($this->medico)->post(route('admin.ordenes-estudio.anular', $primera), ['motivo' => 'Estudio mal indicado'])
            ->assertRedirect(route('admin.ordenes-estudio.show', $primera))->assertSessionHas('status');
        $this->actingAs($this->admin)->post(route('admin.ordenes-estudio.anular', $segunda), ['motivo' => 'Emitida por error'])->assertSessionHas('status');

        $primera = $primera->fresh();
        expect($primera->estaAnulada())->toBeTrue()->and($primera->motivo_anulacion)->toBe('Estudio mal indicado')
            ->and($primera->huella)->toBe($huella)->and($primera->huellaValida())->toBeTrue()
            ->and($segunda->fresh()->estaAnulada())->toBeTrue();
        // No se anula dos veces; la anulada se sigue viendo, con su motivo y su PDF marcado ANULADA.
        $this->actingAs($this->medico)->post(route('admin.ordenes-estudio.anular', $primera), ['motivo' => 'Otra vez más'])->assertForbidden();
        $this->actingAs($this->medico)->get(route('admin.ordenes-estudio.show', $primera))->assertOk()->assertSee('Estudio mal indicado');
        expect(PdfOrden::html($primera))->toContain('ANULADA');
        expect(OrdenEstudio::count())->toBe(2);
    });
});

describe('inmutabilidad, huella y auditoría', function () {
    test('emitida no se modifica ni se borra; su huella es el sha256 del snapshot', function () {
        $orden = oeOrden(hcConsulta());

        expect($orden->huella)->toMatch('/^[0-9a-f]{64}$/')->and($orden->huellaValida())->toBeTrue()
            ->and($orden->snapshot['numero'])->toBe($orden->numero);
        expect(fn () => $orden->update(['snapshot' => [...$orden->snapshot, 'estudios' => [['nombre' => 'Otro', 'estudio_id' => null]]]]))->toThrow(OrdenEstudioInmutable::class)
            ->and(fn () => $orden->update(['fecha' => '2026-01-01']))->toThrow(OrdenEstudioInmutable::class)
            ->and(fn () => $orden->update(['huella' => str_repeat('a', 64)]))->toThrow(OrdenEstudioInmutable::class)
            ->and(fn () => $orden->update(['motivo_anulacion' => 'Solo el motivo']))->toThrow(OrdenEstudioInmutable::class)
            ->and(fn () => $orden->delete())->toThrow(OrdenEstudioInmutable::class);
        expect(OrdenEstudio::count())->toBe(1)->and($orden->fresh()->huellaValida())->toBeTrue();
    });

    test('si el contenido no coincide con la huella, avisa y no genera el PDF (ni lo registra)', function () {
        $orden = oeOrden(hcConsulta());
        DB::table('ordenes_estudio')->where('id', $orden->id)->update(['snapshot' => json_encode([...$orden->snapshot, 'indicacion_clinica' => 'Cambiada'])]);

        $this->actingAs($this->medico)->get(route('admin.ordenes-estudio.show', $orden))->assertOk()
            ->assertSee('La huella de esta orden no coincide con su contenido.')->assertDontSee(route('admin.ordenes-estudio.pdf', $orden), false);
        $this->actingAs($this->medico)->get(route('admin.ordenes-estudio.pdf', $orden))->assertRedirect(route('admin.ordenes-estudio.show', $orden));
        expect(LogAuditoria::where('tabla_afectada', 'ordenes_estudio')->where('accion', AccionAuditoria::EXPORTAR->value)->count())->toBe(0);
    });

    test('auditoría: CREAR, cada PDF (EXPORTAR) y ANULAR con motivo; sin snapshot; contenido clínico oculto a quien no ve la historia', function () {
        $orden = oeOrden(hcConsulta());

        $respuesta = $this->actingAs($this->admin)->get(route('admin.ordenes-estudio.pdf', $orden))->assertOk();
        expect($respuesta->headers->get('Cache-Control'))->toContain('no-store')->and($respuesta->headers->get('Pragma'))->toBe('no-cache');
        $this->actingAs($this->medico)->get(route('admin.ordenes-estudio.pdf', $orden))->assertOk();
        $this->actingAs($this->medico)->post(route('admin.ordenes-estudio.anular', $orden), ['motivo' => 'Se emitió por error']);

        $eventos = LogAuditoria::where('tabla_afectada', 'ordenes_estudio')->where('accion', '!=', 'VER')->orderBy('id')->get();
        expect($eventos->map(fn ($e) => $e->accion->value)->all())->toBe(['CREAR', 'EXPORTAR', 'EXPORTAR', 'ANULAR'])
            ->and($eventos[1]->detalle)->toBe('PDF de la orden de estudio OE-0000001')
            ->and($eventos[1]->usuario_id)->toBe($this->admin->id)
            ->and($eventos->last()->detalle)->toBe('Motivo: Se emitió por error')
            ->and(json_encode($eventos->pluck('valor_nuevo')))->not->toContain('snapshot');
        expect(Auditoria::permisoDeContenido('ordenes_estudio'))->toBe('HISTORIA_CLINICA');
    });
});

describe('contenido', function () {
    test('sin elegir, el diagnóstico no sale; nunca salen el motivo de consulta ni los síntomas', function () {
        $orden = oeOrden(hcConsulta());
        $html = PdfOrden::html($orden);

        expect($orden->snapshot['diagnosticos'])->toBe([]);
        foreach (['J06.9', 'Rinofaringitis', 'Dolor de garganta', 'fiebre', 'Odinofagia', 'Penicilina', '38,2', '38.2'] as $clinico) {
            expect($html)->not->toContain($clinico)->and(json_encode($orden->snapshot, JSON_UNESCAPED_UNICODE))->not->toContain($clinico);
        }
        expect($html)->toContain('Carmen Duarte')->toContain('FP-0000001')->toContain('Rosa Benítez')->toContain('MP-1')
            ->toContain('Estudio inventado A')->toContain('Estudios solicitados')->toContain('BORRADOR SUJETO A REVISIÓN')
            ->toContain('Aclaración y sello')->toContain('Fecha de nacimiento')
            ->not->toContain('Diagnóstico presuntivo')->not->toContain('Indicación clínica');
    });

    test('elegido, el diagnóstico sale con su CIE-10; uno de otra consulta se rechaza', function () {
        $consulta = hcConsulta();
        $diagnostico = $consulta->diagnosticos()->sole();
        $otro = hcConsulta()->diagnosticos()->sole();

        oeEmitir($consulta, ['estudios' => [$this->hemograma->id], 'diagnosticos' => [$otro->id]])->assertSessionHasErrors('diagnosticos');
        expect(OrdenEstudio::count())->toBe(0);

        $orden = oeOrden($consulta, ['estudios' => [$this->hemograma->id], 'diagnosticos' => [$diagnostico->id]]);
        expect($orden->snapshot['diagnosticos'])->toBe([['codigo' => 'J06.9', 'descripcion' => 'Rinofaringitis aguda']])
            ->and(PdfOrden::html($orden))->toContain('Diagnóstico presuntivo (CIE-10)')->toContain('J06.9 — Rinofaringitis aguda')->not->toContain('Dolor de garganta');
    });

    test('varios estudios del catálogo y escritos a mano; los escritos no se agregan al catálogo', function () {
        $otroDelCatalogo = oeEstudio('Estudio inventado B');
        $orden = oeOrden(hcConsulta(), [
            'estudios' => [$otroDelCatalogo->id, $this->hemograma->id],
            'otros' => "Estudio escrito a mano\n\n  estudio ESCRITO a mano  \nOtro estudio escrito",
            'indicacion_clinica' => "Control previo.\nEn ayunas.",
        ]);

        expect($orden->nombresEstudios())->toBe(['Estudio inventado A', 'Estudio inventado B', 'Estudio escrito a mano', 'Otro estudio escrito'])
            ->and(array_column($orden->snapshot['estudios'], 'estudio_id'))->toBe([$this->hemograma->id, $otroDelCatalogo->id, null, null])
            ->and($orden->snapshot['indicacion_clinica'])->toBe("Control previo.\nEn ayunas.")
            ->and(Estudio::count())->toBe(2);
        $html = PdfOrden::html($orden);
        expect($html)->toContain('Estudio escrito a mano')->toContain('Otro estudio escrito')->toContain('Indicación clínica')->toContain('En ayunas.');
        // Solo con un estudio escrito, sin catálogo, también se emite.
        expect(oeOrden(hcConsulta(), ['otros' => 'Solo escrito'])->nombresEstudios())->toBe(['Solo escrito']);
        // Con todos los estudios y datos, entra en una sola hoja con las líneas de firma.
        $pdf = $this->actingAs($this->medico)->get(route('admin.ordenes-estudio.pdf', $orden))->getContent();
        expect(preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf))->toBe(1);
    });

    test('sin ningún estudio, con un estudio inexistente o con uno demasiado largo no se emite', function () {
        $consulta = hcConsulta();

        foreach ([[], ['otros' => "  \n  "], ['estudios' => [999999]], ['otros' => str_repeat('x', Estudio::MAXIMO_NOMBRE + 1)]] as $malo) {
            oeEmitir($consulta, $malo)->assertSessionHasErrors();
        }
        expect(OrdenEstudio::count())->toBe(0);
    });

    test('el texto libre sale escapado', function () {
        $orden = oeOrden(hcConsulta(), ['otros' => '<b>Estudio</b>', 'indicacion_clinica' => '<script>alert(1)</script>']);

        expect(PdfOrden::html($orden))->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->not->toContain('<b>Estudio</b>');
    });

    test('el PDF lleva el cartel de borrador arriba y en el pie, sin marca diagonal que tape campos', function () {
        $html = PdfOrden::html(oeOrden(hcConsulta()));

        expect($html)->toContain('class="cartel"')->not->toContain('rotate(')->not->toContain('class="marca');
    });
});

describe('el catálogo de estudios', function () {
    test('nace vacío: ningún seeder ni migración carga estudios', function () {
        $archivos = collect(File::allFiles(database_path('seeders')))->merge(File::allFiles(database_path('migrations')));
        $conEstudios = $archivos->filter(fn ($archivo) => preg_match('/Estudio::(create|firstOrCreate|updateOrCreate|insert)|table\(\'estudios\'\)->insert/', File::get($archivo->getPathname())));

        expect($conEstudios->all())->toBe([]);
    });

    test('un estudio desactivado no se ofrece ni se acepta, pero la orden ya emitida lo conserva', function () {
        $consulta = hcConsulta();
        $orden = oeOrden($consulta);

        $this->actingAs($this->admin)->patch(route('admin.estudios.desactivar', $this->hemograma))->assertRedirect();
        $this->actingAs($this->admin)->put(route('admin.estudios.update', $this->hemograma), ['nombre' => 'Nombre cambiado', 'estado_id' => $this->hemograma->fresh()->estado_id])->assertRedirect();

        $this->actingAs($this->medico)->get(route('admin.ordenes-estudio.consulta', $consulta))->assertOk()
            ->assertDontSee('name="estudios[]"', false)->assertSee('El catálogo no tiene estudios activos.');
        oeEmitir($consulta)->assertSessionHasErrors('estudios');
        expect(OrdenEstudio::count())->toBe(1);

        $orden = $orden->fresh();
        expect($orden->nombresEstudios())->toBe(['Estudio inventado A'])->and($orden->huellaValida())->toBeTrue()
            ->and(PdfOrden::html($orden))->toContain('Estudio inventado A')->not->toContain('Nombre cambiado');
    });

    test('lo administran Supervisión médica y el Administrador; el Médico solo lo ve; Recepción nada; nada se borra', function () {
        $supervision = User::factory()->conPermisos(PerfilesPredefinidos::MATRIZ[PerfilesPredefinidos::SUPERVISION_MEDICA])->create();
        foreach ([$supervision, $this->admin] as $n => $usuario) {
            $this->actingAs($usuario)->post(route('admin.estudios.store'), ['nombre' => "Estudio de prueba {$n}"])->assertRedirect(route('admin.estudios.index'));
            $estudio = Estudio::where('nombre', "Estudio de prueba {$n}")->sole();
            $this->actingAs($usuario)->patch(route('admin.estudios.desactivar', $estudio))->assertRedirect();
            expect($estudio->fresh()->estaActivo())->toBeFalse();
        }

        $medico = User::factory()->conPermisos(PerfilesPredefinidos::MATRIZ[PerfilesPredefinidos::MEDICO])->create();
        $this->actingAs($medico)->get(route('admin.estudios.index'))->assertOk()->assertSee('Estudio inventado A');
        $this->actingAs($medico)->post(route('admin.estudios.store'), ['nombre' => 'Otro'])->assertForbidden();
        $this->actingAs($medico)->patch(route('admin.estudios.desactivar', $this->hemograma))->assertForbidden();
        $this->actingAs($this->recepcion)->get(route('admin.estudios.index'))->assertForbidden();
        expect(collect(app('router')->getRoutes())->filter(fn ($ruta) => str_starts_with((string) $ruta->getName(), 'admin.estudios.') && in_array('DELETE', $ruta->methods()))->all())->toBe([]);
    });

    test('la matriz predefinida: Médico emite y anula órdenes; Supervisión médica solo las ve; Recepción y Enfermería nada', function () {
        $matriz = PerfilesPredefinidos::MATRIZ;

        expect($matriz[PerfilesPredefinidos::MEDICO]['ORDENES_ESTUDIO'])->toBe(['VER', 'CREAR', 'EDITAR'])
            ->and($matriz[PerfilesPredefinidos::MEDICO]['ESTUDIOS'])->toBe(['VER'])
            ->and($matriz[PerfilesPredefinidos::SUPERVISION_MEDICA]['ORDENES_ESTUDIO'])->toBe(['VER'])
            ->and($matriz[PerfilesPredefinidos::RECEPCION])->not->toHaveKey('ORDENES_ESTUDIO')->not->toHaveKey('ESTUDIOS')
            ->and($matriz[PerfilesPredefinidos::ENFERMERIA])->not->toHaveKey('ORDENES_ESTUDIO');
    });
});
