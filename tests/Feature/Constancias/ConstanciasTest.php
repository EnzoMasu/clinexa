<?php

/*
 * Constancias de retiro y de estado del turno (docs/constancias.md): quién emite cada una, inmutabilidad y huella,
 * anulación con motivo, auditoría y que la de Recepción no tenga nada clínico. "Ahora": martes 06/10/2026 09:00.
 * Datos inventados. Funciones con el prefijo "cn".
 */

use App\Enums\TipoTurno;
use App\Exceptions\ConstanciaInmutable;
use App\Models\Constancia;
use App\Models\ConstanciaEstado;
use App\Models\ConstanciaRetiro;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\Turno;
use App\Models\User;
use App\Support\Atencion\GuardarNotaSalida;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const CN_RETIRO = ['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'CONSTANCIAS_RETIRO' => ['VER', 'CREAR', 'EDITAR']];
const CN_ESTADO = ['TURNOS' => ['VER'], 'CONSTANCIAS_ESTADO' => ['VER', 'CREAR', 'EDITAR']];

beforeEach(function () {
    epEscenario();
    $this->recepcionista = User::factory()->conPermisos(CN_RETIRO)->conPersona(['apellidos' => 'Acosta', 'nombres' => 'Marta'])->create();
    // El médico del escenario (Dra. Benítez, profesional del turno) y el Dr. Insfrán reciben el módulo de estado.
    $this->medicoDelTurno = hcDarPermisos($this->medico, CN_ESTADO);
    $this->otroMedicoConstancias = hcDarPermisos($this->otroMedico, CN_ESTADO);
    $this->supervisor = User::factory()->conPermisos([...CN_ESTADO, 'CORRECCIONES_HC' => ['VER']])->conPersona(['apellidos' => 'Rojas', 'nombres' => 'Elena'])->create();
});

afterEach(fn () => Carbon::setTestNow());

/** Retiro (o rechazo) del turno registrado por Recepción. */
function cnRetirado(array $cambios = [], string $accion = 'retiro'): Turno
{
    $turno = epTurno(cambios: $cambios);
    test()->actingAs(test()->recepcionista)->patch(route('admin.turnos.estado', $turno), ['accion' => $accion])->assertSessionHas('status');

    return $turno->fresh();
}

function cnAtendido(array $cambios = []): Turno
{
    return epTurno(cambios: ['estado_id' => Estado::idDe(Estado::ATENDIDO), ...$cambios]);
}

function cnEmitirRetiro(Turno $turno, ?User $usuario = null)
{
    return test()->actingAs($usuario ?? test()->recepcionista)->post(route('admin.constancias-retiro.emitir', $turno));
}

function cnEmitirEstado(Turno $turno, ?User $usuario = null)
{
    return test()->actingAs($usuario ?? test()->medicoDelTurno)->post(route('admin.constancias-estado.emitir', $turno));
}

describe('quién puede emitir', function () {
    test('retiro: solo quien tiene CREAR sobre CONSTANCIAS_RETIRO (Recepción), y solo de un turno con retiro o rechazo', function () {
        $turno = cnRetirado();

        // Sin permiso del módulo: el médico del turno, el supervisor y una recepción sin constancias.
        $sinConstancias = User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR', 'EDITAR']])->conPersona(['apellidos' => 'Vega', 'nombres' => 'Ana'])->create();
        foreach ([$this->medicoDelTurno, $this->supervisor, $sinConstancias] as $usuario) {
            cnEmitirRetiro($turno, $usuario)->assertForbidden();
        }
        expect(ConstanciaRetiro::count())->toBe(0);

        $respuesta = cnEmitirRetiro($turno)->assertSessionHas('status');
        $constancia = ConstanciaRetiro::sole();
        $respuesta->assertRedirect(route('admin.constancias-retiro.imprimir', $constancia));
        expect($constancia->numero)->toBe('CR-0000001')->and($constancia->usuario_id)->toBe($this->recepcionista->id)
            ->and($constancia->salida_id)->toBe($turno->salida->id)->and($constancia->estaEmitida())->toBeTrue();

        // Un turno sin salida registrada (pendiente o atendido) no admite constancia de retiro.
        foreach ([epTurno(cambios: ['paciente_id' => $this->otraPaciente->id]), cnAtendido(['paciente_id' => $this->otraPaciente->id, 'profesional_id' => $this->otroProfesional->id])] as $sinSalida) {
            cnEmitirRetiro($sinSalida)->assertForbidden();
        }
        expect(ConstanciaRetiro::count())->toBe(1);
    });

    test('estado: el profesional del turno y Supervisión médica; no otro médico ni Recepción', function () {
        $turno = cnAtendido();

        foreach ([$this->otroMedicoConstancias, $this->recepcionista] as $usuario) {
            cnEmitirEstado($turno, $usuario)->assertForbidden();
        }
        expect(ConstanciaEstado::count())->toBe(0);

        cnEmitirEstado($turno)->assertSessionHas('status');
        expect(ConstanciaEstado::sole()->numero)->toBe('CE-0000001');

        // Supervisión médica emite de cualquier turno; quien no tiene CREAR sobre el módulo, de ninguno.
        $otro = cnAtendido(['paciente_id' => $this->otraPaciente->id, 'profesional_id' => $this->otroProfesional->id]);
        cnEmitirEstado($otro, $this->supervisor)->assertSessionHas('status');
        $soloVer = User::factory()->conPermisos(['TURNOS' => ['VER'], 'CONSTANCIAS_ESTADO' => ['VER'], 'CORRECCIONES_HC' => ['VER']])->conPersona(['apellidos' => 'Díaz', 'nombres' => 'Rita'])->create();
        cnEmitirEstado($otro, $soloVer)->assertForbidden();
        expect(ConstanciaEstado::count())->toBe(2);
    });

    test('estado: solo de turnos con un hecho (atendido, ausente, retirado); no pendiente, confirmado ni cancelado', function () {
        foreach ([Estado::PENDIENTE, Estado::CONFIRMADO, Estado::CANCELADO, Estado::EN_CONSULTA] as $i => $estado) {
            $turno = epTurno(cambios: ['paciente_id' => $this->otraPaciente->id, 'estado_id' => Estado::idDe($estado), 'fecha' => '2026-10-0'.(1 + $i)]);
            cnEmitirEstado($turno)->assertForbidden();
        }
        expect(ConstanciaEstado::count())->toBe(0);

        foreach ([Estado::AUSENTE, Estado::ATENDIDO] as $i => $estado) {
            cnEmitirEstado(epTurno(cambios: ['estado_id' => Estado::idDe($estado), 'fecha' => '2026-09-0'.(1 + $i)]))->assertSessionHas('status');
        }
        cnEmitirEstado(cnRetirado(['fecha' => '2026-09-10'], 'rechazo'))->assertSessionHas('status');
        expect(ConstanciaEstado::count())->toBe(3);
    });

    test('ver y anular exigen su permiso: una constancia de otro médico no la lee ni la anula quien no es del turno', function () {
        $turno = cnAtendido();
        cnEmitirEstado($turno);
        $constancia = ConstanciaEstado::sole();

        $this->actingAs($this->otroMedicoConstancias)->get(route('admin.constancias-estado.imprimir', $constancia))->assertForbidden();
        $this->actingAs($this->otroMedicoConstancias)->post(route('admin.constancias-estado.anular', $constancia), ['motivo' => 'Motivo cualquiera'])->assertForbidden();
        $this->actingAs($this->recepcionista)->get(route('admin.constancias-estado.imprimir', $constancia))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('admin.constancias-estado.imprimir', $constancia))->assertOk();
        expect($constancia->fresh()->estaEmitida())->toBeTrue();
    });
});

describe('inmutabilidad y huella', function () {
    test('la constancia emitida no se modifica ni se borra; su huella es el sha256 del snapshot', function () {
        $turno = cnRetirado();
        cnEmitirRetiro($turno);
        $constancia = ConstanciaRetiro::sole();

        expect($constancia->huella)->toMatch('/^[0-9a-f]{64}$/')
            ->and($constancia->huella)->toBe(Constancia::calcularHuella($constancia->snapshot))
            ->and($constancia->huellaValida())->toBeTrue()
            ->and($constancia->snapshot['numero'])->toBe($constancia->numero);

        // Ningún cambio de contenido, de número, de huella ni de estado distinto de anular; tampoco borrar.
        expect(fn () => $constancia->update(['snapshot' => [...$constancia->snapshot, 'hecho' => 'Otro texto']]))->toThrow(ConstanciaInmutable::class)
            ->and(fn () => $constancia->update(['huella' => str_repeat('a', 64)]))->toThrow(ConstanciaInmutable::class)
            ->and(fn () => $constancia->update(['numero' => 'CR-0000099']))->toThrow(ConstanciaInmutable::class)
            ->and(fn () => $constancia->update(['usuario_id' => $this->medico->id]))->toThrow(ConstanciaInmutable::class)
            ->and(fn () => $constancia->update(['motivo_anulacion' => 'Solo el motivo']))->toThrow(ConstanciaInmutable::class)
            ->and(fn () => $constancia->update(['estado_id' => Estado::idDe(Estado::EMITIDO)]))->toThrow(ConstanciaInmutable::class)
            ->and(fn () => $constancia->delete())->toThrow(ConstanciaInmutable::class);
        expect(ConstanciaRetiro::count())->toBe(1)->and($constancia->fresh()->huellaValida())->toBeTrue();
    });

    test('cambiar los datos del paciente después no cambia la hoja: sale del snapshot, idéntica', function () {
        $turno = cnRetirado();
        cnEmitirRetiro($turno);
        $constancia = ConstanciaRetiro::sole();
        $antes = $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', $constancia))->assertOk()->getContent();
        expect($antes)->toContain('Carmen Duarte')->toContain($constancia->huella);

        $this->paciente->persona->forceFill(['nombres' => 'Otra', 'apellidos' => 'Persona'])->save();

        $despues = $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', $constancia))->assertOk()->getContent();
        expect($despues)->toContain('Carmen Duarte')->not->toContain('Otra Persona');
    });

    test('si el contenido guardado no coincide con la huella, la hoja avisa y no abre la impresión', function () {
        $turno = cnRetirado();
        cnEmitirRetiro($turno);
        $constancia = ConstanciaRetiro::sole();
        $adulterado = [...$constancia->snapshot, 'hecho' => 'Texto adulterado en la base.'];
        DB::table('constancias_retiro')->where('id', $constancia->id)->update(['snapshot' => json_encode($adulterado)]);

        expect(ConstanciaRetiro::sole()->huellaValida())->toBeFalse();
        $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', $constancia))
            ->assertOk()->assertSee('La huella de esta constancia no coincide con su contenido.');
    });

    test('emitir dos veces el mismo turno no duplica: devuelve la emitida; los números son correlativos por tipo', function () {
        $turno = cnRetirado();
        cnEmitirRetiro($turno)->assertSessionHas('status');
        cnEmitirRetiro($turno)->assertSessionHas('aviso')->assertSessionMissing('status');
        expect(ConstanciaRetiro::count())->toBe(1);

        $otro = cnRetirado(['paciente_id' => $this->otraPaciente->id, 'fecha' => '2026-10-05'], 'rechazo');
        cnEmitirRetiro($otro);
        cnEmitirEstado(cnAtendido(['fecha' => '2026-10-02']));
        expect(ConstanciaRetiro::orderBy('id')->pluck('numero')->all())->toBe(['CR-0000001', 'CR-0000002'])
            ->and(ConstanciaEstado::pluck('numero')->all())->toBe(['CE-0000001']);
    });
});

describe('anulación', function () {
    test('exige motivo; anula sin tocar el documento ni su huella; queda en Auditoría; luego se puede emitir otra', function () {
        $turno = cnRetirado();
        cnEmitirRetiro($turno);
        $constancia = ConstanciaRetiro::sole();
        $huella = $constancia->huella;
        $snapshot = $constancia->snapshot;

        foreach (['', '   ', 'abc'] as $malo) {
            $this->actingAs($this->recepcionista)->post(route('admin.constancias-retiro.anular', $constancia), ['motivo' => $malo])->assertSessionHasErrors('motivo');
        }
        expect($constancia->fresh()->estaEmitida())->toBeTrue();

        // Sin EDITAR sobre el módulo no se anula.
        $soloCrear = User::factory()->conPermisos(['TURNOS' => ['VER'], 'CONSTANCIAS_RETIRO' => ['VER', 'CREAR']])->conPersona(['apellidos' => 'Paz', 'nombres' => 'Luz'])->create();
        $this->actingAs($soloCrear)->post(route('admin.constancias-retiro.anular', $constancia), ['motivo' => 'Error de datos'])->assertForbidden();

        $this->actingAs($this->recepcionista)->post(route('admin.constancias-retiro.anular', $constancia), ['motivo' => 'Error de datos'])
            ->assertRedirect(route('admin.constancias-retiro.imprimir', $constancia))->assertSessionHas('status');
        $constancia = $constancia->fresh();
        expect($constancia->estaAnulada())->toBeTrue()->and($constancia->motivo_anulacion)->toBe('Error de datos')->and($constancia->anulada_en)->not->toBeNull()
            ->and($constancia->huella)->toBe($huella)->and($constancia->snapshot)->toBe($snapshot)->and($constancia->huellaValida())->toBeTrue();

        // La anulada se ve (con su motivo y marcada ANULADA), no se anula otra vez ni vuelve a emitida.
        $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', $constancia))->assertOk()->assertSee('ANULADA')->assertSee('Error de datos');
        $this->actingAs($this->recepcionista)->post(route('admin.constancias-retiro.anular', $constancia), ['motivo' => 'Otra vez más'])->assertForbidden();
        expect(fn () => $constancia->update(['estado_id' => Estado::idDe(Estado::EMITIDO)]))->toThrow(ConstanciaInmutable::class);

        // La auditoría tiene la emisión (CREAR) y la anulación (ANULAR con el motivo), sin el snapshot.
        $eventos = LogAuditoria::where('tabla_afectada', 'constancias_retiro')->where('accion', '!=', 'VER')->orderBy('id')->get();
        expect($eventos->map(fn ($e) => $e->accion->value)->all())->toBe(['CREAR', 'ANULAR'])
            ->and($eventos->last()->detalle)->toBe('Motivo: Error de datos')
            ->and($eventos->first()->valor_nuevo['huella'])->toBe($huella)
            ->and(json_encode($eventos->pluck('valor_nuevo')))->not->toContain('snapshot');

        // Nada se borró y ahora se puede emitir una nueva, con otro número.
        cnEmitirRetiro($turno)->assertSessionHas('status');
        expect(ConstanciaRetiro::orderBy('id')->pluck('numero')->all())->toBe(['CR-0000001', 'CR-0000002']);
    });

    test('la de estado la anula el profesional del turno o Supervisión médica, con motivo', function () {
        cnEmitirEstado(cnAtendido());
        $constancia = ConstanciaEstado::sole();

        $this->actingAs($this->medicoDelTurno)->post(route('admin.constancias-estado.anular', $constancia), ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->actingAs($this->medicoDelTurno)->post(route('admin.constancias-estado.anular', $constancia), ['motivo' => 'Se emitió por error'])->assertSessionHas('status');

        expect($constancia->fresh()->estaAnulada())->toBeTrue()->and($constancia->fresh()->motivo_anulacion)->toBe('Se emitió por error');
        expect(LogAuditoria::where('tabla_afectada', 'constancias_estado')->pluck('accion')->map->value->all())->toContain('CREAR', 'ANULAR');
    });
});

describe('contenido', function () {
    test('la constancia de retiro no tiene nada clínico: ni urgencia, ni observación, ni nota, ni signos, ni cargo', function () {
        $turno = epTurno(TipoTurno::URGENCIA);
        epTriaje($turno, urgente: true, observacion: 'Dolor torácico intenso irradiado.', alterados: true, terminado: true);
        $this->actingAs($this->recepcionista)->patch(route('admin.turnos.estado', $turno), ['accion' => 'retiro'])->assertSessionHas('status');
        $salida = $turno->fresh()->salida;
        GuardarNotaSalida::ejecutar($this->medicoDelTurno, $salida, 'Nota clínica reservada: sospecha de cuadro grave.');
        expect($salida->fresh()->cargo_pendiente)->toBeTrue();

        cnEmitirRetiro($turno)->assertSessionHas('status');
        $constancia = ConstanciaRetiro::sole();

        // Lista cerrada de datos: nada más que esto.
        expect(array_keys($constancia->snapshot))->toEqualCanonicalizing(['tipo', 'titulo', 'clinica', 'fecha', 'paciente', 'hecho', 'turno', 'pie', 'emitida_por', 'borrador', 'marca_borrador', 'numero'])
            ->and(array_keys($constancia->snapshot['paciente']))->toEqualCanonicalizing(['nombre', 'documento']);

        $hoja = $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', $constancia))->assertOk()->getContent();
        $todo = mb_strtolower(json_encode($constancia->getAttributes(), JSON_UNESCAPED_UNICODE).$hoja);
        foreach (['dolor torácico', 'observación', 'nota clínica', 'sospecha', 'urgencia', 'urgente', 'triaje', 'signo', '39.5', 'temperatura', 'cargo', 'entre pacientes', 'diagnóstico', 'alterado'] as $clinico) {
            expect($todo)->not->toContain($clinico);
        }
        // Y sí tiene el hecho, el paciente y la clínica.
        expect($hoja)->toContain('Carmen Duarte')->toContain('se retiró de Plenitud Mujer el 06/10/2026 a las 09:00')->toContain('Turno del 06/10/2026.');
    });

    test('el rechazo dice que rechazó la atención; la de estado no lleva diagnóstico ni datos clínicos', function () {
        $rechazo = cnRetirado(accion: 'rechazo');
        cnEmitirRetiro($rechazo);
        expect(ConstanciaRetiro::sole()->snapshot['hecho'])->toContain('rechazó la atención ofrecida en Plenitud Mujer el 06/10/2026 a las 09:00');

        $atendido = cnAtendido(['paciente_id' => $this->otraPaciente->id, 'fecha' => '2026-10-05']);
        cnEmitirEstado($atendido);
        $estado = ConstanciaEstado::sole();

        expect(array_keys($estado->snapshot))->toEqualCanonicalizing(['tipo', 'titulo', 'clinica', 'fecha', 'paciente', 'hecho', 'turno', 'pie', 'emitida_por', 'borrador', 'marca_borrador', 'profesional', 'numero'])
            ->and(array_keys($estado->snapshot['profesional']))->toEqualCanonicalizing(['nombre', 'matricula'])
            ->and($estado->snapshot['hecho'])->toContain('Lidia Gómez')->toContain('su turno con Rosa Benítez figura como atendido');
    });

    test('toda constancia nueva sale marcada "BORRADOR SUJETO A REVISIÓN" mientras config constancias.borrador sea true', function () {
        cnEmitirRetiro(cnRetirado());
        $constancia = ConstanciaRetiro::sole();
        expect($constancia->snapshot['borrador'])->toBeTrue();
        $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', $constancia))->assertOk()->assertSee('BORRADOR SUJETO A REVISIÓN');

        // Aprobado el texto: las nuevas salen sin marca, y la ya emitida conserva la suya (está en su snapshot y su huella).
        config(['constancias.borrador' => false]);
        $otro = cnRetirado(['paciente_id' => $this->otraPaciente->id, 'fecha' => '2026-10-05']);
        cnEmitirRetiro($otro);
        $nueva = ConstanciaRetiro::orderByDesc('id')->first();
        expect($nueva->snapshot['borrador'])->toBeFalse();
        $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', $nueva))->assertOk()->assertDontSee('BORRADOR SUJETO A REVISIÓN');
        $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', $constancia))->assertOk()->assertSee('BORRADOR SUJETO A REVISIÓN');
    });

    test('los textos se reemplazan en config/constancias.php y quedan en el snapshot de la constancia emitida', function () {
        config(['constancias.retiro.hechos.RETIRO' => 'TEXTO NUEVO :paciente :fecha :hora']);
        cnEmitirRetiro(cnRetirado());

        expect(ConstanciaRetiro::sole()->snapshot['hecho'])->toBe('TEXTO NUEVO Carmen Duarte 06/10/2026 09:00');
        config(['constancias.retiro.hechos.RETIRO' => 'OTRO TEXTO']);
        $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', ConstanciaRetiro::sole()))->assertSee('TEXTO NUEVO Carmen Duarte');
    });

    test('el nombre del paciente con HTML sale escapado en la hoja', function () {
        $this->paciente->persona->forceFill(['nombres' => '<script>alert(1)</script>'])->save();
        cnEmitirRetiro(cnRetirado());

        $hoja = $this->actingAs($this->recepcionista)->get(route('admin.constancias-retiro.imprimir', ConstanciaRetiro::sole()))->assertOk()->getContent();
        expect($hoja)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    });
});

describe('pantallas', function () {
    test('Turnos ofrece la constancia de retiro a Recepción en los turnos retirados, y la de estado al médico en los suyos', function () {
        $retirado = cnRetirado();
        $pendiente = epTurno(cambios: ['paciente_id' => $this->otraPaciente->id, 'fecha' => '2026-10-05']);

        $recepcion = $this->actingAs($this->recepcionista)->get(route('admin.turnos.index'))->assertOk();
        $recepcion->assertSee(route('admin.constancias-retiro.emitir', $retirado), false)->assertDontSee(route('admin.constancias-retiro.emitir', $pendiente), false)
            ->assertDontSee('constancia-estado', false);

        $medico = $this->actingAs($this->medicoDelTurno)->get(route('admin.turnos.index'))->assertOk();
        $medico->assertSee(route('admin.constancias-estado.emitir', $retirado), false)->assertDontSee('constancia-retiro', false);
    });
});
