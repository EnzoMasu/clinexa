<?php

/*
 * Una consulta cerrada (FINALIZADA o ANULADA) queda llaveada: no se modifica, no se elimina y no se desactiva.
 * La regla está en los modelos (ProtegidoPorCierre) y las rutas de escritura responden 409 (middleware
 * consulta.abierta) a quien pase el permiso de la ruta; sin ese permiso, 403 (no se revela nada). Únicas
 * excepciones: la transición EN_CURSO → FINALIZADO de Finalizar y anular una receta EMITIDA con motivo.
 * Nada se borra, salvo "Quitar" un renglón de una receta en BORRADOR. Datos inventados (escenario.php).
 */

use App\Exceptions\ConsultaCerrada;
use App\Exceptions\RegistroClinicoNoSeBorra;
use App\Models\BloqueAnamnesis;
use App\Models\Consulta;
use App\Models\DetalleReceta;
use App\Models\Diagnostico;
use App\Models\Estado;
use App\Models\ExamenFisico;
use App\Models\HistoriaClinica;
use App\Models\Indicacion;
use App\Models\LogAuditoria;
use App\Models\Receta;
use App\Models\TipoIndicacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

const TABLAS_DE_LA_HISTORIA = ['historias_clinicas', 'consultas', 'bloques_anamnesis', 'examenes_fisicos', 'diagnosticos', 'indicaciones', 'recetas', 'detalles_receta'];

beforeEach(function () {
    hcEscenario();
    $this->reposo = TipoIndicacion::create(['nombre' => 'Reposo']);
    $this->medico = hcDarPermisos($this->medico, [...HC_PERMISOS_RECETAS, 'TURNOS' => ['VER', 'EDITAR'], 'PREPARACION' => ['VER', 'CREAR', 'EDITAR']]);
    $this->otroMedico = hcDarPermisos($this->otroMedico, [...HC_PERMISOS_RECETAS, 'PREPARACION' => ['VER', 'CREAR', 'EDITAR']]);
    $this->actingAs($this->medico);

    // Consulta completa: todas las secciones, una indicación y una receta EMITIDA; después, FINALIZADA.
    $this->consulta = hcEnCurso();
    hcAutoguardar($this->consulta, [...hcDatos(['examen' => ['peso' => '60', 'hallazgos' => 'Faringe congestiva.']]),
        'con_indicaciones' => '1', 'indicaciones' => [['id' => '', 'tipo_indicacion_id' => $this->reposo->id, 'descripcion' => 'Reposo 48 horas']]])->assertOk();
    $this->receta = hcReceta($this->consulta);
    hcEmitir($this->receta)->assertSessionHasNoErrors();
    hcFinalizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'con_indicaciones' => '1',
        'indicaciones' => [['id' => (string) Indicacion::sole()->id, 'activo' => '1', 'tipo_indicacion_id' => $this->reposo->id, 'descripcion' => 'Reposo 48 horas']]])->assertSessionHasNoErrors();
    $this->consulta->refresh();
    $this->receta->refresh();
    expect($this->consulta->finalizada())->toBeTrue()->and($this->receta->estaEmitida())->toBeTrue();
});

/** Foto de todas las tablas de la historia clínica (filas completas) y de los eventos de cambio del log. */
function fotoDeLaHistoria(): string
{
    return json_encode([
        ...collect(TABLAS_DE_LA_HISTORIA)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->orderBy('id')->get()])->all(),
        'cambios_en_el_log' => LogAuditoria::where('accion', '!=', 'VER')->count(),
    ]);
}

/** Lo que manda cada sección (como el formulario), para el autoguardado y Finalizar. */
function seccionDeEscritura(string $seccion): array
{
    return match ($seccion) {
        'motivo' => ['motivo_consulta' => 'Motivo cambiado.'],
        'anamnesis' => ['con_anamnesis' => '1', 'anamnesis' => [['uid' => 'n', 'id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => test()->alergias->id, 'contenido' => 'Nuevo.']]],
        'signos' => ['examen' => ['peso' => '75']],
        'hallazgos' => ['examen' => ['hallazgos' => 'Hallazgo cambiado.']],
        'diagnósticos' => ['con_diagnosticos' => '1', 'diagnostico_principal' => '0', 'diagnosticos' => [['uid' => 'n', 'id' => '', 'activo' => '1', 'codigo_cie10' => 'R51', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => '']]],
        'indicaciones' => ['con_indicaciones' => '1', 'indicaciones' => [['uid' => 'n', 'id' => '', 'tipo_indicacion_id' => '', 'descripcion' => 'Indicación nueva.']]],
    };
}

/** Los actores: [usuario, si tiene el permiso de la ruta]. */
function actorDeLaMatriz(string $actor): User
{
    return match ($actor) {
        'profesional dueño' => test()->medico,
        'otro profesional' => test()->otroMedico,
        'Administrador' => User::factory()->administrador()->create(),
        'enfermería' => User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->create(),
    };
}

/**
 * Cada intento de escritura: [nombre, closure que hace el pedido, permiso que exige la ruta]. Las secciones
 * de la consulta van por el autoguardado y por Finalizar; las recetas, por sus rutas.
 */
function intentosDeEscritura(): array
{
    $c = test()->consulta;
    $r = test()->receta;
    $version = fn () => ['version' => $c->fresh()->version()];
    $intentos = [];
    foreach (['motivo', 'anamnesis', 'signos', 'hallazgos', 'diagnósticos', 'indicaciones'] as $seccion) {
        $intentos["{$seccion} por el autoguardado"] = [fn () => test()->patchJson(route('admin.consultas.autoguardado', $c), [...seccionDeEscritura($seccion), ...$version()]), ['PREPARACION.EDITAR', 'HISTORIA_CLINICA.CREAR', 'HISTORIA_CLINICA.EDITAR']];
        $intentos["{$seccion} por Finalizar"] = [fn () => test()->post(route('admin.consultas.finalizar', $c), [...hcDatos(), ...seccionDeEscritura($seccion), ...$version()]), ['HISTORIA_CLINICA.CREAR', 'HISTORIA_CLINICA.EDITAR']];
    }

    return $intentos + [
        'deshacer' => [fn () => test()->post(route('admin.consultas.deshacer', $c)), ['HISTORIA_CLINICA.CREAR', 'HISTORIA_CLINICA.EDITAR']],
        'marcar como lista' => [fn () => test()->post(route('admin.preparacion.lista', $c)), ['PREPARACION.EDITAR', 'HISTORIA_CLINICA.CREAR', 'HISTORIA_CLINICA.EDITAR']],
        'reabrir la preparación' => [fn () => test()->post(route('admin.preparacion.reabrir', $c)), ['PREPARACION.EDITAR', 'HISTORIA_CLINICA.CREAR', 'HISTORIA_CLINICA.EDITAR']],
        'recetas: formulario de alta' => [fn () => test()->get(route('admin.recetas.create', $c)), ['RECETAS.CREAR']],
        'recetas: crear' => [fn () => test()->post(route('admin.recetas.store', $c), ['con_detalles' => '1', 'detalles' => [hcRenglon()], 'observaciones' => '']), ['RECETAS.CREAR']],
        'recetas: formulario de edición' => [fn () => test()->get(route('admin.recetas.edit', $r)), ['RECETAS.EDITAR']],
        'recetas: guardar' => [fn () => test()->put(route('admin.recetas.update', $r), ['version' => $r->version(), 'con_detalles' => '1', 'detalles' => [hcRenglon(['dosis' => 'Otra'])]]), ['RECETAS.EDITAR']],
        'recetas: emitir' => [fn () => test()->post(route('admin.recetas.emitir', $r), ['version' => $r->version()]), ['RECETAS.CREAR']],
        'recetas: anular y corregir' => [fn () => test()->post(route('admin.recetas.corregir', $r), ['motivo' => 'Dosis equivocada']), ['RECETAS.EDITAR']],
    ];
}

test('matriz: cada sección y cada ruta de escritura sobre una consulta FINALIZADA se rechaza para todos, y nada cambia', function (string $actor) {
    $usuario = actorDeLaMatriz($actor);
    $this->actingAs($usuario);
    $antes = fotoDeLaHistoria();
    $resultados = [];

    foreach (intentosDeEscritura() as $nombre => [$pedido, $permisos]) {
        $tienePermiso = collect($permisos)->contains(fn (string $p) => $usuario->tienePermiso(...explode('.', $p)));
        $respuesta = $pedido();
        // Con el permiso de la ruta: 409 "consulta cerrada" (antes de la regla de cada acción). Sin él: 403.
        $esperado = $tienePermiso ? 409 : 403;
        $resultados[$nombre] = $respuesta->status();
        expect($respuesta->status())->toBe($esperado, "{$actor} · {$nombre}");
        if ($esperado === 409) {
            // En JSON (autoguardado) el mensaje viaja codificado: se compara ya decodificado.
            $mensaje = $respuesta->headers->get('Content-Type') === 'application/json' ? $respuesta->json('message') : $respuesta->getContent();
            expect(str_contains((string) $mensaje, ConsultaCerrada::MENSAJE))->toBeTrue("{$actor} · {$nombre}: falta el mensaje");
        }
        expect(fotoDeLaHistoria())->toBe($antes, "{$actor} · {$nombre}: cambió algo en la base");
    }

    expect($resultados)->toHaveCount(21); // 6 secciones × (autoguardado y Finalizar) + 9 rutas de la consulta y sus recetas
})->with(['profesional dueño', 'otro profesional', 'Administrador', 'enfermería']);

test('la enfermería recibe 409 (no 403) en lo que sí puede escribir: preparación y autoguardado', function () {
    $this->actingAs(actorDeLaMatriz('enfermería'));

    $this->patchJson(route('admin.consultas.autoguardado', $this->consulta), [...seccionDeEscritura('signos'), 'version' => $this->consulta->version()])
        ->assertStatus(409)->assertJson(['message' => ConsultaCerrada::MENSAJE]);
    $this->post(route('admin.preparacion.lista', $this->consulta))->assertStatus(409)->assertSee(ConsultaCerrada::MENSAJE);
});

test('una consulta ANULADA también está cerrada', function () {
    $anulada = hcEnCurso();
    $this->post(route('admin.consultas.deshacer', $anulada))->assertRedirect();
    expect($anulada->fresh()->anulada())->toBeTrue();

    $this->patchJson(route('admin.consultas.autoguardado', $anulada), ['motivo_consulta' => 'x', 'version' => $anulada->fresh()->version()])->assertStatus(409);
    expect(fn () => $anulada->fresh()->update(['motivo_consulta' => 'x']))->toThrow(ConsultaCerrada::class);
});

describe('desde el modelo (servicio, tinker...)', function () {
    test('cualquier cambio en la consulta o en lo que cuelga de ella se rechaza', function () {
        $antes = fotoDeLaHistoria();
        $c = $this->consulta->fresh();

        foreach ([
            'motivo' => fn () => $c->update(['motivo_consulta' => 'Cambiado']),
            'estado (reabrir)' => fn () => $c->fresh()->forceFill(['estado_id' => Estado::idDe(Estado::EN_CURSO)])->save(),
            'anular la consulta' => fn () => $c->fresh()->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO)])->save(),
            'tocar (versión)' => function () use ($c) {
                \Illuminate\Support\Carbon::setTestNow(now()->addSecond()); // con el reloj quieto, touch() no tendría nada que guardar
                $c->fresh()->touch();
            },
            'bloque: cambiar' => fn () => BloqueAnamnesis::first()->update(['contenido' => 'x']),
            'bloque: agregar' => fn () => $c->bloquesAnamnesis()->create(['tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'x', 'orden' => 9]),
            'examen: signos' => fn () => ExamenFisico::sole()->update(['peso' => 99]),
            'examen: hallazgos' => fn () => ExamenFisico::sole()->update(['hallazgos' => 'x']),
            'diagnóstico: cambiar' => fn () => Diagnostico::first()->update(['descripcion_adicional' => 'x']),
            'diagnóstico: agregar' => fn () => $c->diagnosticos()->create(['codigo_cie10' => 'R51', 'tipo' => 'PRESUNTIVO', 'principal' => false]),
            'indicación: retirar' => fn () => Indicacion::sole()->update(['activo' => false]),
            'receta: nueva' => fn () => Receta::create(['consulta_id' => $c->id]),
            'receta: observaciones' => fn () => Receta::find($this->receta->id)->update(['observaciones' => 'x']),
            'renglón: cambiar' => fn () => DetalleReceta::first()->update(['dosis' => 'x']),
            'renglón: agregar' => fn () => DetalleReceta::create([...hcRenglon(), 'receta_id' => $this->receta->id, 'orden' => 9]),
        ] as $nombre => $cambio) {
            $error = null;
            try {
                $cambio();
            } catch (Throwable $e) {
                $error = $e;
            }
            expect($error)->toBeInstanceOf(ConsultaCerrada::class, "{$nombre}: no se rechazó con ConsultaCerrada (".($error ? $error::class : 'sin error').')')
                ->and($error->getMessage())->toBe(ConsultaCerrada::MENSAJE)
                ->and(fotoDeLaHistoria())->toBe($antes, "{$nombre}: cambió algo en la base");
        }
    });

    test('en masa tampoco: ni update ni delete ni truncate', function () {
        expect(fn () => Consulta::query()->update(['motivo_consulta' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => BloqueAnamnesis::where('consulta_id', $this->consulta->id)->update(['contenido' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => Diagnostico::query()->delete())->toThrow(RegistroClinicoNoSeBorra::class)
            ->and(fn () => DetalleReceta::query()->forceDelete())->toThrow(RegistroClinicoNoSeBorra::class)
            ->and(fn () => Indicacion::query()->truncate())->toThrow(RegistroClinicoNoSeBorra::class);
        expect(Diagnostico::count())->toBe(1)->and(BloqueAnamnesis::pluck('contenido')->all())->not->toContain('x');
    });

    test('excepción permitida: anular una receta EMITIDA con su motivo (también por la pantalla)', function () {
        $this->post(route('admin.recetas.anular', $this->receta), ['motivo' => 'Dosis equivocada'])->assertSessionHasNoErrors();

        $receta = $this->receta->fresh();
        expect($receta->estaAnulada())->toBeTrue()->and($receta->motivo_anulacion)->toBe('Dosis equivocada')
            ->and(Receta::count())->toBe(1); // "Anular y corregir" no crea reemplazo en una consulta cerrada
        // Anulada, ya no se toca: ni siquiera con otra "anulación".
        expect(fn () => $receta->forceFill(['motivo_anulacion' => 'Otro'])->save())->toThrow(ConsultaCerrada::class);
    });

    test('excepción permitida: Finalizar pasa de EN_CURSO a FINALIZADO (estado, fecha y cierre del turno)', function () {
        $turno = hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30']);
        $consulta = hcEnCurso($turno);
        hcFinalizar($consulta, hcDatos())->assertSessionHasNoErrors();

        expect($consulta->fresh()->finalizada())->toBeTrue()->and($consulta->fresh()->finalizada_en)->not->toBeNull()
            ->and($turno->fresh()->estado->codigo)->toBe(Estado::ATENDIDO);
    });

    test('crear una consulta (aunque nazca cerrada) no es modificar una cerrada; tocar una abierta, sí se puede', function () {
        $abierta = hcEnCurso();
        $abierta->update(['motivo_consulta' => 'Abierta: se puede.']);
        expect($abierta->fresh()->motivo_consulta)->toBe('Abierta: se puede.');
    });
});

describe('nada se borra', function () {
    test('no hay rutas DELETE en la aplicación', function () {
        $delete = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('DELETE', $r->methods(), true))->map(fn ($r) => $r->uri())->values()->all();
        expect($delete)->toBe([]);
    });

    test('borrar cualquier registro de la historia clínica se rechaza, también con la consulta abierta', function () {
        $abierta = hcConsultaEnCurso();
        $receta = hcReceta($abierta);

        foreach ([
            'historia' => fn () => HistoriaClinica::first()->delete(),
            'consulta' => fn () => $abierta->fresh()->delete(),
            'bloque' => fn () => $abierta->bloquesAnamnesis()->first()->delete(),
            'examen' => fn () => $abierta->examenFisico()->first()->delete(),
            'diagnóstico' => fn () => $abierta->diagnosticos()->first()->delete(),
            'receta (borrador)' => fn () => Receta::find($receta->id)->delete(),
        ] as $nombre => $borrar) {
            expect($borrar)->toThrow(RegistroClinicoNoSeBorra::class);
        }
        // Cerrada: también (con su propio aviso).
        expect(fn () => $this->consulta->fresh()->delete())->toThrow(ConsultaCerrada::class)
            ->and(fn () => DetalleReceta::where('receta_id', $this->receta->id)->first()->delete())->toThrow(ConsultaCerrada::class);
        expect(HistoriaClinica::count())->toBe(1)->and(Consulta::count())->toBe(2);
    });

    test('única excepción: "Quitar" un renglón de una receta en BORRADOR', function () {
        $abierta = hcConsultaEnCurso();
        $receta = hcReceta($abierta, [hcRenglon(), hcRenglon(['medicamento' => 'Ibuprofeno 400 mg'])]);
        $quitar = $receta->detalles()->orderBy('orden')->get()->last();

        $quitar->delete();
        expect($receta->detalles()->count())->toBe(1);
        hcEmitir($receta->fresh())->assertSessionHasNoErrors();
        expect(fn () => $receta->detalles()->first()->delete())->toThrow(RegistroClinicoNoSeBorra::class);
    });

    test('en el código, el único delete() sobre tablas clínicas es el "Quitar" de renglones del borrador', function () {
        $usos = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.php'))
            ->flatMap(function ($f) {
                $lineas = file($f->getPathname());

                return collect($lineas)->filter(fn ($l) => preg_match('/->(delete|forceDelete|truncate)\(\)/', $l))
                    ->map(fn ($l, $n) => str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1)).':'.($n + 1));
            })
            // Los builders que justamente lo prohíben, y lo que no es de la historia clínica (redes de un proveedor).
            ->reject(fn ($uso) => str_contains($uso, 'Models/Builders/') || str_contains($uso, 'ProveedorController.php') || str_contains($uso, 'Concerns/ProtegidoPorCierre.php'))
            ->values()->all();

        expect($usos)->toHaveCount(1)->and($usos[0])->toStartWith('app/Http/Controllers/Admin/RecetaController.php:');
    });
});

describe('auditoría', function () {
    test('el cierre deja su evento como siempre, y los intentos rechazados no dejan eventos de cambio', function () {
        $finalizar = LogAuditoria::where('tabla_afectada', 'consultas')->where('registro_afectado_id', (string) $this->consulta->id)->where('detalle', 'Finalizar')->sole();
        expect($finalizar->valor_nuevo['estado_id'])->toBe(Estado::idDe(Estado::FINALIZADO));

        $antes = LogAuditoria::where('accion', '!=', 'VER')->count();
        $this->patchJson(route('admin.consultas.autoguardado', $this->consulta), ['motivo_consulta' => 'x', 'version' => $this->consulta->version()])->assertStatus(409);
        $this->post(route('admin.recetas.store', $this->consulta), ['con_detalles' => '1', 'detalles' => [hcRenglon()]])->assertStatus(409);
        expect(fn () => $this->consulta->fresh()->update(['motivo_consulta' => 'x']))->toThrow(ConsultaCerrada::class);

        expect(LogAuditoria::where('accion', '!=', 'VER')->count())->toBe($antes);
    });
});

test('el autoguardado solo vale en EN_PREPARACION y EN_CURSO', function () {
    $turno = hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30']);
    $this->post(route('admin.preparacion.preparar', $turno));
    $preparacion = Consulta::where('turno_id', $turno->id)->sole();
    hcAutoguardar($preparacion, ['examen' => ['peso' => '61']])->assertOk();

    $enCurso = hcEnCurso();
    hcAutoguardar($enCurso, ['motivo_consulta' => 'Ok.'])->assertOk();

    hcAutoguardar($this->consulta, ['motivo_consulta' => 'No.'])->assertStatus(409);
});

test('la lectura de una consulta cerrada lo dice y no ofrece ninguna edición', function () {
    $this->get(route('admin.consultas.show', $this->consulta))->assertOk()
        ->assertSee('Consulta cerrada: no puede modificarse.')
        ->assertDontSee('Guardar cambios')->assertDontSee('Nueva receta')->assertDontSee(route('admin.recetas.corregir', $this->receta), false)
        ->assertSee(route('admin.recetas.anular', $this->receta), false); // anular una emitida sí
});
