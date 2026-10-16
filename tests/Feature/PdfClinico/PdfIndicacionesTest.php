<?php

/*
 * PDF de las indicaciones generales (docs/indicaciones-generales.md): abre con la consulta abierta y cerrada; sin indicaciones
 * vigentes no hay PDF vacío (aviso); quién puede y quién no (incluida Recepción); cada generación en Auditoría; nada se
 * guarda; el contenido es solo el encabezado y las indicaciones tal cual. Datos inventados. Funciones con el prefijo "pdfIndicaciones".
 */

use App\Enums\AccionAuditoria;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\TipoIndicacion;
use App\Models\User;
use App\Support\PdfClinico\PdfIndicaciones;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    hcEscenario();
    hcDarPermisos($this->medico, ['PDF_CLINICO' => ['EXPORTAR']]);
    $this->control = TipoIndicacion::create(['nombre' => 'Control']);
});

/** Lo que manda el formulario con dos indicaciones (una con tipo, otra sin tipo) y una retirada. */
function pdfIndicacionesDatos(): array
{
    return hcDatos([
        'con_indicaciones' => '1',
        'indicaciones' => [
            ['uid' => 'n-1', 'id' => '', 'activo' => '1', 'tipo_indicacion_id' => test()->control->id, 'descripcion' => "Volver en 7 días.\nTraer estudios."],
            ['uid' => 'n-2', 'id' => '', 'activo' => '1', 'tipo_indicacion_id' => '', 'descripcion' => 'Tomar abundante agua de prueba.'],
        ],
    ]);
}

function pdfIndicacionesAbierta(): Consulta
{
    $consulta = hcEnCurso();
    hcAutoguardar($consulta, pdfIndicacionesDatos())->assertOk();

    return $consulta->fresh();
}

function pdfIndicacionesCerrada(): Consulta
{
    $consulta = hcEnCurso();
    hcFinalizar($consulta, pdfIndicacionesDatos())->assertSessionHasNoErrors();

    return $consulta->fresh();
}

function pdfIndicacionesUsuario(array $permisos, ?string $personalDeSalud = null): User
{
    $fabrica = User::factory()->conPermisos($permisos);

    return ($personalDeSalud ? $fabrica->personalDeSalud($personalDeSalud) : $fabrica)->create();
}

function pdfIndicacionesExportaciones(): int
{
    return LogAuditoria::where('accion', AccionAuditoria::EXPORTAR->value)->count();
}

describe('se genera con la consulta abierta y con la cerrada', function () {
    test('abre (200, application/pdf, no-store) y no deja archivos en el servidor', function (string $estado) {
        $consulta = $estado === 'abierta' ? pdfIndicacionesAbierta() : pdfIndicacionesCerrada();
        $antes = collect([storage_path(), public_path()])->flatMap(fn ($c) => File::allFiles($c))->map->getPathname()->filter(fn ($r) => str_ends_with(strtolower($r), '.pdf'))->values()->all();

        $respuesta = $this->actingAs($this->medico)->get(route('admin.pdf.indicaciones', $consulta))->assertOk();

        expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')
            ->and(str_starts_with($respuesta->getContent(), '%PDF'))->toBeTrue()
            ->and($respuesta->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($respuesta->headers->get('Pragma'))->toBe('no-cache')
            ->and($respuesta->headers->get('Content-Disposition'))->toStartWith('inline;');
        $despues = collect([storage_path(), public_path()])->flatMap(fn ($c) => File::allFiles($c))->map->getPathname()->filter(fn ($r) => str_ends_with(strtolower($r), '.pdf'))->values()->all();
        expect($despues)->toBe($antes);
    })->with(['abierta', 'cerrada']);

    test('sin indicaciones vigentes no hay PDF vacío: un aviso y ningún registro de generación', function () {
        $sinNinguna = hcConsulta();
        $soloRetirada = pdfIndicacionesAbierta();
        $soloRetirada->indicaciones()->get()->each(fn ($indicacion) => $indicacion->update(['activo' => false]));

        foreach ([$sinNinguna, $soloRetirada] as $consulta) {
            $respuesta = $this->actingAs($this->medico)->get(route('admin.pdf.indicaciones', $consulta))->assertOk()
                ->assertSee(PdfIndicaciones::SIN_INDICACIONES);
            expect($respuesta->headers->get('Content-Type'))->not->toBe('application/pdf')
                ->and($respuesta->headers->get('Cache-Control'))->toContain('no-store');
        }
        expect(pdfIndicacionesExportaciones())->toBe(0);
    });

    test('toma lo ya guardado: una indicación agregada con la consulta abierta sale en el siguiente PDF', function () {
        $consulta = pdfIndicacionesAbierta();
        hcAutoguardar($consulta, [...pdfIndicacionesDatos(), 'indicaciones' => [
            ...collect($consulta->indicaciones()->orderBy('orden')->get())->map(fn ($i) => ['id' => (string) $i->id, 'activo' => '1', 'tipo_indicacion_id' => (string) ($i->tipo_indicacion_id ?? ''), 'descripcion' => $i->descripcion])->all(),
            ['uid' => 'n-3', 'id' => '', 'activo' => '1', 'tipo_indicacion_id' => '', 'descripcion' => 'Indicación agregada después.'],
        ]])->assertOk();

        expect(PdfIndicaciones::html($consulta->fresh(), PdfIndicaciones::vigentes($consulta->fresh())))->toContain('Indicación agregada después.');
    });

    test('una consulta anulada no existe (404)', function () {
        $consulta = pdfIndicacionesCerrada();
        $consulta->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO)])->saveQuietly();

        $this->actingAs($this->medico)->get(route('admin.pdf.indicaciones', $consulta))->assertNotFound();
    });
});

describe('quién puede y quién no', function () {
    test('puede: el profesional de la consulta, Supervisión médica y el Administrador', function () {
        $consulta = pdfIndicacionesCerrada();
        $supervision = pdfIndicacionesUsuario(['PDF_CLINICO' => ['EXPORTAR'], 'CORRECCIONES_HC' => ['VER']]);

        foreach ([$this->medico, $supervision, User::factory()->administrador()->create()] as $usuario) {
            $this->actingAs($usuario)->get(route('admin.pdf.indicaciones', $consulta))->assertOk();
        }
    });

    test('no puede: otro médico, Recepción (con o sin el permiso), el personal del triaje ni quien no tiene el permiso', function () {
        $consulta = pdfIndicacionesCerrada();
        $noPueden = [
            hcDarPermisos($this->otroMedico, ['PDF_CLINICO' => ['EXPORTAR']]),
            pdfIndicacionesUsuario(['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'PACIENTES' => ['VER']]),
            pdfIndicacionesUsuario(['PDF_CLINICO' => ['EXPORTAR'], 'TURNOS' => ['VER', 'CREAR', 'EDITAR']]),
            pdfIndicacionesUsuario(['PDF_CLINICO' => ['EXPORTAR'], 'PREPARACION' => ['VER', 'CREAR', 'EDITAR']], 'Enfermero/a'),
            pdfIndicacionesUsuario(['HISTORIA_CLINICA' => ['VER'], 'CORRECCIONES_HC' => ['VER']]),
        ];

        foreach ($noPueden as $usuario) {
            $this->actingAs($usuario)->get(route('admin.pdf.indicaciones', $consulta))->assertForbidden();
        }
        expect(pdfIndicacionesExportaciones())->toBe(0);
    });

    test('el enlace aparece a quien puede generarlo y no a quien solo lee', function () {
        $consulta = pdfIndicacionesCerrada();

        $this->actingAs($this->medico)->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSee('data-pdf="indicaciones"', false)->assertSee(route('admin.pdf.indicaciones', $consulta), false);
        $this->actingAs(pdfIndicacionesUsuario(['HISTORIA_CLINICA' => ['VER']]))->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertDontSee('data-pdf="indicaciones"', false);
    });
});

test('auditoría: cada generación deja un EXPORTAR sobre la consulta, con quién y qué; repetir deja otro', function () {
    $consulta = pdfIndicacionesAbierta();

    $this->actingAs($this->medico)->get(route('admin.pdf.indicaciones', $consulta))->assertOk();
    $this->actingAs($this->medico)->get(route('admin.pdf.indicaciones', $consulta))->assertOk();

    $eventos = LogAuditoria::where('accion', AccionAuditoria::EXPORTAR->value)->get();
    expect($eventos)->toHaveCount(2)
        ->and($eventos->pluck('usuario_id')->unique()->all())->toBe([$this->medico->id])
        ->and($eventos->pluck('tabla_afectada')->unique()->all())->toBe(['consultas'])
        ->and($eventos->pluck('registro_afectado_id')->unique()->all())->toBe([(string) $consulta->id])
        ->and($eventos->pluck('detalle')->unique()->all())->toBe(["PDF de indicaciones generales de la consulta {$consulta->id}"]);
});

test('contenido: encabezado y las indicaciones vigentes tal cual; sin motivo, diagnósticos, signos ni receta; escapado', function () {
    $consulta = pdfIndicacionesAbierta();
    $consulta->indicaciones()->create(['tipo_indicacion_id' => null, 'descripcion' => 'Indicación retirada de prueba.', 'orden' => 9, 'activo' => false]);
    $consulta->indicaciones()->create(['tipo_indicacion_id' => null, 'descripcion' => '<b>negrita</b> & "comillas"', 'orden' => 10, 'activo' => true]);

    $html = PdfIndicaciones::html($consulta->fresh(), PdfIndicaciones::vigentes($consulta->fresh()));

    expect($html)->toContain(config('clinexa.clinica.nombre'))->toContain('Indicaciones generales')
        ->toContain('Duarte')->toContain('Benítez')->toContain('06/10/2026')
        ->toContain('Control')->toContain("Volver en 7 días.\nTraer estudios.")->toContain('Tomar abundante agua de prueba.')
        ->toContain('&lt;b&gt;negrita&lt;/b&gt;')->not->toContain('<b>negrita</b>')
        ->not->toContain('Indicación retirada de prueba.')
        ->not->toContain('Dolor de garganta')->not->toContain('Rinofaringitis')->not->toContain('J06.9')
        ->not->toContain('Odinofagia')->not->toContain('38,2')->not->toContain('Receta')->not->toContain('FP-0000001');
});
