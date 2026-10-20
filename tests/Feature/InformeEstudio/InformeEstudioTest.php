<?php

/*
 * Informes de estudio (docs/informes-estudio.md): parte de la consulta. Quién escribe y quién no (Recepción, Enfermería, otro
 * médico, el Administrador), varios informes, estudio del catálogo o escrito a mano, retirar (nada se borra), consulta cerrada
 * de solo lectura, corrección solo por desbloqueo (con versión) y adenda, PDF con la consulta abierta y cerrada (quién, no-store,
 * nada se guarda, auditoría de cada generación), contenido oculto en Auditoría y Recepción sin acceso. La migración, contra un
 * catálogo sin datos. Datos inventados (escenario.php). Funciones con el prefijo "infEst".
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\ConsultaCerrada;
use App\Exceptions\RegistroClinicoNoSeBorra;
use App\Models\Consulta;
use App\Models\ConsultaVersion;
use App\Models\DesbloqueoConsulta;
use App\Models\Estado;
use App\Models\Estudio;
use App\Models\InformeEstudio;
use App\Models\LogAuditoria;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\PdfClinico\PdfInformeEstudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    hcEscenario();
    hcDarPermisos($this->medico, ['PDF_CLINICO' => ['EXPORTAR'], 'ESTUDIOS' => ['VER']]);
    $this->ecografia = Estudio::create(['nombre' => 'Ecografía inventada']);
});

/** Lo que manda el formulario: dos informes, uno del catálogo con solicitante y otro escrito a mano. */
function infEstDatos(array $informes = []): array
{
    return [
        'con_informes' => '1',
        'informes' => $informes ?: [
            ['uid' => 'n-1', 'id' => '', 'estudio_id' => (string) test()->ecografia->id, 'solicitante' => 'Dr. Externo Inventado', 'texto' => "Informe de prueba uno.\nSegunda línea."],
            ['uid' => 'n-2', 'id' => '', 'estudio_id' => '', 'estudio_libre' => 'Estudio escrito a mano', 'solicitante' => '', 'texto' => 'Informe de prueba dos.'],
        ],
    ];
}

/** Consulta EN_CURSO de la Dra. Benítez con los dos informes guardados por el autoguardado. */
function infEstAbierta(): Consulta
{
    $consulta = hcEnCurso();
    hcAutoguardar($consulta, infEstDatos())->assertOk();

    return $consulta->fresh();
}

/** Consulta FINALIZADA con los dos informes. */
function infEstCerrada(): Consulta
{
    $consulta = hcEnCurso();
    hcFinalizar($consulta, hcDatos(infEstDatos()))->assertSessionHasNoErrors();

    return $consulta->fresh();
}

/** Los informes guardados, como el formulario los vuelve a mandar. */
function infEstFilas(Consulta $consulta): array
{
    return $consulta->informesEstudio()->orderBy('orden')->get()->map(fn (InformeEstudio $informe) => [
        'id' => (string) $informe->id, 'activo' => $informe->activo ? '1' : '0', 'estudio_id' => (string) ($informe->estudio_id ?? ''),
        'estudio_libre' => $informe->estudio_id ? '' : $informe->estudio_nombre, 'solicitante' => (string) $informe->solicitante, 'texto' => $informe->texto,
    ])->all();
}

function infEstUsuario(array $permisos, ?string $personalDeSalud = null): User
{
    $fabrica = User::factory()->conPermisos($permisos);

    return ($personalDeSalud ? $fabrica->personalDeSalud($personalDeSalud) : $fabrica)->create();
}

function infEstRecepcion(): User
{
    return infEstUsuario(['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'PACIENTES' => ['VER', 'CREAR', 'EDITAR'], 'PDF_CLINICO' => ['EXPORTAR']]);
}

function infEstExportaciones(): int
{
    return LogAuditoria::where('accion', AccionAuditoria::EXPORTAR->value)->count();
}

function infEstPdfs(): array
{
    return collect([storage_path(), public_path()])->flatMap(fn ($carpeta) => File::allFiles($carpeta))->map->getPathname()
        ->filter(fn ($ruta) => str_ends_with(strtolower($ruta), '.pdf'))->values()->all();
}

describe('quién escribe', function () {
    test('el profesional de la consulta: varios informes, del catálogo (nombre copiado) o escritos a mano, con su autoría', function () {
        $consulta = infEstAbierta();
        $informes = $consulta->informesEstudio()->orderBy('orden')->get();

        expect($informes)->toHaveCount(2)
            ->and($informes[0]->estudio_id)->toBe($this->ecografia->id)->and($informes[0]->estudio_nombre)->toBe('Ecografía inventada')
            ->and($informes[0]->solicitante)->toBe('Dr. Externo Inventado')->and($informes[0]->texto)->toBe("Informe de prueba uno.\nSegunda línea.")
            ->and($informes[1]->estudio_id)->toBeNull()->and($informes[1]->estudio_nombre)->toBe('Estudio escrito a mano')->and($informes[1]->solicitante)->toBeNull()
            ->and($informes->pluck('usuario_id')->unique()->all())->toBe([$this->medico->id]);
        // El escrito a mano no se agrega al catálogo; renombrar o desactivar el estudio no altera lo guardado.
        expect(Estudio::count())->toBe(1);
        $this->ecografia->update(['nombre' => 'Ecografía renombrada']);
        $this->ecografia->desactivar();
        hcAutoguardar($consulta, infEstDatos(infEstFilas($consulta)))->assertOk();
        expect($informes[0]->fresh()->estudio_nombre)->toBe('Ecografía inventada');

        // Un estudio inactivo no se elige en un informe nuevo (el que la fila ya tenía, sí).
        hcAutoguardar($consulta, infEstDatos([...infEstFilas($consulta), ['uid' => 'n-3', 'id' => '', 'estudio_id' => (string) $this->ecografia->id, 'texto' => 'Otro.']]))
            ->assertStatus(422)->assertJsonValidationErrors('informes.2.estudio_id');
    });

    test('borrador: el informe sin estudio o sin texto no se guarda y se informa; al finalizar, es un error', function () {
        $consulta = hcEnCurso();
        hcAutoguardar($consulta, infEstDatos([
            ['uid' => 'sin-texto', 'id' => '', 'estudio_id' => (string) $this->ecografia->id, 'texto' => ''],
            ['uid' => 'sin-estudio', 'id' => '', 'estudio_id' => '', 'estudio_libre' => '', 'texto' => 'Texto sin estudio.'],
        ]))->assertOk()->assertJsonPath('incompletas.informes', ['sin-texto', 'sin-estudio']);
        expect(InformeEstudio::count())->toBe(0);

        hcFinalizar($consulta, hcDatos(infEstDatos([['id' => '', 'estudio_id' => '', 'estudio_libre' => '', 'texto' => 'Texto sin estudio.']])))
            ->assertSessionHasErrors('informes.0.estudio_libre');
        expect($consulta->fresh()->enCurso())->toBeTrue();
    });

    test('hasta 5000 caracteres de texto', function () {
        $consulta = hcEnCurso();
        hcAutoguardar($consulta, infEstDatos([['uid' => 'n', 'id' => '', 'estudio_id' => (string) $this->ecografia->id, 'texto' => str_repeat('a', 5001)]]))
            ->assertStatus(422)->assertJsonValidationErrors('informes.0.texto');
        hcAutoguardar($consulta, infEstDatos([['uid' => 'n', 'id' => '', 'estudio_id' => (string) $this->ecografia->id, 'texto' => str_repeat('a', 5000)]]))->assertOk();
    });

    test('no escriben: Enfermería (triaje), otro médico, el Administrador ni Recepción', function () {
        $consulta = infEstAbierta();
        $antes = InformeEstudio::orderBy('id')->get()->toJson();
        $enfermeria = infEstUsuario(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']], 'Enfermero/a');

        foreach ([$enfermeria, $this->otroMedico, User::factory()->administrador()->create(), infEstRecepcion()] as $usuario) {
            $this->actingAs($usuario)->patchJson(route('admin.consultas.autoguardado', $consulta), [...infEstDatos(), 'version' => $consulta->fresh()->version()])
                ->assertForbidden();
        }
        expect(InformeEstudio::orderBy('id')->get()->toJson())->toBe($antes);
    });

    test('la pantalla de atención ofrece "Estudios" (ya no "Próximamente") con los informes y el catálogo activo', function () {
        $consulta = infEstAbierta();

        $this->get(route('admin.consultas.atencion', $consulta))->assertOk()
            ->assertSee('Informes de estudio')->assertSee('Otro (no está en el catálogo)')->assertSee('Ecograf')
            ->assertSee('Informe de prueba dos.')->assertDontSee('Próximamente');
    });
});

describe('nada se borra y la consulta cerrada es de solo lectura', function () {
    test('retirar: queda guardado, se ve como "Retirado" y se puede reponer; no se borra ni con la consulta abierta', function () {
        $consulta = infEstAbierta();
        $filas = infEstFilas($consulta);
        $filas[0]['activo'] = '0';
        hcAutoguardar($consulta, infEstDatos($filas))->assertOk();

        $retirado = InformeEstudio::orderBy('orden')->first();
        expect($retirado->activo)->toBeFalse()->and(InformeEstudio::count())->toBe(2)
            ->and(fn () => $retirado->delete())->toThrow(RegistroClinicoNoSeBorra::class);
        // El PDF de un retirado no existe.
        $this->get(route('admin.pdf.informe-estudio', ['consulta' => $consulta, 'informe' => $retirado]))->assertNotFound();

        $filas[0]['activo'] = '1';
        hcAutoguardar($consulta->fresh(), infEstDatos($filas))->assertOk();
        expect($retirado->fresh()->activo)->toBeTrue();
    });

    test('consulta cerrada: el autoguardado da 409 y el modelo rechaza crear, modificar y borrar', function () {
        $consulta = infEstCerrada();
        $informe = InformeEstudio::orderBy('id')->first();

        hcAutoguardar($consulta, infEstDatos(infEstFilas($consulta)))->assertStatus(409);
        expect(fn () => $informe->update(['texto' => 'Cambiado.']))->toThrow(ConsultaCerrada::class)
            ->and(fn () => $consulta->informesEstudio()->create(['estudio_nombre' => 'X', 'texto' => 'Nuevo.', 'orden' => 9]))->toThrow(ConsultaCerrada::class)
            ->and(fn () => $informe->delete())->toThrow(ConsultaCerrada::class)
            ->and($informe->fresh()->texto)->toBe("Informe de prueba uno.\nSegunda línea.");

        $this->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSee('Consulta cerrada: no puede modificarse.')->assertSee('Informes de estudio')->assertSee('Solicitante: Dr. Externo Inventado');
    });

    test('corrección: solo con desbloqueo (de un solo uso, nunca quien atendió), con la versión anterior; la adenda sigue igual', function () {
        $consulta = infEstCerrada();
        $supervisor = User::factory()->conPermisos(['HISTORIA_CLINICA' => ['VER'], 'CORRECCIONES_HC' => ['VER', 'CREAR', 'EDITAR']])->create();

        // Quien atendió no desbloquea su consulta, aunque tenga el permiso.
        hcDarPermisos($this->medico, ['CORRECCIONES_HC' => ['VER', 'CREAR', 'EDITAR']]);
        $this->actingAs($this->medico)->post(route('admin.correcciones.desbloqueo.store', $consulta), ['motivo' => 'Corregir un error de tipeo del informe.'])->assertForbidden();

        $this->actingAs($supervisor)->post(route('admin.correcciones.desbloqueo.store', $consulta), ['motivo' => 'Corregir un error de tipeo del informe.']);
        $desbloqueo = DesbloqueoConsulta::where('consulta_id', $consulta->id)->sole();
        $this->get(route('admin.correcciones.corregir', $desbloqueo))->assertOk()->assertSee('Informes de estudio');

        $filas = infEstFilas($consulta);
        $filas[0]['texto'] = 'Informe corregido.';
        $filas[1]['activo'] = '0';
        $datos = [...hcFilasGuardadas($consulta), 'examen' => ['hallazgos' => $consulta->examenFisico?->hallazgos], ...infEstDatos($filas)];
        $this->post(route('admin.correcciones.corregir.guardar', $desbloqueo), $datos)->assertSessionHasNoErrors();

        $informes = $consulta->informesEstudio()->orderBy('orden')->get();
        expect($consulta->fresh()->finalizada())->toBeTrue()
            ->and($informes[0]->texto)->toBe('Informe corregido.')->and($informes[0]->modificado_por_id)->toBe($supervisor->id)
            ->and($informes[1]->activo)->toBeFalse()
            ->and(ConsultaVersion::sole()->snapshot['informes'][0]['texto'])->toBe("Informe de prueba uno.\nSegunda línea.")
            ->and($desbloqueo->fresh()->tieneEstado(Estado::REALIZADO))->toBeTrue();
        // Un solo uso: el segundo guardado ya no corrige.
        $filas[0]['texto'] = 'Segundo intento.';
        $this->post(route('admin.correcciones.corregir.guardar', $desbloqueo), [...$datos, ...infEstDatos($filas)]);
        expect($informes[0]->fresh()->texto)->toBe('Informe corregido.');

        // La adenda (profesional de la consulta) no toca los informes.
        $this->actingAs($this->medico)->post(route('admin.correcciones.adenda.store', $consulta), ['motivo' => 'Aclaración del informe.', 'texto' => 'Nota agregada de prueba.'])
            ->assertRedirect(route('admin.consultas.show', $consulta));
        expect($informes[0]->fresh()->texto)->toBe('Informe corregido.');
    });

    test('deshacer la atención no corre con un informe vigente', function () {
        $consulta = infEstAbierta();

        $this->post(route('admin.consultas.deshacer', $consulta))->assertSessionHas('error', \App\Support\Atencion\DeshacerAtencion::CON_CONTENIDO);
        expect($consulta->fresh()->enCurso())->toBeTrue();
    });
});

describe('PDF del informe', function () {
    test('abre con la consulta abierta y con la cerrada (application/pdf, no-store) y no deja archivos', function (string $estado) {
        $consulta = $estado === 'abierta' ? infEstAbierta() : infEstCerrada();
        $antes = infEstPdfs();

        foreach ($consulta->informesEstudio as $informe) {
            $respuesta = $this->actingAs($this->medico)->get(route('admin.pdf.informe-estudio', ['consulta' => $consulta, 'informe' => $informe]))->assertOk();
            expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')
                ->and(str_starts_with($respuesta->getContent(), '%PDF'))->toBeTrue()
                ->and($respuesta->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
                ->and($respuesta->headers->get('Pragma'))->toBe('no-cache')
                ->and($respuesta->headers->get('Content-Disposition'))->toStartWith('inline;');
        }
        expect(infEstPdfs())->toBe($antes);
    })->with(['abierta', 'cerrada']);

    test('contenido: clínica, paciente, profesional, fecha, estudio, solicitante y texto; sin diagnósticos ni numeración', function () {
        $consulta = infEstCerrada();
        $html = PdfInformeEstudio::html($consulta, InformeEstudio::orderBy('orden')->first());

        expect($html)->toContain('Informe de estudio')->toContain('Carmen')->toContain('Duarte')->toContain('FP-0000001')
            ->toContain('05/03/1990')->toContain('36 años')->toContain('MP-1')->toContain('06/10/2026')
            ->toContain('Ecografía inventada')->toContain('Dr. Externo Inventado')->toContain('Informe de prueba uno.')
            ->toContain('Firma')->toContain('Aclaración y sello')
            ->not->toContain('J06.9')->not->toContain('Rinofaringitis')->not->toContain('Dolor de garganta')->not->toContain('Huella');
        // Sin solicitante, no sale la fila.
        expect(PdfInformeEstudio::html($consulta, InformeEstudio::orderBy('orden')->skip(1)->first()))->not->toContain('Solicitante');
    });

    test('quién: el profesional, Supervisión médica y el Administrador; no otro médico, Enfermería ni Recepción', function () {
        $consulta = infEstCerrada();
        $ruta = route('admin.pdf.informe-estudio', ['consulta' => $consulta, 'informe' => $consulta->informesEstudio->first()]);
        $supervision = infEstUsuario(['PDF_CLINICO' => ['EXPORTAR'], 'CORRECCIONES_HC' => ['VER']]);

        foreach ([$this->medico, $supervision, User::factory()->administrador()->create()] as $usuario) {
            $this->actingAs($usuario)->get($ruta)->assertOk();
        }
        expect(infEstExportaciones())->toBe(3);

        foreach ([hcDarPermisos($this->otroMedico, ['PDF_CLINICO' => ['EXPORTAR']]), infEstUsuario(['PDF_CLINICO' => ['EXPORTAR'], 'PREPARACION' => ['VER', 'CREAR', 'EDITAR']], 'Enfermero/a'), infEstRecepcion()] as $usuario) {
            $this->actingAs($usuario)->get($ruta)->assertForbidden();
        }
        expect(infEstExportaciones())->toBe(3);
    });

    test('un informe de otra consulta o de una consulta anulada no existe (404)', function () {
        $consulta = infEstCerrada();
        $otra = infEstCerrada();

        $this->get(route('admin.pdf.informe-estudio', ['consulta' => $consulta, 'informe' => $otra->informesEstudio->first()]))->assertNotFound();
        $consulta->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO)])->saveQuietly();
        $this->get(route('admin.pdf.informe-estudio', ['consulta' => $consulta, 'informe' => $consulta->informesEstudio->first()]))->assertNotFound();
        expect(infEstExportaciones())->toBe(0);
    });

    test('auditoría: cada generación deja un EXPORTAR sobre la consulta con el informe; repetir deja otro', function () {
        $consulta = infEstAbierta();
        $informe = $consulta->informesEstudio()->orderBy('orden')->first();
        $ruta = route('admin.pdf.informe-estudio', ['consulta' => $consulta, 'informe' => $informe]);

        $this->get($ruta)->assertOk();
        $this->get($ruta)->assertOk();

        $eventos = LogAuditoria::where('accion', AccionAuditoria::EXPORTAR->value)->get();
        expect($eventos)->toHaveCount(2)
            ->and($eventos->pluck('tabla_afectada')->unique()->all())->toBe(['consultas'])
            ->and($eventos->pluck('registro_afectado_id')->unique()->all())->toBe([(string) $consulta->id])
            ->and($eventos->pluck('usuario_id')->unique()->all())->toBe([$this->medico->id])
            ->and($eventos->first()->detalle)->toBe("PDF del informe de estudio {$informe->id} de la consulta {$consulta->id}");
    });

    test('el enlace sale a quien puede generarlo y solo en los vigentes', function () {
        $consulta = infEstCerrada();
        $informe = $consulta->informesEstudio()->orderBy('orden')->first();

        $this->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSee(route('admin.pdf.informe-estudio', ['consulta' => $consulta, 'informe' => $informe]), false);
        $this->actingAs(infEstUsuario(['HISTORIA_CLINICA' => ['VER']]))->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSee('Informe de prueba uno.')->assertDontSee('data-pdf="informe-estudio"', false);
    });
});

describe('Recepción, Administrador y Auditoría', function () {
    test('Recepción no ve nada del informe: ni la consulta, ni la atención, ni el PDF; el Administrador ve todo', function () {
        $consulta = infEstCerrada();
        $recepcion = infEstRecepcion();

        $this->actingAs($recepcion)->get(route('admin.consultas.show', $consulta))->assertForbidden();
        $this->get(route('admin.historias-clinicas.show', $consulta->historia_clinica_id))->assertForbidden();
        $this->get(route('admin.pdf.informe-estudio', ['consulta' => $consulta, 'informe' => $consulta->informesEstudio->first()]))->assertForbidden();
        $this->get(route('admin.turnos.index'))->assertOk()->assertDontSee('Informe de prueba');

        $this->actingAs(User::factory()->administrador()->create())->get(route('admin.consultas.show', $consulta))->assertOk()
            ->assertSee('Informe de prueba uno.')->assertSee('Estudio escrito a mano');
    });

    test('contenido clínico: oculto en Auditoría a quien no tiene VER sobre la historia clínica', function () {
        infEstAbierta();
        expect(Auditoria::TABLAS_CLINICAS)->toContain('informes_estudio')->and(Auditoria::permisoDeContenido('informes_estudio'))->toBe('HISTORIA_CLINICA');
        $evento = LogAuditoria::where('tabla_afectada', 'consultas')->where('accion', 'EDITAR')->latest('id')->firstOrFail();
        expect(json_encode($evento->valor_nuevo, JSON_UNESCAPED_UNICODE))->toContain('Informe de prueba dos.');

        $this->actingAs(infEstUsuario(['AUDITORIA' => ['VER']]))->get(route('admin.auditoria.show', $evento))->assertOk()
            ->assertSee('se requiere permiso de lectura sobre Historia Clínica')->assertDontSee('Informe de prueba');
        $this->actingAs(infEstUsuario(['AUDITORIA' => ['VER'], 'HISTORIA_CLINICA' => ['VER']]))->get(route('admin.auditoria.show', $evento))->assertOk()
            ->assertSee('Informe de prueba dos.');
    });

    test('HTML y JS en el estudio, el solicitante y el texto: escapados en la pantalla, la lectura y el PDF', function () {
        $consulta = hcEnCurso();
        $malo = '<script>alert(1)</script>';
        hcAutoguardar($consulta, infEstDatos([['uid' => 'n', 'id' => '', 'estudio_id' => '', 'estudio_libre' => "E{$malo}", 'solicitante' => "S{$malo}", 'texto' => "T{$malo}"]]))->assertOk();

        $this->get(route('admin.consultas.atencion', $consulta))->assertOk()->assertDontSee($malo, false);
        hcFinalizar($consulta, hcDatos(infEstDatos(infEstFilas($consulta))))->assertSessionHasNoErrors();
        $this->get(route('admin.consultas.show', $consulta))->assertOk()->assertDontSee($malo, false)->assertSee('T&lt;script&gt;', false);
        expect(PdfInformeEstudio::html($consulta->fresh(), InformeEstudio::sole()))->not->toContain($malo);
    });
});

test('la migración corre contra un catálogo sin datos (sin estudios ni módulos) y en simulación', function () {
    $migracion = require base_path('database/migrations/2026_10_24_100001_create_informes_estudio.php');
    $migracion->down();
    expect(Schema::hasTable('informes_estudio'))->toBeFalse()->and(Estudio::count())->toBe(1);
    // Catálogo vacío: la migración no lee filas ni depende de módulos, permisos ni estudios.
    Estudio::query()->delete();

    $sentencias = DB::pretend(fn () => $migracion->up());
    expect($sentencias)->not->toBeEmpty()->and(Schema::hasTable('informes_estudio'))->toBeFalse();

    $migracion->up();
    expect(Schema::hasTable('informes_estudio'))->toBeTrue()->and(DB::table('informes_estudio')->count())->toBe(0);
});
