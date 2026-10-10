<?php

use App\Enums\AccionAuditoria;
use App\Support\Atencion\Autoguardado;
use App\Models\Indicacion;
use App\Models\LogAuditoria;
use App\Models\Receta;
use App\Models\TipoIndicacion;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Carbon;

/*
 * Recetas e indicaciones generales: auditoría (un evento por cambio, sin snapshot, contenido oculto
 * sin permiso), escapado del contenido y las indicaciones generales del formulario de la consulta.
 */

beforeEach(function () {
    hcEscenario();
    $this->medico = hcDarPermisos($this->medico, HC_PERMISOS_RECETAS);
    $this->actingAs($this->medico);
    $this->consulta = hcConsultaEnCurso(); // las recetas se cargan con la consulta EN_CURSO
    $this->reposo = TipoIndicacion::create(['nombre' => 'Reposo']);
});

afterEach(fn () => Carbon::setTestNow());

function eventosDeRecetas()
{
    return LogAuditoria::whereIn('tabla_afectada', ['recetas', 'detalles_receta'])->orderBy('id')->get();
}

function conIndicaciones(array $filas): array
{
    return [...hcFilasGuardadas(test()->consulta), 'con_indicaciones' => '1', 'indicaciones' => $filas];
}

describe('auditoría de recetas', function () {
    test('un evento por cambio: CREAR, EDITAR de renglones, EDITAR al emitir y ANULAR con el motivo; nada de los renglones por su cuenta', function () {
        $receta = hcReceta($this->consulta);
        Carbon::setTestNow(now()->addMinute());
        $this->put(route('admin.recetas.update', $receta), ['version' => $receta->version(), 'con_detalles' => '1',
            'detalles' => [hcRenglon(['id' => (string) $receta->detalles()->first()->id, 'dosis' => '2 comprimidos'])]])->assertSessionHasNoErrors();
        hcEmitir($receta);
        $this->post(route('admin.recetas.anular', $receta), ['motivo' => 'Dosis equivocada']);

        $eventos = eventosDeRecetas()->where('accion', '!=', AccionAuditoria::VER);
        expect($eventos->map(fn ($e) => [$e->accion->value, $e->tabla_afectada, array_keys($e->valor_nuevo ?? [])])->values()->all())->toBe([
            ['CREAR', 'recetas', ['observaciones', 'consulta_id', 'estado_id', 'id']],
            ['EDITAR', 'recetas', ['detalles']],
            ['EDITAR', 'recetas', ['detalles']],
            ['EDITAR', 'recetas', ['numero', 'fecha', 'estado_id', 'emitida_en']],
            ['ANULAR', 'recetas', ['estado_id', 'anulada_en', 'motivo_anulacion']],
        ]);

        $renglones = $eventos->values()[2];
        expect($renglones->valor_anterior)->toBe(['detalles' => ['Amoxicilina 500 mg · 1 caja x 21 · 1 comprimido · Oral · Cada 8 horas · 7 días · Tomar con las comidas']])
            ->and($renglones->valor_nuevo)->toBe(['detalles' => ['Amoxicilina 500 mg · 1 caja x 21 · 2 comprimidos · Oral · Cada 8 horas · 7 días · Tomar con las comidas']])
            ->and($eventos->last()->detalle)->toBe('Motivo: Dosis equivocada')
            ->and($eventos->values()[3]->valor_nuevo['numero'])->toBe('RE-0000001');
    });

    test('el snapshot no aparece en ningún registro del log', function () {
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);
        $this->post(route('admin.recetas.corregir', $receta), ['motivo' => 'Corregir dosis']);

        foreach (LogAuditoria::all() as $evento) {
            expect(json_encode([$evento->valor_anterior, $evento->valor_nuevo]))->not->toContain('snapshot')->not->toContain('Plenitud Mujer')->not->toContain('"clinica"');
        }
    });

    test('VER: la hoja y la vista previa registran VER sobre recetas, sin repetir en 5 minutos', function () {
        $receta = hcReceta($this->consulta);
        $this->get(route('admin.recetas.vista-previa', $receta))->assertOk();
        $this->get(route('admin.recetas.vista-previa', $receta))->assertOk();
        hcEmitir($receta);
        $this->get(route('admin.recetas.imprimir', $receta))->assertOk(); // misma receta, dentro de 5 minutos

        expect(LogAuditoria::where('accion', 'VER')->where('tabla_afectada', 'recetas')->get()->map(fn ($e) => $e->registro_afectado_id)->all())->toBe([(string) $receta->id]);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->get(route('admin.recetas.imprimir', $receta))->assertOk();
        expect(LogAuditoria::where('accion', 'VER')->where('tabla_afectada', 'recetas')->count())->toBe(2);
    });

    test('contenido oculto en la pantalla de auditoría: recetas sin VER sobre RECETAS', function () {
        $receta = hcReceta($this->consulta);
        $this->post(route('admin.recetas.anular', $receta), ['motivo' => 'Paciente alérgica']);
        $anulacion = LogAuditoria::where('accion', 'ANULAR')->sole();
        $renglones = LogAuditoria::where('tabla_afectada', 'recetas')->where('accion', 'EDITAR')->first();

        // Con VER sobre la historia clínica pero no sobre RECETAS: oculto.
        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());
        $this->get(route('admin.auditoria.show', $anulacion))->assertOk()
            ->assertSee('Contenido clínico: se requiere permiso de lectura sobre Recetas')->assertSee('motivo_anulacion')
            ->assertDontSee('Paciente alérgica');
        $this->get(route('admin.auditoria.show', $renglones))->assertOk()->assertDontSee('Amoxicilina');

        // Con VER sobre RECETAS: visible.
        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'RECETAS' => ['VER']])->create());
        $this->get(route('admin.auditoria.show', $anulacion))->assertOk()->assertSee('Paciente alérgica')->assertDontSee('Contenido clínico');
        $this->get(route('admin.auditoria.show', $renglones))->assertOk()->assertSee('Amoxicilina');
    });

    test('el buscador no encuentra los eventos de recetas por su texto sin VER sobre RECETAS', function () {
        $receta = hcReceta($this->consulta);
        $this->post(route('admin.recetas.anular', $receta), ['motivo' => 'Paciente alérgica']);
        $anulacion = LogAuditoria::where('accion', 'ANULAR')->sole();

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());
        $this->get(route('admin.auditoria.index', ['q' => 'alérgica']))->assertOk()->assertDontSee(route('admin.auditoria.show', $anulacion));

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'RECETAS' => ['VER']])->create());
        $this->get(route('admin.auditoria.index', ['q' => 'alérgica']))->assertOk()->assertSee(route('admin.auditoria.show', $anulacion));
    });

    test('ANULAR está en el filtro de acciones de Auditoría, con su etiqueta', function () {
        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER']])->create());

        $this->get(route('admin.auditoria.index'))->assertOk()->assertSee('<option value="ANULAR" >Anular</option>', false);
        expect(AccionAuditoria::ANULAR->etiqueta())->toBe('Anular');
    });

    test('el historial de la consulta incluye los eventos de sus recetas solo con VER sobre RECETAS', function () {
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);
        $auditor = hcConPerfil(User::factory()->create(), ['HISTORIA_CLINICA' => ['VER'], 'AUDITORIA' => ['VER']]);

        $this->flushSession();
        $sin = $this->actingAs($auditor)->get(route('admin.consultas.show', $this->consulta))->assertOk()->getContent();
        expect($sin)->toContain('Historial de cambios')->not->toContain('Receta RE-0000001:')->not->toContain('medicamentos');

        $con = $this->actingAs(hcDarPermisos($auditor, ['RECETAS' => ['VER']]))->get(route('admin.consultas.show', $this->consulta))->assertOk()->getContent();
        expect($con)->toContain('Receta RE-0000001:')->toContain('medicamentos')->toContain('número');
    });
});

describe('indicaciones generales', function () {
    test('alta en el formulario de la consulta, con tipo opcional, y en la lectura como lista', function () {
        Carbon::setTestNow(now()->addMinute());
        hcActualizar($this->consulta, conIndicaciones([
            ['id' => '', 'tipo_indicacion_id' => $this->reposo->id, 'descripcion' => 'Reposo 48 horas'],
            ['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => 'Tomar abundante líquido'],
        ]))->assertSessionHasNoErrors();

        expect(Indicacion::orderBy('orden')->get()->map(fn ($i) => [$i->orden, $i->tipoIndicacion?->nombre, $i->descripcion, $i->activo])->all())
            ->toBe([[1, 'Reposo', 'Reposo 48 horas', true], [2, null, 'Tomar abundante líquido', true]]);
        $this->get(route('admin.consultas.show', $this->consulta))->assertOk()
            ->assertSeeInOrder(['Indicaciones generales', 'Reposo:', 'Reposo 48 horas', 'Tomar abundante líquido']);
    });

    test('retirar y reponer; los retirados no cuentan para el máximo de 10', function () {
        hcActualizar($this->consulta, conIndicaciones(array_fill(0, 10, ['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => 'Algo'])))->assertSessionHasNoErrors();
        Carbon::setTestNow(now()->addMinute());
        hcActualizar($this->consulta, conIndicaciones(array_fill(0, 11, ['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => 'Algo'])))->assertStatus(422)->assertJsonValidationErrors('indicaciones');

        $guardadas = Indicacion::orderBy('orden')->get();
        $filas = $guardadas->map(fn ($i) => ['id' => (string) $i->id, 'activo' => '1', 'tipo_indicacion_id' => '', 'descripcion' => 'Algo'])->all();
        $filas[0]['activo'] = '0';
        $filas[] = ['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => 'Nueva'];
        hcActualizar($this->consulta, conIndicaciones($filas))->assertSessionHasNoErrors();
        expect(Indicacion::count())->toBe(11)->and(Indicacion::where('activo', false)->count())->toBe(1);
        $this->get(route('admin.consultas.show', $this->consulta))->assertSee('Retirado');

        $filas = Indicacion::orderBy('orden')->get()->map(fn ($i) => ['id' => (string) $i->id, 'activo' => '1', 'tipo_indicacion_id' => '', 'descripcion' => $i->descripcion])->all();
        $filas[10]['activo'] = '0';
        Carbon::setTestNow(now()->addMinute());
        hcActualizar($this->consulta, conIndicaciones($filas))->assertSessionHasNoErrors(); // repone una, retira otra
        expect(Indicacion::where('activo', true)->count())->toBe(10);
    });

    test('descripción obligatoria (al finalizar; en el borrador la fila queda incompleta) y de hasta 500; un tipo inactivo solo vale en la fila que ya lo tenía', function () {
        hcActualizar($this->consulta, conIndicaciones([['uid' => 'n-1', 'id' => '', 'tipo_indicacion_id' => '', 'descripcion' => ' ']]))->assertOk()->assertJsonPath('incompletas.indicaciones', ['n-1']);
        hcFinalizar($this->consulta, conIndicaciones([['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => ' ']]))->assertSessionHasErrors('indicaciones.0.descripcion');
        expect($this->consulta->fresh()->enCurso())->toBeTrue()->and(Indicacion::count())->toBe(0);
        hcActualizar($this->consulta, conIndicaciones([['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => str_repeat('a', 501)]]))->assertStatus(422)->assertJsonValidationErrors('indicaciones.0.descripcion');

        hcActualizar($this->consulta, conIndicaciones([['id' => '', 'tipo_indicacion_id' => $this->reposo->id, 'descripcion' => 'Reposo']]))->assertSessionHasNoErrors();
        $this->reposo->desactivar();
        $guardada = Indicacion::sole();
        Carbon::setTestNow(now()->addMinute());
        $this->get(route('admin.consultas.atencion', $this->consulta))->assertSee('Reposo (inactivo)');
        hcActualizar($this->consulta, conIndicaciones([['id' => (string) $guardada->id, 'activo' => '1', 'tipo_indicacion_id' => $this->reposo->id, 'descripcion' => 'Reposo 3 días']]))->assertSessionHasNoErrors();
        hcActualizar($this->consulta, conIndicaciones([
            ['id' => (string) $guardada->id, 'activo' => '1', 'tipo_indicacion_id' => $this->reposo->id, 'descripcion' => 'Reposo 3 días'],
            ['id' => '', 'tipo_indicacion_id' => $this->reposo->id, 'descripcion' => 'Otra'],
        ]))->assertStatus(422)->assertJsonValidationErrors('indicaciones.1.tipo_indicacion_id');
    });

    test('sin JavaScript (sin la marca) no se pierden filas', function () {
        hcActualizar($this->consulta, conIndicaciones([['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => 'Reposo']]))->assertSessionHasNoErrors();
        Carbon::setTestNow(now()->addMinute());

        $datos = hcFilasGuardadas($this->consulta);
        unset($datos['con_indicaciones'], $datos['indicaciones']);
        hcActualizar($this->consulta, $datos)->assertSessionHasNoErrors();

        expect(Indicacion::where('activo', true)->count())->toBe(1);
        $this->get(route('admin.consultas.atencion', $this->consulta))->assertSee('<template x-if="true"><input type="hidden" name="con_indicaciones" value="1"></template>', false);
    });

    test('auditoría: EDITAR de la consulta con la fila legible, sin eventos propios; oculto sin VER sobre la historia clínica', function () {
        Carbon::setTestNow(now()->addMinute());
        hcActualizar($this->consulta, conIndicaciones([['id' => '', 'tipo_indicacion_id' => $this->reposo->id, 'descripcion' => 'Reposo 48 horas']]))->assertSessionHasNoErrors();

        $evento = LogAuditoria::where('tabla_afectada', 'consultas')->get()->first(fn ($e) => isset($e->valor_nuevo['indicaciones']));
        $fila = 'Indicación #'.$this->consulta->indicaciones()->value('id');
        expect($evento->valor_anterior)->toBe(['indicaciones' => [$fila => null]])->and($evento->valor_nuevo)->toBe(['indicaciones' => [$fila => '1. Reposo: Reposo 48 horas']])
            ->and(LogAuditoria::where('tabla_afectada', 'indicaciones')->count())->toBe(0)
            ->and(Auditoria::TABLAS_CLINICAS)->toContain('indicaciones')
            ->and(Auditoria::TABLAS_RECETAS)->toBe(['recetas', 'detalles_receta']);

        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'RECETAS' => ['VER']])->create());
        $this->get(route('admin.auditoria.show', $evento))->assertOk()->assertSee('se requiere permiso de lectura sobre Historia Clínica')->assertDontSee('Reposo 48 horas')->assertDontSee('Indicación #');
    });

    test('concurrencia de la consulta: las indicaciones también cambian la versión', function () {
        $vieja = $this->consulta->fresh()->version();
        Carbon::setTestNow(now()->addSecond());
        hcActualizar($this->consulta, conIndicaciones([['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => 'Reposo']]), $vieja)->assertSessionHasNoErrors();

        hcActualizar($this->consulta, conIndicaciones([['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => 'Otra']]), $vieja)
            ->assertStatus(409)->assertJson(['message' => Autoguardado::VERSION_VIEJA]);
    });
});

describe('escapado', function () {
    test('HTML y JS en medicamento, dosis, observaciones, indicaciones y motivo: escapados en formulario, consulta, popup, hoja y auditoría', function () {
        $img = '<img src=x onerror=alert(1)>';
        $script = '"><script>alert(1)</script>';
        Carbon::setTestNow(now()->addMinute());
        hcActualizar($this->consulta, conIndicaciones([['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => "Indicación {$img}"]]))->assertSessionHasNoErrors();
        $receta = hcReceta($this->consulta, [hcRenglon(['medicamento' => "Med {$img}", 'dosis' => "Dosis {$script}", 'observaciones' => "Obs {$img}"])], ['observaciones' => "Receta {$script}"]);

        $sinCrudo = fn ($respuesta) => $respuesta->assertDontSee($img, false)->assertDontSee('<script>alert(1)</script>', false);
        $escapado = '&lt;img src=x onerror=alert(1)&gt;';

        // Formulario del borrador: los renglones van a Alpine como JSON codificado; las observaciones, en el textarea.
        $sinCrudo($this->get(route('admin.recetas.edit', $receta))->assertOk())
            ->assertSee('Med \\\\u003Cimg src=x onerror=alert(1)\\\\u003E', false)->assertSee('Receta &quot;&gt;&lt;script&gt;', false);
        // Vista previa (hoja con datos vivos).
        $sinCrudo($this->get(route('admin.recetas.vista-previa', $receta))->assertOk())
            ->assertSee("Med {$escapado}", false)->assertSee('Dosis &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee("Obs {$escapado}", false)->assertSee("Indicación {$escapado}", false);

        hcEmitir($receta);
        $this->post(route('admin.recetas.anular', $receta), ['motivo' => "Motivo {$script}"]);
        $this->flushSession();

        // Hoja (snapshot), página de la consulta y popup.
        $sinCrudo($this->get(route('admin.recetas.imprimir', $receta))->assertOk())->assertSee("Med {$escapado}", false)->assertSee('Motivo: Motivo &quot;&gt;&lt;script&gt;', false);
        $sinCrudo($this->get(route('admin.consultas.show', $this->consulta))->assertOk())
            ->assertSee("Med {$escapado}", false)->assertSee("Indicación {$escapado}", false)->assertSee('Motivo de la anulación: Motivo &quot;&gt;&lt;script&gt;', false);
        $sinCrudo($this->get(route('admin.consultas.detalle', $this->consulta), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk())
            ->assertSee("Med {$escapado}", false);

        // Detalle de auditoría (renglones, anulación con el motivo e indicaciones).
        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'RECETAS' => ['VER'], 'HISTORIA_CLINICA' => ['VER']])->create());
        $renglones = LogAuditoria::where('tabla_afectada', 'recetas')->get()->first(fn ($e) => isset($e->valor_nuevo['detalles']));
        $sinCrudo($this->get(route('admin.auditoria.show', $renglones))->assertOk())->assertSee("Med {$escapado}", false);
        $sinCrudo($this->get(route('admin.auditoria.show', LogAuditoria::where('accion', 'ANULAR')->sole()))->assertOk())
            ->assertSee('Motivo: Motivo &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $indicaciones = LogAuditoria::where('tabla_afectada', 'consultas')->get()->first(fn ($e) => isset($e->valor_nuevo['indicaciones']));
        $sinCrudo($this->get(route('admin.auditoria.show', $indicaciones))->assertOk())->assertSee("Indicación {$escapado}", false);
    });

    test('las vistas de recetas no usan {!! !!}', function () {
        foreach (['recetas/form', 'recetas/hoja', 'recetas/_hoja', 'admin/consultas/_contenido', 'admin/consultas/show'] as $vista) {
            expect(file_get_contents(resource_path("views/{$vista}.blade.php")))->not->toContain('{!!');
        }
    });
});
