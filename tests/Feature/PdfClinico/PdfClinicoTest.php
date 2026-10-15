<?php

/*
 * PDF de triaje y de la consulta (docs/pdf-clinico.md): quién puede y quién no (incluida Recepción), con la consulta
 * abierta y cerrada; cada generación en Auditoría; nada se guarda en el servidor; cabeceras no-store; el contenido es
 * solo lo registrado. Datos inventados. Funciones con el prefijo "pdf".
 */

use App\Enums\AccionAuditoria;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\Permiso;
use App\Models\User;
use App\Support\PdfClinico\GenerarPdf;
use Illuminate\Support\Facades\File;

const PDF_EXPORTAR = ['PDF_CLINICO' => ['EXPORTAR']];

beforeEach(function () {
    hcEscenario();
    // El médico del escenario (profesional de las consultas) recibe el permiso nuevo, como lo trae el perfil Médico.
    hcDarPermisos($this->medico, PDF_EXPORTAR);
});

/** Una consulta EN_CURSO de la Dra. Benítez con el contenido de siempre, más un signo vital y hallazgos. */
function pdfAbierta(): Consulta
{
    return hcConsultaEnCurso(['examen' => ['temperatura' => '38,2', 'hallazgos' => 'Faringe eritematosa.']]);
}

function pdfUsuario(array $permisos, ?string $personalDeSalud = null): User
{
    $fabrica = User::factory()->conPermisos($permisos);

    return ($personalDeSalud ? $fabrica->personalDeSalud($personalDeSalud) : $fabrica)->create();
}

/** @return list<string> los .pdf que hay hoy en el almacenamiento y en lo público (no debe aparecer ninguno nuevo). */
function pdfArchivosEnDisco(): array
{
    return collect([storage_path(), public_path()])
        ->flatMap(fn (string $carpeta) => File::allFiles($carpeta))
        ->map(fn ($archivo) => $archivo->getPathname())
        ->filter(fn (string $ruta) => str_ends_with(strtolower($ruta), '.pdf'))
        ->values()->all();
}

describe('se genera con la consulta abierta y con la cerrada', function () {
    test('el PDF de triaje y el de la consulta abren (200, application/pdf)', function (string $estado) {
        $consulta = $estado === 'abierta' ? pdfAbierta() : hcConsulta();

        foreach (['admin.pdf.triaje', 'admin.pdf.consulta'] as $ruta) {
            $respuesta = $this->actingAs($this->medico)->get(route($ruta, $consulta))->assertOk();
            expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')
                ->and(str_starts_with($respuesta->getContent(), '%PDF'))->toBeTrue();
        }
    })->with(['abierta', 'cerrada']);

    test('una consulta anulada no existe (404)', function () {
        $consulta = hcConsulta();
        $consulta->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO)])->saveQuietly();

        $this->actingAs($this->medico)->get(route('admin.pdf.consulta', $consulta))->assertNotFound();
    });
});

describe('quién puede y quién no', function () {
    test('puede: el profesional de la consulta, Supervisión médica y el Administrador (ambos PDF)', function () {
        $consulta = hcConsulta();
        $supervision = pdfUsuario([...PDF_EXPORTAR, 'CORRECCIONES_HC' => ['VER']]);
        $administrador = User::factory()->administrador()->create();

        foreach ([$this->medico, $supervision, $administrador] as $usuario) {
            $this->actingAs($usuario)->get(route('admin.pdf.triaje', $consulta))->assertOk();
            $this->actingAs($usuario)->get(route('admin.pdf.consulta', $consulta))->assertOk();
        }
    });

    test('no puede: otro médico con el permiso, Recepción (con o sin el permiso) ni quien no tiene el permiso', function () {
        $consulta = hcConsulta();
        $otroMedico = hcDarPermisos($this->otroMedico, PDF_EXPORTAR);
        $recepcion = pdfUsuario(['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'PACIENTES' => ['VER']]);
        $recepcionConElPermiso = pdfUsuario([...PDF_EXPORTAR, 'TURNOS' => ['VER', 'CREAR', 'EDITAR']]); // por error: no es de la consulta
        $sinElPermiso = pdfUsuario(['HISTORIA_CLINICA' => ['VER'], 'CORRECCIONES_HC' => ['VER']]); // identidad sí, permiso no

        foreach ([$otroMedico, $recepcion, $recepcionConElPermiso, $sinElPermiso] as $usuario) {
            $this->actingAs($usuario)->get(route('admin.pdf.triaje', $consulta))->assertForbidden();
            $this->actingAs($usuario)->get(route('admin.pdf.consulta', $consulta))->assertForbidden();
        }
        $this->actingAs($this->medico)->get(route('admin.pdf.triaje', $consulta))->assertOk();
    });

    test('el personal de salud que hizo el triaje saca el de triaje, no el de la consulta; otro personal de salud, ninguno', function () {
        $permisos = [...PDF_EXPORTAR, 'PREPARACION' => ['VER', 'CREAR', 'EDITAR']];
        $enfermera = pdfUsuario($permisos, 'Enfermero/a');
        $otra = pdfUsuario($permisos, 'Enfermero/a');
        $this->actingAs($enfermera)->post(route('admin.preparacion.preparar', hcTurno()))->assertRedirect();
        $consulta = Consulta::sole();
        hcAutoguardar($consulta, ['examen' => ['frecuencia_cardiaca' => '80']])->assertOk();

        $this->actingAs($enfermera)->get(route('admin.pdf.triaje', $consulta))->assertOk();
        $this->actingAs($enfermera)->get(route('admin.pdf.consulta', $consulta))->assertForbidden();
        $this->actingAs($otra)->get(route('admin.pdf.triaje', $consulta))->assertForbidden();
        $this->actingAs($otra)->get(route('admin.pdf.consulta', $consulta))->assertForbidden();
    });

    test('un invitado va al login', function () {
        $consulta = hcConsulta();
        $this->app['auth']->forgetGuards();

        $this->get(route('admin.pdf.triaje', $consulta))->assertRedirect(route('login'));
    });

    test('el permiso es EXPORTAR y es el único que tiene sentido en el módulo', function () {
        expect(Permiso::accionesDe('PDF_CLINICO'))->toBe(['EXPORTAR']);
    });
});

describe('enlaces en pantalla', function () {
    test('la consulta muestra los enlaces a quien puede generar el PDF y no a quien solo puede leer', function () {
        $consulta = hcConsulta();
        $soloLectura = pdfUsuario(['HISTORIA_CLINICA' => ['VER']]);

        $this->actingAs($this->medico)->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSee('data-pdf="triaje"', false)->assertSee('data-pdf="consulta"', false)
            ->assertSee(route('admin.pdf.consulta', $consulta), false);
        $this->actingAs($soloLectura)->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertDontSee('data-pdf=', false);
    });
});

describe('auditoría', function () {
    test('cada generación deja un registro (quién, qué documento, qué consulta); repetir deja otro; negado no deja nada', function () {
        $consulta = pdfAbierta();
        expect(LogAuditoria::where('accion', AccionAuditoria::EXPORTAR->value)->count())->toBe(0);

        $this->actingAs($this->medico)->get(route('admin.pdf.triaje', $consulta))->assertOk();
        $this->actingAs($this->medico)->get(route('admin.pdf.triaje', $consulta))->assertOk();
        $this->actingAs($this->medico)->get(route('admin.pdf.consulta', $consulta))->assertOk();
        $this->actingAs(pdfUsuario(['TURNOS' => ['VER']]))->get(route('admin.pdf.consulta', $consulta))->assertForbidden();

        $eventos = LogAuditoria::where('accion', AccionAuditoria::EXPORTAR->value)->orderBy('id')->get();
        expect($eventos)->toHaveCount(3)
            ->and($eventos->pluck('usuario_id')->unique()->values()->all())->toBe([$this->medico->id])
            ->and($eventos->pluck('tabla_afectada')->unique()->values()->all())->toBe(['consultas'])
            ->and($eventos->pluck('registro_afectado_id')->unique()->values()->all())->toBe([(string) $consulta->id])
            ->and($eventos->pluck('detalle')->all())->toBe([
                "PDF de triaje de la consulta {$consulta->id}", "PDF de triaje de la consulta {$consulta->id}", "PDF de consulta de la consulta {$consulta->id}",
            ]);
    });
});

describe('nada se guarda', function () {
    test('ningún archivo nuevo en el servidor y cabeceras no-store', function () {
        $consulta = hcConsulta();
        $antes = pdfArchivosEnDisco();

        foreach (['admin.pdf.triaje', 'admin.pdf.consulta'] as $ruta) {
            $respuesta = $this->actingAs($this->medico)->get(route($ruta, $consulta))->assertOk();
            expect($respuesta->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
                ->and($respuesta->headers->get('Pragma'))->toBe('no-cache')
                ->and($respuesta->headers->get('Content-Disposition'))->toStartWith('inline;');
        }

        expect(pdfArchivosEnDisco())->toBe($antes);
    });
});

describe('contenido: solo lo registrado', function () {
    test('encabezado con clínica, paciente, fecha y quién lo generó; el de triaje no trae diagnósticos ni hallazgos', function () {
        $consulta = pdfAbierta();

        $triaje = GenerarPdf::html($this->medico, $consulta, GenerarPdf::TRIAJE);
        $completo = GenerarPdf::html($this->medico, $consulta, GenerarPdf::CONSULTA);

        foreach ([$triaje, $completo] as $html) {
            expect($html)->toContain(config('clinexa.clinica.nombre'))->toContain('Duarte')->toContain('FP-0000001')
                ->toContain('06/10/2026')->toContain('Generado por')->toContain('Rosa')->toContain('En curso')
                ->toContain('Temperatura')->toContain('38,2')
                ->not->toContain('Receta')->not->toContain('Indicaciones')->not->toContain('Reposo');
        }
        expect($triaje)->not->toContain('Diagnósticos')->not->toContain('Faringe eritematosa.')
            ->and($completo)->toContain('Motivo de consulta')->toContain('Faringe eritematosa.')->toContain('Diagnósticos')->toContain('Rinofaringitis aguda');
    });

    test('lo escrito por el usuario sale escapado', function () {
        $consulta = hcConsultaEnCurso(['motivo_consulta' => '<b>negrita</b> & "comillas"']);

        $html = GenerarPdf::html($this->medico, $consulta, GenerarPdf::CONSULTA);

        expect($html)->toContain('&lt;b&gt;negrita&lt;/b&gt;')->not->toContain('<b>negrita</b>');
    });
});
