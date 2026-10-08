<?php

use App\Http\Middleware\VerificarPermiso;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Atención sin turno (urgencias): la entrada directa para iniciar una consulta sin turno y el listado
 * de las atenciones sin turno del día. "Ahora" del escenario: martes 06/10/2026 09:00 en Paraguay.
 */

beforeEach(fn () => hcEscenario());

afterEach(fn () => Carbon::setTestNow());

/** La URL del buscador, como viaja al componente (JSON para Alpine, con las barras escapadas). */
const URL_BUSCADOR = 'atencion-sin-turno\/pacientes';

const AVISO_SIN_ATENCION = 'Solo un profesional activo con permiso para crear consultas puede iniciar una atención.';

/** Una consulta sin turno guardada en ese momento (UTC), sin pasar por el formulario. */
function atencion(string $momentoUtc, array $cambios = []): Consulta
{
    Carbon::setTestNow(Carbon::parse($momentoUtc, 'UTC'));

    return Consulta::create(['historia_clinica_id' => test()->historia->id, 'profesional_id' => test()->profesional->id, 'motivo_consulta' => 'Urgencia', ...$cambios]);
}

function buscarPacientes(string $q)
{
    return test()->getJson(route('admin.atencion-sin-turno.pacientes', ['q' => $q]));
}

describe('permisos', function () {
    test('sin VER: 403', function () {
        $this->actingAs(User::factory()->conPermisos(['PACIENTES' => ['VER']])->create());

        $this->get(route('admin.atencion-sin-turno.index'))->assertForbidden();
    });

    test('con VER sin ser profesional (ni siquiera el Administrador): ve el listado, no la parte de iniciar atención', function (string $quien) {
        $this->actingAs($quien === 'administrador' ? User::factory()->administrador()->create() : User::factory()->conPermisos(['HISTORIA_CLINICA' => ['VER']])->create());

        $this->get(route('admin.atencion-sin-turno.index'))->assertOk()
            ->assertSee('Atenciones sin turno')->assertSee(AVISO_SIN_ATENCION)
            ->assertDontSee(URL_BUSCADOR, false)->assertDontSee('paciente_atencion');
    })->with(['administrador', 'con solo VER']);

    test('un profesional activo con CREAR ve el buscador, que lleva al mismo formulario de la historia', function () {
        $html = $this->get(route('admin.atencion-sin-turno.index'))->assertOk()
            ->assertDontSee(AVISO_SIN_ATENCION)
            ->assertSee(URL_BUSCADOR, false)
            ->assertSee('Si el paciente no aparece, puede que todavía no esté registrado o que esté inactivo.')
            ->assertSee('uno inactivo se reactiva desde Pacientes.')
            ->getContent();

        // "Atender" usa la ruta de la historia (con el id de la historia elegida en lugar de __ID__), y sin JS hay aviso.
        expect($html)->toContain('historias-clinicas\/__ID__\/consultas\/create')
            ->toContain('<noscript>');
        // Sin permisos en Personas ni en Pacientes, ninguno de los enlaces.
        expect($html)->not->toContain(route('admin.personas.create'))->not->toContain(route('admin.pacientes.create'))
            ->not->toContain('Ir a Pacientes');
    });

    test('los enlaces aparecen solo con el permiso de su módulo: CREAR para dar de alta, EDITAR para reactivar', function (array $permisos, array $visibles) {
        [$medico] = hcMedico(['apellidos' => 'Ríos', 'nombres' => 'Ana'], 'MP-7', [...HC_PERMISOS_MEDICO, ...$permisos]);
        $html = $this->actingAs($medico)->get(route('admin.atencion-sin-turno.index'))->assertOk()->getContent();

        $enlaces = ['persona' => route('admin.personas.create'), 'paciente' => route('admin.pacientes.create'), 'reactivar' => 'Ir a Pacientes'];
        foreach ($enlaces as $nombre => $texto) {
            in_array($nombre, $visibles, true) ? expect($html)->toContain($texto) : expect($html)->not->toContain($texto);
        }
    })->with([
        'CREAR en los dos' => [['PERSONAS' => ['VER', 'CREAR'], 'PACIENTES' => ['VER', 'CREAR']], ['persona', 'paciente']],
        'EDITAR en Pacientes' => [['PACIENTES' => ['VER', 'EDITAR']], ['reactivar']],
        'solo VER en Pacientes' => [['PACIENTES' => ['VER']], []],
        'todo' => [['PERSONAS' => ['VER', 'CREAR'], 'PACIENTES' => ['VER', 'CREAR', 'EDITAR']], ['persona', 'paciente', 'reactivar']],
    ]);

    test('el buscador de pacientes exige CREAR sobre la historia clínica', function () {
        $this->actingAs(User::factory()->conPermisos(['HISTORIA_CLINICA' => ['VER', 'EDITAR'], 'TURNOS' => ['VER', 'CREAR']])->create());

        buscarPacientes('Duarte')->assertForbidden();
    });

    test('con CREAR pero sin ser profesional (ni siquiera el Administrador), el buscador da 403 y no devuelve ningún nombre', function (string $quien) {
        $this->actingAs($quien === 'administrador' ? User::factory()->administrador()->create() : User::factory()->conPermisos(HC_PERMISOS_MEDICO)->create());

        $respuesta = buscarPacientes('Duarte')->assertForbidden();
        expect($respuesta->getContent())->not->toContain('Duarte')->not->toContain('Carmen')->not->toContain('FP-0000001');
    })->with(['administrador', 'con CREAR sin ser profesional']);

    test('un profesional INACTIVO también recibe 403 en el buscador', function () {
        $this->profesional->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);

        $this->actingAs($this->medico->fresh());
        buscarPacientes('Duarte')->assertForbidden();
    });

    test('cada resultado trae solo el id de la historia y "nombre — documento · ficha"', function () {
        $resultado = buscarPacientes('Duarte')->assertOk()->json('0');

        expect(array_keys($resultado))->toBe(['id', 'texto'])
            ->and($resultado['id'])->toBe($this->historia->id)
            ->and($resultado['texto'])->toBe('Duarte, Carmen — '.$this->paciente->persona->tipoDocumento->codigo.' '.$this->paciente->persona->nro_documento.' · Ficha FP-0000001');
    });

    test('un profesional INACTIVO no ve la parte de iniciar atención', function () {
        $this->profesional->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);

        $this->actingAs($this->medico->fresh())->get(route('admin.atencion-sin-turno.index'))->assertOk()->assertSee(AVISO_SIN_ATENCION);
    });
});

describe('buscador de pacientes', function () {
    test('solo activos, desde 2 caracteres, y devuelve la historia clínica para el formulario', function () {
        $inactivo = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Inés'])->id, 'nro_ficha' => 'FP-0000002', 'estado_id' => Estado::idDe(Estado::INACTIVO)]);

        buscarPacientes('Duarte')->assertOk()->assertExactJson([
            ['id' => $this->historia->id, 'texto' => 'Duarte, Carmen — '.$this->paciente->persona->tipoDocumento->codigo.' '.$this->paciente->persona->nro_documento.' · Ficha FP-0000001'],
        ]);
        buscarPacientes('FP-0000001')->assertJsonCount(1);
        buscarPacientes('D')->assertExactJson([]);
        expect(buscarPacientes('Inés')->json())->toBe([]);
        expect($inactivo->historiaClinica)->not->toBeNull();
    });

    test('devuelve como máximo 15', function () {
        foreach (range(2, 21) as $n) {
            Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Benegas'])->id, 'nro_ficha' => Paciente::nroFicha($n)]);
        }

        expect(buscarPacientes('Benegas')->json())->toHaveCount(15);
    });

    test('lo elegido lleva al mismo formulario y ruta que "Atender sin turno" de la historia', function () {
        $historia = buscarPacientes('Duarte')->json('0.id');

        $this->get(route('admin.consultas.create', $historia))->assertOk()->assertSee('Sin turno (urgencia)');
        hcGuardarNueva()->assertRedirect();
        expect(Consulta::sole()->turno_id)->toBeNull();
    });
});

describe('listado del día', function () {
    test('bordes del día local: 00:30 y 23:59 caen en su día; la medianoche exacta es del día que empieza (fin exclusivo)', function () {
        // Paraguay = UTC-3. El 06/10 local va de 03:00 UTC del 06/10 (incluido) a 03:00 UTC del 07/10 (excluido).
        atencion('2026-10-06 03:00:00', ['motivo_consulta' => 'MEDIANOCHE-DEL-06']); // 00:00 local del 06/10: primer instante
        atencion('2026-10-06 03:30:00', ['motivo_consulta' => 'MADRUGADA-DEL-06']); // 00:30 local del 06/10
        atencion('2026-10-07 02:59:00', ['motivo_consulta' => 'CASI-FIN-DEL-06']); // 23:59 local del 06/10
        atencion('2026-10-07 03:00:00', ['motivo_consulta' => 'MEDIANOCHE-DEL-07']); // 00:00 local del 07/10: ya no es del 06
        atencion('2026-10-06 02:59:00', ['motivo_consulta' => 'CASI-FIN-DEL-05']); // 23:59 local del 05/10
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $dia = fn (string $fecha) => $this->get(route('admin.atencion-sin-turno.index', ['fecha' => $fecha]))->assertOk();

        $dia('06/10/2026')->assertSeeInOrder(['23:59', 'CASI-FIN-DEL-06', '00:30', 'MADRUGADA-DEL-06', '00:00', 'MEDIANOCHE-DEL-06'])
            ->assertDontSee('MEDIANOCHE-DEL-07')->assertDontSee('CASI-FIN-DEL-05');
        $dia('07/10/2026')->assertSee('MEDIANOCHE-DEL-07')
            ->assertDontSee('CASI-FIN-DEL-06')->assertDontSee('MADRUGADA-DEL-06')->assertDontSee('MEDIANOCHE-DEL-06');
        $dia('05/10/2026')->assertSee('CASI-FIN-DEL-05')
            ->assertDontSee('MADRUGADA-DEL-06')->assertDontSee('MEDIANOCHE-DEL-06')->assertDontSee('CASI-FIN-DEL-06');
    });

    test('solo las sin turno del día pedido, en hora de Paraguay (22:30 local es su día, aunque en UTC sea el siguiente)', function () {
        $deHoy = atencion('2026-10-06 11:00:00', ['motivo_consulta' => 'HOY-MANANA']); // 08:00 local
        $nocturna = atencion('2026-10-07 01:30:00', ['motivo_consulta' => 'HOY-NOCHE']); // 22:30 local del 06/10
        $deMadrugada = atencion('2026-10-06 02:00:00', ['motivo_consulta' => 'AYER-NOCHE']); // 23:00 local del 05/10
        $deManana = atencion('2026-10-07 04:00:00', ['motivo_consulta' => 'DIA-SIGUIENTE']); // 01:00 local del 07/10
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        $conTurno = Consulta::create(['historia_clinica_id' => $this->historia->id, 'profesional_id' => $this->profesional->id, 'motivo_consulta' => 'CON-TURNO', 'turno_id' => hcTurno()->id]);

        $this->get(route('admin.atencion-sin-turno.index'))->assertOk()
            ->assertSeeInOrder(['22:30', 'HOY-NOCHE', '08:00', 'HOY-MANANA'])
            ->assertDontSee('AYER-NOCHE')->assertDontSee('DIA-SIGUIENTE')->assertDontSee('CON-TURNO')
            ->assertSee(route('admin.consultas.show', $deHoy))->assertSee(route('admin.consultas.show', $nocturna));

        $this->get(route('admin.atencion-sin-turno.index', ['fecha' => '05/10/2026']))->assertOk()->assertSee('AYER-NOCHE')->assertDontSee('HOY-NOCHE');
        $this->get(route('admin.atencion-sin-turno.index', ['fecha' => '07/10/2026']))->assertOk()->assertSee('DIA-SIGUIENTE')->assertDontSee('HOY-NOCHE');
        // A las 22:30 de Paraguay (01:30 UTC del 07), "hoy" sigue siendo el 06/10.
        Carbon::setTestNow(Carbon::parse('2026-10-07 01:30:00', 'UTC'));
        $this->get(route('admin.atencion-sin-turno.index'))->assertOk()->assertSee('HOY-NOCHE')->assertDontSee('AYER-NOCHE');

        expect([$deMadrugada->exists, $deManana->exists, $conTurno->exists])->toBe([true, true, true]);
    });

    test('columnas: hora, paciente con ficha, profesional y motivo cortado a 90; búsqueda en vivo por paciente, ficha o profesional', function () {
        $otro = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Zárate', 'nombres' => 'Luis'])->id, 'nro_ficha' => 'FP-0000077']);
        atencion('2026-10-06 11:00:00', ['motivo_consulta' => 'Dolor torácico '.str_repeat('opresivo ', 15).'FIN-DEL-MOTIVO']);
        atencion('2026-10-06 11:30:00', ['historia_clinica_id' => $otro->historiaClinica->id, 'profesional_id' => $this->otroProfesional->id, 'motivo_consulta' => 'Fiebre']);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $this->get(route('admin.atencion-sin-turno.index'))->assertOk()
            ->assertSeeInOrder(['08:30', 'Zárate, Luis', 'Ficha FP-0000077', 'Insfrán, Laura', 'Fiebre', '08:00', 'Duarte, Carmen', 'Ficha FP-0000001', 'Benítez, Rosa', 'Dolor torácico'])
            ->assertDontSee('FIN-DEL-MOTIVO');

        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        foreach (['Zárate', 'FP-0000077', 'Insfrán'] as $q) {
            $this->get(route('admin.atencion-sin-turno.index', ['q' => $q]), $ajax)->assertOk()->assertSee('Zárate, Luis')->assertDontSee('Duarte, Carmen');
        }
    });

    test('no trae el contenido completo de las consultas: ni anamnesis, ni examen, ni diagnósticos', function () {
        hcConsulta([
            'anamnesis' => [['id' => '', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'SECRETO-ANAMNESIS']],
            'examen' => ['hallazgos' => 'SECRETO-HALLAZGO', 'presion_arterial' => '135/85'],
            'diagnosticos' => [['id' => '', 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => 'SECRETO-DIAGNOSTICO']],
        ]);

        $html = $this->get(route('admin.atencion-sin-turno.index'))->assertOk()->assertSee('Dolor de garganta')->getContent();
        foreach (['SECRETO-ANAMNESIS', 'SECRETO-HALLAZGO', 'SECRETO-DIAGNOSTICO', '135/85', 'J06.9', 'Penicilina'] as $dato) {
            expect($html)->not->toContain($dato);
        }
    });

    test('pagina de a 20, más recientes primero', function () {
        foreach (range(1, 25) as $n) {
            atencion(sprintf('2026-10-06 %02d:%02d:00', 4 + intdiv($n, 6), ($n % 6) * 10), ['motivo_consulta' => "Atención {$n}"]);
        }
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $consultas = $this->get(route('admin.atencion-sin-turno.index'))->viewData('consultas');
        expect($consultas->total())->toBe(25)->and($consultas->count())->toBe(20)
            ->and($consultas->first()->motivo_consulta)->toBe('Atención 25');
    });
});

describe('seguridad', function () {
    test('no-store y nosniff en la pantalla, la búsqueda en vivo y el buscador de pacientes', function () {
        foreach ([$this->get(route('admin.atencion-sin-turno.index')),
            $this->get(route('admin.atencion-sin-turno.index', ['q' => 'x']), ['X-Requested-With' => 'XMLHttpRequest']),
            buscarPacientes('Duarte')] as $respuesta) {
            $respuesta->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            expect($respuesta->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        }
    });

    test('abrir la pantalla registra un VER sobre consultas sin registro; la búsqueda en vivo y el buscador no', function () {
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->get(route('admin.atencion-sin-turno.index'))->assertOk();
        $this->get(route('admin.atencion-sin-turno.index'))->assertOk(); // dentro de 5 minutos
        $this->get(route('admin.atencion-sin-turno.index', ['q' => 'Duarte']), $ajax)->assertOk();
        $this->get(route('admin.atencion-sin-turno.index', ['fecha' => '05/10/2026']), $ajax)->assertOk();
        buscarPacientes('Duarte')->assertOk();

        expect(LogAuditoria::where('accion', 'VER')->get()->map(fn ($e) => [$e->tabla_afectada, $e->registro_afectado_id, $e->usuario_id])->all())
            ->toBe([['consultas', null, $this->medico->id]]);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->get(route('admin.atencion-sin-turno.index'))->assertOk();
        expect(LogAuditoria::where('accion', 'VER')->count())->toBe(2);
    });

    test('sin permiso, el 403 no registra lectura', function () {
        $this->actingAs(User::factory()->conPermisos(['PACIENTES' => ['VER']])->create());
        $this->get(route('admin.atencion-sin-turno.index'))->assertForbidden();

        expect(LogAuditoria::where('accion', 'VER')->count())->toBe(0);
    });

    test('una marca "tabla=" que no es del módulo es un error de la ruta', function () {
        $middleware = new VerificarPermiso;
        $metodo = (new ReflectionMethod($middleware, 'tablaMarcada'));

        expect($metodo->invoke($middleware, 'HISTORIA_CLINICA', ['tabla=consultas']))->toBe('consultas')
            ->and($metodo->invoke($middleware, 'HISTORIA_CLINICA', []))->toBeNull()
            ->and(fn () => $metodo->invoke($middleware, 'HISTORIA_CLINICA', ['tabla=users']))->toThrow(LogicException::class);
    });

    test('HTML y JS en el motivo y en el nombre: escapados en el listado y en el buscador', function () {
        $img = '<img src=x onerror=alert(1)>';
        $script = '"><script>alert(1)</script>';
        $this->paciente->persona->update(['apellidos' => "Duarte {$img}", 'nombres' => "Carmen {$script}"]);
        atencion('2026-10-06 11:00:00', ['motivo_consulta' => "Motivo {$img} {$script}"]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        foreach ([$this->get(route('admin.atencion-sin-turno.index')), $this->get(route('admin.atencion-sin-turno.index', ['q' => 'Duarte']), ['X-Requested-With' => 'XMLHttpRequest'])] as $respuesta) {
            $respuesta->assertOk()
                ->assertDontSee($img, false)->assertDontSee('<script>alert(1)</script>', false)
                ->assertSee('Duarte &lt;img src=x onerror=alert(1)&gt;, Carmen &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false)
                ->assertSee('Motivo &lt;img src=x onerror=alert(1)&gt; &quot;&gt;&lt;script&gt;', false);
        }

        // El buscador responde JSON: el texto va como dato (lo muestra x-text), con < > " codificados (\u003C...).
        $respuesta = buscarPacientes('Duarte')->assertOk()->assertHeader('Content-Type', 'application/json');
        expect($respuesta->getContent())->not->toContain('<img')->not->toContain('<script')->toContain('\u003Cimg')
            ->and($respuesta->json('0.texto'))->toStartWith("Duarte {$img}, Carmen {$script}");
    });
});

describe('botón en el listado de historias', function () {
    test('"Atender sin turno" en cada fila solo si el usuario puede atender, y no en pacientes inactivos', function () {
        $inactivo = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Zárate'])->id, 'nro_ficha' => 'FP-0000002', 'estado_id' => Estado::idDe(Estado::INACTIVO)]);

        $this->get(route('admin.historias-clinicas.index'))->assertOk()
            ->assertSee(route('admin.consultas.create', $this->historia))
            ->assertDontSee(route('admin.consultas.create', $inactivo->historiaClinica));

        $this->actingAs(User::factory()->administrador()->create())->get(route('admin.historias-clinicas.index'))->assertOk()
            ->assertDontSee('Atender sin turno');
        $this->actingAs(User::factory()->conPermisos(['HISTORIA_CLINICA' => ['VER']])->create())->get(route('admin.historias-clinicas.index'))
            ->assertDontSee('Atender sin turno');
    });

    test('menú: "Atención sin turno" justo después de "Historias clínicas", con VER', function () {
        $this->get('/dashboard')->assertSeeInOrder(['data-grupo-menu="Clínica"', 'Historias clínicas', 'Atención sin turno'], false);

        $this->actingAs(User::factory()->conPermisos(['PACIENTES' => ['VER']])->create())->get('/dashboard')
            ->assertDontSee('Atención sin turno')->assertDontSee('data-grupo-menu="Clínica"', false);
    });
});

describe('rendimiento', function () {
    test('con 200 atenciones sin turno, las mismas consultas SQL en la página 1 que en la 2, y en la búsqueda', function () {
        $pacientes = collect(range(2, 50))->map(fn ($n) => Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => Paciente::nroFicha($n)]));
        foreach (range(1, 200) as $n) {
            Consulta::create(['historia_clinica_id' => $pacientes[$n % 49]->historiaClinica->id, 'profesional_id' => $n % 2 ? $this->profesional->id : $this->otroProfesional->id,
                'motivo_consulta' => "Urgencia {$n}"]);
        }

        $pagina1 = consultasSql(route('admin.atencion-sin-turno.index'));
        expect($pagina1)->toBeLessThan(30)
            ->toBe(consultasSql(route('admin.atencion-sin-turno.index', ['page' => 2])))
            ->and(consultasSql(route('admin.atencion-sin-turno.index', ['q' => 'Benítez']), ['X-Requested-With' => 'XMLHttpRequest']))->toBeLessThanOrEqual($pagina1);
    });

    test('el listado de historias con el botón: las mismas consultas SQL con 20 filas que con 10', function () {
        foreach (range(2, 30) as $n) {
            Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => Paciente::nroFicha($n)]);
        }

        $conVeinte = consultasSql(route('admin.historias-clinicas.index'));
        $conDiez = consultasSql(route('admin.historias-clinicas.index', ['page' => 2]));

        expect($conVeinte)->toBe($conDiez)->toBeLessThan(25);
    });
});
