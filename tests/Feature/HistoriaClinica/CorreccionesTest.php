<?php

/*
 * Correcciones de una consulta cerrada (docs/historia-clinica.md): adenda y desbloqueo. Lo más sensible del módulo:
 * quién puede cada cosa (incluido el que atendió y el Administrador), la lista blanca de campos, un solo uso y
 * vencimiento (reloj simulado), el alcance del permiso de escritura, la versión con el estado anterior, la adenda
 * inmutable, el contenido oculto en Auditoría sin permiso y el escapado. Datos inventados (escenario.php).
 */

use App\Exceptions\ConsultaCerrada;
use App\Exceptions\RegistroInmutable;
use App\Models\AdendaConsulta;
use App\Models\BloqueAnamnesis;
use App\Models\Consulta;
use App\Models\ConsultaVersion;
use App\Models\DesbloqueoConsulta;
use App\Models\Diagnostico;
use App\Models\Estado;
use App\Models\ExamenFisico;
use App\Models\Indicacion;
use App\Models\LogAuditoria;
use App\Models\User;
use App\Policies\ConsultaPolicy;
use App\Support\Auditoria;
use App\Support\Correcciones\VentanaDeCorreccion;
use App\Support\PerfilesPredefinidos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

const CORR_SUPERVISOR = ['HISTORIA_CLINICA' => ['VER'], 'CORRECCIONES_HC' => ['VER', 'CREAR', 'EDITAR'], 'AUDITORIA' => ['VER']];

beforeEach(function () {
    hcEscenario();
    $this->supervisor = User::factory()->conPermisos(CORR_SUPERVISOR)->conPersona(['apellidos' => 'Quintana', 'nombres' => 'Marta'])->create();
    $this->consulta = hcConsulta([
        'examen' => ['presion_arterial' => '120/80', 'peso' => '60', 'hallazgos' => 'Faringe congestiva.'],
        'con_indicaciones' => '1', 'indicaciones' => [['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => 'Reposo 48 horas']],
    ]);
    expect($this->consulta->finalizada())->toBeTrue();
});

afterEach(fn () => Carbon::setTestNow());

/** Un desbloqueo VIGENTE de la consulta, abierto por el supervisor (por la pantalla). */
function corrDesbloquear(?User $usuario = null, string $motivo = 'Se cargó mal el diagnóstico, error de tipeo.'): DesbloqueoConsulta
{
    test()->actingAs($usuario ?? test()->supervisor)->post(route('admin.correcciones.desbloqueo.store', test()->consulta), ['motivo' => $motivo]);

    return DesbloqueoConsulta::where('consulta_id', test()->consulta->id)->latest('id')->firstOrFail();
}

/** Lo que manda el formulario de corrección: las filas guardadas y SOLO los hallazgos del examen. */
function corrDatos(array $cambios = []): array
{
    $consulta = test()->consulta->fresh();
    $filas = hcFilasGuardadas($consulta);
    $filas['examen'] = ['hallazgos' => $consulta->examenFisico?->hallazgos];

    return [...$filas, ...$cambios];
}

/** Foto de lo que NO se puede corregir: signos vitales, triaje, estado, fechas, paciente, profesional y recetas. */
function corrFotoIntocable(): string
{
    $consulta = DB::table('consultas')->where('id', test()->consulta->id)->first();

    return json_encode([
        collect((array) $consulta)->only(['historia_clinica_id', 'turno_id', 'profesional_id', 'estado_id', 'fecha_hora', 'iniciada_en', 'finalizada_en', 'triaje_urgente', 'triaje_observacion'])->all(),
        DB::table('examenes_fisicos')->where('consulta_id', test()->consulta->id)->first(['presion_arterial', 'frecuencia_cardiaca', 'temperatura', 'peso', 'talla', 'saturacion_oxigeno', 'signos_usuario_id', 'signos_alertas']),
        DB::table('recetas')->where('consulta_id', test()->consulta->id)->get(),
    ]);
}

/** Lo corregible, tal como está. */
function corrFotoCorregible(): string
{
    return json_encode(\App\Support\Correcciones\Instantanea::de(test()->consulta->fresh()));
}

describe('adenda: quién puede agregarla', function () {
    test('el profesional de la consulta, quien tiene CREAR en CORRECCIONES_HC y el Administrador; nadie más', function () {
        $datos = ['motivo' => 'Aclaración del seguimiento.', 'texto' => 'La paciente volvió a llamar y se indicó control en 7 días.'];
        $rutaForm = route('admin.correcciones.adenda.create', $this->consulta);

        foreach ([$this->medico, $this->supervisor, User::factory()->administrador()->create()] as $usuario) {
            $this->actingAs($usuario)->get($rutaForm)->assertOk()->assertSee('novalidate', false);
        }
        // Otro profesional con CREAR sobre la historia: no es el de la consulta. Quien solo ve las correcciones, tampoco.
        $solo = User::factory()->conPermisos(['HISTORIA_CLINICA' => ['VER'], 'CORRECCIONES_HC' => ['VER', 'EDITAR']])->create();
        foreach ([$this->otroMedico, $solo, User::factory()->conPermisos(['HISTORIA_CLINICA' => ['VER']])->create()] as $usuario) {
            $this->actingAs($usuario)->get($rutaForm)->assertForbidden();
            $this->post(route('admin.correcciones.adenda.store', $this->consulta), $datos)->assertForbidden();
        }
        expect(AdendaConsulta::count())->toBe(0);

        $this->actingAs($this->medico)->post(route('admin.correcciones.adenda.store', $this->consulta), $datos)->assertRedirect(route('admin.consultas.show', $this->consulta));
        expect(AdendaConsulta::sole()->usuario_id)->toBe($this->medico->id);
    });

    test('solo a una consulta finalizada; el motivo de 10 a 300 y el texto de hasta 3000', function () {
        $enCurso = hcEnCurso(); // como el médico: el supervisor no atiende
        $this->actingAs($this->supervisor);
        $this->post(route('admin.correcciones.adenda.store', $enCurso), ['motivo' => 'Motivo válido', 'texto' => 'Texto'])->assertForbidden();

        $url = route('admin.correcciones.adenda.store', $this->consulta);
        foreach ([['motivo' => str_repeat('a', 9), 'texto' => 'x'], ['motivo' => str_repeat('a', 301), 'texto' => 'x'], ['motivo' => str_repeat('a', 10), 'texto' => ''],
            ['motivo' => str_repeat('a', 10), 'texto' => str_repeat('a', 3001)]] as $malos) {
            $this->post($url, $malos)->assertSessionHasErrors();
        }
        expect(AdendaConsulta::count())->toBe(0);
        $this->post($url, ['motivo' => str_repeat('a', 300), 'texto' => str_repeat('b', 3000)])->assertSessionHasNoErrors();
        expect(AdendaConsulta::count())->toBe(1);
    });

    test('la adenda no toca la consulta, se ve debajo de lo original (página y popup) y es inmutable', function () {
        $antes = corrFotoCorregible().corrFotoIntocable();
        $this->actingAs($this->supervisor)->post(route('admin.correcciones.adenda.store', $this->consulta), ['motivo' => 'Aclaración del seguimiento.', 'texto' => 'Se indicó control en 7 días.'])->assertSessionHasNoErrors();
        expect(corrFotoCorregible().corrFotoIntocable())->toBe($antes);

        $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSeeInOrder(['Motivo de consulta', 'Adendas', 'Quintana', 'Se indicó control en 7 días.'], false);
        $this->get(route('admin.consultas.detalle', $this->consulta), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('Se indicó control en 7 días.')->assertSee('Marta');

        $adenda = AdendaConsulta::sole();
        expect(fn () => $adenda->update(['texto' => 'Otra']))->toThrow(RegistroInmutable::class)
            ->and(fn () => $adenda->delete())->toThrow(RegistroInmutable::class)
            ->and(fn () => AdendaConsulta::where('id', $adenda->id)->update(['texto' => 'Otra']))->toBeCallable()
            ->and($adenda->fresh()->texto)->toBe('Se indicó control en 7 días.');
        // La auditoría lo registra como CREAR de adendas_consulta.
        expect(LogAuditoria::where('tabla_afectada', 'adendas_consulta')->where('accion', 'CREAR')->count())->toBe(1);
    });
});

describe('desbloqueo: quién puede abrirlo', function () {
    test('un supervisor y el Administrador sí; el que atendió, no (con su mensaje); sin EDITAR, no', function () {
        $atendio = hcDarPermisos($this->medico, ['CORRECCIONES_HC' => ['VER', 'CREAR', 'EDITAR']]);
        $motivo = 'Se cargó mal el diagnóstico, error de tipeo.';

        $this->actingAs($atendio)->get(route('admin.correcciones.desbloqueo.create', $this->consulta))->assertForbidden();
        $this->post(route('admin.correcciones.desbloqueo.store', $this->consulta), ['motivo' => $motivo])->assertForbidden();
        expect(Gate::forUser($atendio)->inspect('desbloquear', $this->consulta)->message())->toBe('Esta consulta la atendió usted: otro supervisor tiene que desbloquearla.')
            ->and(DesbloqueoConsulta::count())->toBe(0);

        $sinEditar = User::factory()->conPermisos(['HISTORIA_CLINICA' => ['VER'], 'CORRECCIONES_HC' => ['VER', 'CREAR']])->create();
        $this->actingAs($sinEditar)->post(route('admin.correcciones.desbloqueo.store', $this->consulta), ['motivo' => $motivo])->assertForbidden();
        $this->actingAs($this->otroMedico)->post(route('admin.correcciones.desbloqueo.store', $this->consulta), ['motivo' => $motivo])->assertForbidden();
        expect(DesbloqueoConsulta::count())->toBe(0);

        $this->actingAs(User::factory()->administrador()->create())->post(route('admin.correcciones.desbloqueo.store', $this->consulta), ['motivo' => $motivo])->assertRedirect();
        expect(DesbloqueoConsulta::sole()->estaVigente())->toBeTrue();
    });

    test('el motivo de 20 a 500, vigente 24 h, una sola ventana a la vez y cancelable', function () {
        $this->actingAs($this->supervisor);
        $url = route('admin.correcciones.desbloqueo.store', $this->consulta);
        $this->post($url, ['motivo' => str_repeat('a', 19)])->assertSessionHasErrors('motivo');
        $this->post($url, ['motivo' => str_repeat('a', 501)])->assertSessionHasErrors('motivo');
        expect(DesbloqueoConsulta::count())->toBe(0);

        $desbloqueo = corrDesbloquear($this->supervisor, str_repeat('a', 20));
        expect($desbloqueo->estaVigente())->toBeTrue()->and($desbloqueo->vence_en->equalTo(now()->addHours(24)))->toBeTrue();

        $this->post($url, ['motivo' => str_repeat('b', 20)])->assertSessionHas('error');
        expect(DesbloqueoConsulta::count())->toBe(1);

        $this->post(route('admin.correcciones.cancelar', $desbloqueo))->assertRedirect();
        expect($desbloqueo->fresh()->tieneEstado(Estado::CANCELADO))->toBeTrue();
        $this->get(route('admin.correcciones.corregir', $desbloqueo))->assertRedirect(route('admin.consultas.show', $this->consulta));
        corrDesbloquear($this->supervisor, str_repeat('c', 20)); // ya se puede abrir otra
        expect(DesbloqueoConsulta::count())->toBe(2);
    });

    test('los botones salen solo con permiso y la página no se guarda en el navegador', function () {
        $this->actingAs($this->medico);
        $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Agregar adenda')->assertDontSee('Desbloquear para corregir');
        $respuesta = $this->actingAs($this->supervisor)->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Agregar adenda')->assertSee('Desbloquear para corregir');
        expect($respuesta->headers->get('Cache-Control'))->toContain('no-store');
        $this->actingAs($this->otroMedico)->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertDontSee('Agregar adenda')->assertDontSee('Desbloquear para corregir');
    });
});

describe('corrección con el desbloqueo', function () {
    test('corrige motivo, anamnesis, hallazgos, diagnósticos e indicaciones; guarda la versión anterior; vuelve a cerrar', function () {
        $desbloqueo = corrDesbloquear();
        $antes = corrFotoCorregible();
        $intocable = corrFotoIntocable();
        $bloque = $this->consulta->bloquesAnamnesis()->orderBy('id')->first();
        $this->get(route('admin.correcciones.corregir', $desbloqueo))->assertOk()->assertSee('Guardar corrección');

        $datos = corrDatos([
            'motivo_consulta' => 'Motivo corregido.',
            'examen' => ['hallazgos' => 'Faringe normal.'],
        ]);
        $datos['anamnesis'][0]['contenido'] = 'Contenido corregido.';
        $datos['anamnesis'][1]['activo'] = '0'; // retirado
        $datos['anamnesis'][] = ['id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'Alergia nueva.'];
        $datos['diagnosticos'][0]['tipo'] = 'CONFIRMADO';
        $datos['con_indicaciones'] = '1';
        $datos['indicaciones'] = [['id' => (string) Indicacion::sole()->id, 'activo' => '1', 'tipo_indicacion_id' => '', 'descripcion' => 'Reposo 72 horas']];

        $this->post(route('admin.correcciones.corregir.guardar', $desbloqueo), $datos)->assertSessionHasNoErrors()->assertRedirect(route('admin.consultas.show', $this->consulta));

        $consulta = $this->consulta->fresh();
        expect($consulta->finalizada())->toBeTrue()->and($consulta->motivo_consulta)->toBe('Motivo corregido.')
            ->and($consulta->examenFisico->hallazgos)->toBe('Faringe normal.')
            ->and($bloque->fresh()->contenido)->toBe('Contenido corregido.')
            ->and($consulta->bloquesAnamnesis()->where('activo', false)->count())->toBe(1)
            ->and($consulta->bloquesAnamnesis()->count())->toBe(3)
            ->and(Diagnostico::sole()->tipo->value)->toBe('CONFIRMADO')
            ->and(Indicacion::sole()->descripcion)->toBe('Reposo 72 horas')
            ->and(corrFotoIntocable())->toBe($intocable)
            ->and($desbloqueo->fresh()->tieneEstado(Estado::REALIZADO))->toBeTrue()
            ->and($desbloqueo->fresh()->usado_en)->not->toBeNull();

        // La versión guarda el estado ANTERIOR, completo.
        $version = ConsultaVersion::sole();
        expect($version->numero)->toBe(1)->and($version->desbloqueo_id)->toBe($desbloqueo->id)->and($version->usuario_id)->toBe($this->supervisor->id)
            ->and(json_encode($version->snapshot))->toBe($antes)
            ->and($version->snapshot['hallazgos'])->toBe('Faringe congestiva.')->and($version->snapshot['motivo_consulta'])->toBe('Dolor de garganta y fiebre desde ayer.');
        expect(fn () => $version->update(['numero' => 9]))->toThrow(RegistroInmutable::class)->and(fn () => $version->delete())->toThrow(RegistroInmutable::class);

        // Auditoría: CREAR de desbloqueo y de versión; EDITAR de la consulta con el detalle y el antes y después.
        expect(LogAuditoria::where('tabla_afectada', 'desbloqueos_consulta')->where('accion', 'CREAR')->count())->toBe(1)
            ->and(LogAuditoria::where('tabla_afectada', 'consulta_versiones')->where('accion', 'CREAR')->count())->toBe(1);
        $evento = LogAuditoria::where('tabla_afectada', 'consultas')->where('detalle', "Corrección autorizada (desbloqueo #{$desbloqueo->id})")->sole();
        expect($evento->accion->value)->toBe('EDITAR')->and($evento->valor_anterior['motivo_consulta'])->toBe('Dolor de garganta y fiebre desde ayer.')
            ->and($evento->valor_nuevo['motivo_consulta'])->toBe('Motivo corregido.');

        // La lectura: "Corregida 1 vez" y, con VER sobre las correcciones, el historial con el antes.
        $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Corregida 1 vez')->assertSee('Historial de correcciones')
            ->assertSee('Faringe congestiva.')->assertSee($desbloqueo->motivo);
        $this->actingAs($this->medico)->get(route('admin.consultas.show', $this->consulta))->assertSee('Corregida 1 vez')->assertDontSee('Historial de correcciones');
    });

    test('lista blanca: signos vitales, triaje, receta, paciente, profesional, fechas, estado y autoría se rechazan sin cambios', function () {
        $desbloqueo = corrDesbloquear();
        $url = route('admin.correcciones.corregir.guardar', $desbloqueo);
        $intocable = corrFotoIntocable();
        $corregible = corrFotoCorregible();

        $prohibidos = [
            'signos vitales' => ['examen' => ['hallazgos' => 'x', 'temperatura' => '40', 'peso' => '99']],
            'presión' => ['examen' => ['presion_arterial' => '200/120']],
            'triaje' => ['con_triaje' => '1', 'triaje_urgente' => '1', 'triaje_observacion' => 'x'],
            'receta' => ['receta_id' => 1, 'recetas' => [['medicamento' => 'x']]],
            'paciente' => ['paciente_id' => $this->otraPaciente->id ?? 999],
            'profesional' => ['profesional_id' => $this->otroProfesional->id],
            'historia' => ['historia_clinica_id' => 999],
            'fecha' => ['fecha_hora' => '2020-01-01 00:00:00', 'finalizada_en' => '2020-01-01 00:00:00'],
            'estado' => ['estado_id' => Estado::idDe(Estado::EN_CURSO)],
            'autoría' => ['usuario_id' => $this->supervisor->id, 'signos_usuario_id' => $this->supervisor->id],
        ];
        foreach ($prohibidos as $nombre => $extra) {
            $this->post($url, corrDatos(['motivo_consulta' => 'Cambiado '.$nombre, ...$extra]))->assertSessionHasErrors('correccion');
            expect(corrFotoIntocable())->toBe($intocable, $nombre)->and(corrFotoCorregible())->toBe($corregible, $nombre)
                ->and($desbloqueo->fresh()->estaVigente())->toBeTrue()->and(ConsultaVersion::count())->toBe(0);
        }

        // Defensa en profundidad, en el modelo: aun dentro de la ventana, los campos no permitidos dan 409.
        foreach ([
            fn () => ExamenFisico::where('consulta_id', $this->consulta->id)->firstOrFail()->update(['temperatura' => 40]),
            fn () => ExamenFisico::where('consulta_id', $this->consulta->id)->firstOrFail()->update(['signos_usuario_id' => $this->supervisor->id]),
            fn () => $this->consulta->fresh()->update(['triaje_observacion' => 'x']),
            fn () => $this->consulta->fresh()->forceFill(['estado_id' => Estado::idDe(Estado::EN_CURSO)])->save(),
            fn () => $this->consulta->fresh()->forceFill(['profesional_id' => $this->otroProfesional->id])->save(),
            fn () => $this->consulta->fresh()->forceFill(['historia_clinica_id' => 999])->save(),
            fn () => $this->consulta->recetas()->create(['numero' => 'X']),
        ] as $intento) {
            expect(fn () => VentanaDeCorreccion::dentro($desbloqueo, $intento))->toThrow(Exception::class);
        }
        expect(corrFotoIntocable())->toBe($intocable)->and(VentanaDeCorreccion::activa())->toBeFalse();
    });

    test('sin cambios no se consume; un solo uso: el segundo guardado y cualquier escritura después dan error', function () {
        $desbloqueo = corrDesbloquear();
        $url = route('admin.correcciones.corregir.guardar', $desbloqueo);

        $this->post($url, corrDatos())->assertSessionHas('error', \App\Support\Correcciones\CorregirConsulta::SIN_CAMBIOS);
        expect($desbloqueo->fresh()->estaVigente())->toBeTrue()->and(ConsultaVersion::count())->toBe(0);

        $this->post($url, corrDatos(['motivo_consulta' => 'Primera corrección.']))->assertSessionHasNoErrors();
        $despues = corrFotoCorregible();
        $this->post($url, corrDatos(['motivo_consulta' => 'Segunda corrección.']))->assertSessionHas('error', \App\Support\Correcciones\CorregirConsulta::NO_VIGENTE);
        expect(corrFotoCorregible())->toBe($despues)->and(ConsultaVersion::count())->toBe(1);

        // Usada: ya no hay permiso de escritura en ninguna parte (409).
        expect(fn () => $this->consulta->fresh()->update(['motivo_consulta' => 'x']))->toThrow(ConsultaCerrada::class)
            ->and(fn () => BloqueAnamnesis::firstOrFail()->update(['contenido' => 'x']))->toThrow(ConsultaCerrada::class)
            ->and(fn () => VentanaDeCorreccion::dentro($desbloqueo, fn () => $this->consulta->fresh()->update(['motivo_consulta' => 'x'])))->not->toThrow(Exception::class);
    });

    test('vence a las 24 horas (reloj simulado) y queda VENCIDO', function () {
        $desbloqueo = corrDesbloquear();
        $url = route('admin.correcciones.corregir.guardar', $desbloqueo);

        Carbon::setTestNow(now()->addHours(23)->addMinutes(59));
        $this->get(route('admin.correcciones.corregir', $desbloqueo))->assertOk();

        Carbon::setTestNow(now()->addMinutes(2));
        $antes = corrFotoCorregible();
        $this->post($url, corrDatos(['motivo_consulta' => 'Tarde.']))->assertSessionHas('error', \App\Support\Correcciones\CorregirConsulta::NO_VIGENTE);
        expect($desbloqueo->fresh()->tieneEstado(Estado::VENCIDO))->toBeTrue()->and(corrFotoCorregible())->toBe($antes)->and(ConsultaVersion::count())->toBe(0);
        $this->get(route('admin.correcciones.corregir', $desbloqueo))->assertRedirect(route('admin.consultas.show', $this->consulta));

        // Vencido, se puede abrir uno nuevo.
        corrDesbloquear($this->supervisor, str_repeat('n', 20));
        expect(DesbloqueoConsulta::count())->toBe(2)->and(DesbloqueoConsulta::where('estado_id', Estado::idDe(Estado::VIGENTE))->count())->toBe(1);
    });

    test('una ventana VIGENTE con el plazo vencido no bloquea un desbloqueo nuevo: pasa a VENCIDO y se crea el otro', function () {
        $vieja = corrDesbloquear();
        expect($vieja->estaVigente())->toBeTrue();

        Carbon::setTestNow(now()->addHours(25)); // nadie la tocó: sigue VIGENTE en la base, con el plazo vencido
        expect($vieja->fresh()->tieneEstado(Estado::VIGENTE))->toBeTrue();

        $this->actingAs($this->supervisor)->post(route('admin.correcciones.desbloqueo.store', $this->consulta), ['motivo' => 'Nuevo desbloqueo, el anterior venció.'])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');

        $nuevo = DesbloqueoConsulta::where('consulta_id', $this->consulta->id)->latest('id')->firstOrFail();
        expect($vieja->fresh()->tieneEstado(Estado::VENCIDO))->toBeTrue()
            ->and($nuevo->id)->not->toBe($vieja->id)->and($nuevo->estaVigente())->toBeTrue()
            ->and(DesbloqueoConsulta::where('estado_id', Estado::idDe(Estado::VIGENTE))->count())->toBe(1);
    });

    test('alcance: la ventana vale solo para SU consulta; el que atendió no la usa; fuera de la ventana todo da 409', function () {
        $otra = hcConsulta(['motivo_consulta' => 'Otra consulta.']);
        expect($otra->id)->not->toBe($this->consulta->id);
        $desbloqueo = corrDesbloquear();

        // Dentro de la ventana de ESTA consulta, escribir en la OTRA (o en su anamnesis/diagnósticos) da 409.
        foreach ([
            fn () => $otra->fresh()->update(['motivo_consulta' => 'x']),
            fn () => $otra->bloquesAnamnesis()->firstOrFail()->update(['contenido' => 'x']),
            fn () => $otra->diagnosticos()->firstOrFail()->update(['activo' => false]),
        ] as $intento) {
            expect(fn () => VentanaDeCorreccion::dentro($desbloqueo, $intento))->toThrow(ConsultaCerrada::class);
        }
        // Y de ESTA consulta, una tabla fuera de la lista (recetas).
        expect(fn () => VentanaDeCorreccion::dentro($desbloqueo, fn () => $this->consulta->recetas()->create(['numero' => 'X'])))->toThrow(ConsultaCerrada::class);

        // Sin ventana abierta, nada se escribe.
        expect(fn () => $this->consulta->fresh()->update(['motivo_consulta' => 'x']))->toThrow(ConsultaCerrada::class);

        // El que atendió no usa el desbloqueo aunque tenga el permiso (no se salta la regla ni con el enlace).
        $atendio = hcDarPermisos($this->medico, ['CORRECCIONES_HC' => ['VER', 'CREAR', 'EDITAR']]);
        $this->actingAs($atendio)->get(route('admin.correcciones.corregir', $desbloqueo))->assertForbidden();
        $this->post(route('admin.correcciones.corregir.guardar', $desbloqueo), corrDatos(['motivo_consulta' => 'Propio.']))->assertForbidden();
        $this->post(route('admin.correcciones.cancelar', $desbloqueo))->assertForbidden();
        expect($desbloqueo->fresh()->estaVigente())->toBeTrue()->and($this->consulta->fresh()->motivo_consulta)->not->toBe('Propio.');
    });
});

describe('auditoría, módulo y escapado', function () {
    test('las tablas nuevas son contenido clínico: ocultas en Auditoría sin VER sobre la historia clínica', function () {
        $this->actingAs($this->supervisor)->post(route('admin.correcciones.adenda.store', $this->consulta), ['motivo' => 'Aclaración del seguimiento.', 'texto' => 'Texto reservado de la adenda.']);
        $desbloqueo = corrDesbloquear();
        $this->post(route('admin.correcciones.corregir.guardar', $desbloqueo), corrDatos(['motivo_consulta' => 'Motivo reservado corregido.']))->assertSessionHasNoErrors();

        expect(Auditoria::TABLAS_CLINICAS)->toContain('adendas_consulta', 'desbloqueos_consulta', 'consulta_versiones');
        $this->actingAs(User::factory()->conPermisos(['AUDITORIA' => ['VER'], 'RECETAS' => ['VER'], 'CORRECCIONES_HC' => ['VER']])->create());
        foreach (['adendas_consulta', 'desbloqueos_consulta', 'consulta_versiones'] as $tabla) {
            $evento = LogAuditoria::where('tabla_afectada', $tabla)->where('accion', 'CREAR')->firstOrFail();
            $this->get(route('admin.auditoria.show', $evento))->assertOk()->assertSee('se requiere permiso de lectura sobre Historia Clínica')
                ->assertDontSee('Texto reservado')->assertDontSee('Motivo reservado')->assertDontSee($desbloqueo->motivo)->assertDontSee('Dolor de garganta');
        }
        // Con VER sobre la historia clínica sí se ve.
        $this->actingAs($this->supervisor);
        $evento = LogAuditoria::where('tabla_afectada', 'adendas_consulta')->firstOrFail();
        $this->get(route('admin.auditoria.show', $evento))->assertOk()->assertSee('Texto reservado de la adenda.');
    });

    test('el módulo es sensible y solo lo recibe Supervisión médica (y el Administrador)', function () {
        expect(\App\Models\ModuloSistema::where('codigo', 'CORRECCIONES_HC')->value('es_sensible'))->toBeTrue();
        $conModulo = collect(PerfilesPredefinidos::MATRIZ)->filter(fn (array $matriz) => isset($matriz['CORRECCIONES_HC']))->keys()->all();
        expect($conModulo)->toBe([PerfilesPredefinidos::SUPERVISION_MEDICA])->and(PerfilesPredefinidos::MATRIZ[PerfilesPredefinidos::SUPERVISION_MEDICA]['CORRECCIONES_HC'])->toBe(['VER', 'CREAR', 'EDITAR']);
    });

    test('HTML y JS en el motivo y el texto de la adenda, en el motivo del desbloqueo y en lo corregido: escapados', function () {
        $img = '<img src=x onerror=alert(1)>';
        $script = '"><script>alert(2)</script>';
        $this->actingAs($this->supervisor)->post(route('admin.correcciones.adenda.store', $this->consulta), ['motivo' => "Motivo {$img}", 'texto' => "Texto {$script}"])->assertSessionHasNoErrors();
        $desbloqueo = corrDesbloquear(motivo: "Desbloqueo {$script} con motivo largo");
        $this->get(route('admin.correcciones.corregir', $desbloqueo))->assertOk()->assertDontSee($script, false)->assertSee('Desbloqueo &quot;&gt;&lt;script&gt;', false);
        $this->post(route('admin.correcciones.corregir.guardar', $desbloqueo), corrDatos(['motivo_consulta' => "Corregido {$img}"]))->assertSessionHasNoErrors();

        $sinCrudo = fn ($respuesta) => $respuesta->assertDontSee($img, false)->assertDontSee('<script>alert(2)</script>', false);
        $sinCrudo($this->get(route('admin.consultas.show', $this->consulta))->assertOk())
            ->assertSee('Motivo: Motivo &lt;img src=x onerror=alert(1)&gt;', false)->assertSee('Texto &quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;', false);
        $sinCrudo($this->get(route('admin.consultas.detalle', $this->consulta), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk())->assertSee('Texto &quot;&gt;&lt;script&gt;', false);
        $sinCrudo($this->get(route('admin.correcciones.adenda.create', $this->consulta))->assertOk());
    });
});
