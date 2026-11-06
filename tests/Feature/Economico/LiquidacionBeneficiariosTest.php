<?php

/*
 * Liquidación a beneficiarios del reparto (docs/liquidacion-beneficiarios.md): qué entra (solo lo cobrado a la paciente; lo de la
 * aseguradora, con su liquidación PAGADA; con copago, las dos partes), el funcionario con acuerdo de 0 % (su línea en cero no se
 * liquida y la clínica y el dueño del equipo quedan bien), el armado con su snapshot y el total exacto, una abierta por beneficiario, el
 * corte, postergar (quitar con motivo), enviar, aprobar y devolver, que nadie apruebe lo propio, las líneas marcadas, los ajustes en
 * contra y el saldo negativo que pasa a la siguiente, la línea que vuelve a entrar, los pagos (parciales, comprobante, desde la caja de
 * Tesorería como salida y su anulación con las reglas de la salida), la anulación y la inmutabilidad. Datos, precios y números
 * inventados. Prefijo "liqBenef".
 */

use App\Exceptions\AccionRechazada;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\AcuerdoReparto;
use App\Models\Caja;
use App\Models\CategoriaGasto;
use App\Models\Cobro;
use App\Models\Consulta;
use App\Models\Equipo;
use App\Models\Estado;
use App\Models\LineaLiquidacionBeneficiario;
use App\Models\LineaReparto;
use App\Models\LineaVisacion;
use App\Models\LiquidacionBeneficiario;
use App\Models\MedioPago;
use App\Models\PagoBeneficiario;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Proveedor;
use App\Models\ResponsablePago;
use App\Models\SalidaCaja;
use App\Models\SolicitudReversionCobro;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\AnularSalida;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\RevertirCobro;
use App\Support\Convenios\CalcularCobertura;
use App\Support\Convenios\FirmaRecibida;
use App\Support\Convenios\GestionConvenios;
use App\Support\Convenios\GestionCoberturas;
use App\Support\Liquidaciones\ArmarLiquidacion;
use App\Support\Liquidaciones\FlujoLiquidacion;
use App\Support\Liquidaciones\PagosAseguradora;
use App\Support\LiquidacionesBeneficiarios\ArmarLiquidacionBeneficiario as Armar;
use App\Support\LiquidacionesBeneficiarios\Elegibilidad;
use App\Support\LiquidacionesBeneficiarios\FlujoLiquidacionBeneficiario as Flujo;
use App\Support\LiquidacionesBeneficiarios\PagosBeneficiario as Pagos;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\AnularPrestacion;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\AltaAcuerdo;
use App\Support\Repartos\Porcentaje;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    hcEscenario(); // "hoy": martes 06/10/2026, 09:00 en Paraguay
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $this->consultaProc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->eco = Procedimiento::create(['codigo' => 'ECO-PRUEBA', 'nombre' => 'Ecografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    $this->gerente = liqBenefUsuario(PerfilesPredefinidos::GERENCIA);
    $this->facturacion = liqBenefUsuario(PerfilesPredefinidos::FACTURACION);
    $this->tesoreria = liqBenefUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $this->otraTesoreria = liqBenefUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $this->cajero = liqBenefUsuario(PerfilesPredefinidos::CAJA);
    $this->recepcion = liqBenefUsuario(PerfilesPredefinidos::RECEPCION);
    $this->admin = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->sole()])->create();
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO, CalcularCobertura::HITO])
        ->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $this->consultaProc->id, null, 100000, Carbon::parse('2026-10-01'));
    AltaTarifa::ejecutar($this->gerente, $this->eco->id, null, 250000, Carbon::parse('2026-10-01'));

    // La Dra. Benítez cobra el 40 %; el ecógrafo (de un proveedor) el 20 %.
    AltaAcuerdo::ejecutar($this->gerente, AcuerdoReparto::PROFESIONAL, $this->profesional->id, null, null, Porcentaje::desdeTexto('40'), Carbon::parse('2026-10-01'));
    $this->duenio = Proveedor::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Equipos', 'nombres' => 'Dueño'])->id]);
    $this->equipo = Equipo::create(['nombre' => 'Ecógrafo de prueba', 'tipo_equipo_id' => TipoEquipo::create(['nombre' => 'Ecógrafo'])->id, 'proveedor_id' => $this->duenio->id]);
    $this->equipo->procedimientos()->sync([$this->eco->id]);
    AltaAcuerdo::ejecutar($this->gerente, AcuerdoReparto::EQUIPO, null, null, $this->equipo->id, Porcentaje::desdeTexto('20'), Carbon::parse('2026-10-01'));

    [$this->efectivo, $this->transferencia] = [MedioPago::efectivo(), MedioPago::where('codigo', MedioPago::TRANSFERENCIA)->sole()];
    $this->categoria = CategoriaGasto::create(['nombre' => 'Honorarios profesionales']);
    $this->turnos = 0;
    $this->corte = Carbon::parse('2026-10-06');
});

afterEach(fn () => Carbon::setTestNow());

function liqBenefUsuario(string $codigo): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

/** Una consulta finalizada de la Dra. Benítez: Consulta ₲ 100.000 (o la ecografía con el ecógrafo, ₲ 250.000). */
function liqBenefPrestacion(bool $eco = false): Prestacion
{
    test()->actingAs(test()->medico);
    $n = ++test()->turnos;
    $turno = hcTurno(['hora_inicio' => sprintf('%02d:00', 7 + $n), 'hora_fin' => sprintf('%02d:30', 7 + $n),
        'procedimiento_id' => $eco ? test()->eco->id : test()->consultaProc->id]);
    hcGuardarNueva([], $turno)->assertSessionHasNoErrors();

    return Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->whereNull('informe_estudio_id')->sole();
}

/** Cobra a la paciente lo suyo, en efectivo, desde la caja del cajero (la abre si hace falta). */
function liqBenefCobrar(Prestacion ...$prestaciones): Cobro
{
    if (! Caja::where('usuario_id', test()->cajero->id)->whereNull('cerrada_en')->exists()) {
        AbrirCaja::ejecutar(test()->cajero, 50000);
    }
    $total = array_sum(array_map(fn (Prestacion $p) => $p->fresh()->montoPaciente(), $prestaciones));

    return Cobrar::ejecutar(test()->cajero, test()->paciente->id, array_map(fn (Prestacion $p) => $p->id, $prestaciones),
        [['medio_pago_id' => test()->efectivo->id, 'monto' => $total, 'referencia' => null]]);
}

function liqBenefRevertir(Cobro $cobro): void
{
    RevertirCobro::ejecutar(test()->cajero, $cobro, 'Cobro cargado por error');
}

function liqBenefArmar(string $tipo = 'PROFESIONAL', ?int $id = null, ?User $usuario = null): LiquidacionBeneficiario
{
    $id ??= $tipo === 'PROFESIONAL' ? test()->profesional->id : test()->duenio->id;

    return Armar::ejecutar($usuario ?? test()->facturacion, $tipo, $id, test()->corte);
}

function liqBenefAprobada(string $tipo = 'PROFESIONAL', ?int $id = null): LiquidacionBeneficiario
{
    $liquidacion = liqBenefArmar($tipo, $id);
    Flujo::enviar(test()->facturacion, $liquidacion);

    return Flujo::aprobar(test()->gerente, $liquidacion->fresh())->fresh();
}

function liqBenefPagar(LiquidacionBeneficiario $liquidacion, int $monto, array $extra = []): PagoBeneficiario
{
    return Pagos::registrar(test()->tesoreria, $liquidacion->fresh(), ['monto' => $monto, 'fecha' => Carbon::parse('2026-10-06'),
        'medio_pago_id' => test()->transferencia->id, 'referencia' => 'TR-0001', ...$extra]);
}

/** Tipo => [monto, ...] de las líneas no liberadas. */
function liqBenefLineas(LiquidacionBeneficiario $liquidacion): array
{
    return $liquidacion->lineas()->whereNull('liberada_en')->orderBy('id')->get()
        ->map(fn (LineaLiquidacionBeneficiario $l) => [$l->tipo, $l->montoEntero()])->all();
}

test('entra solo lo cobrado a la paciente; lo sin cobrar queda pendiente con su motivo', function () {
    $cobrada = liqBenefPrestacion();
    $sinCobrar = liqBenefPrestacion();
    liqBenefCobrar($cobrada);

    $previa = Armar::vistaPrevia('PROFESIONAL', $this->profesional->id, $this->corte);
    expect($previa['cantidad'])->toBe(1)->and($previa['total'])->toBe(40000)
        ->and($previa['lineas'][0]['prestacion_id'])->toBe($cobrada->id)
        ->and(Elegibilidad::motivoNoPercibida($sinCobrar->fresh()))->toBe(Elegibilidad::SIN_COBRO);

    $liquidacion = liqBenefArmar();
    expect($liquidacion->totalEntero())->toBe(40000)->and($liquidacion->enPreparacion())->toBeTrue()
        ->and($liquidacion->lineas()->sole()->only(['tipo', 'prestacion_id', 'vez', 'paciente', 'procedimiento']))
        ->toBe(['tipo' => 'ORDINARIA', 'prestacion_id' => $cobrada->id, 'vez' => 1, 'paciente' => 'Duarte, Carmen', 'procedimiento' => 'Consulta']);
});

test('la ecografía reparte entre el profesional y el dueño del equipo: dos liquidaciones separadas; la del dueño sin la paciente', function () {
    $eco = liqBenefPrestacion(eco: true);
    liqBenefCobrar($eco);

    $profesional = liqBenefArmar();
    $duenio = liqBenefArmar('EQUIPO');
    expect($profesional->totalEntero())->toBe(100000)->and($duenio->totalEntero())->toBe(50000)
        ->and($duenio->lineas()->sole()->only(['paciente', 'equipo']))->toBe(['paciente' => null, 'equipo' => 'Ecógrafo de prueba'])
        ->and((int) $duenio->lineas()->sole()->monto_base)->toBe(250000)
        ->and(Armar::vistaPrevia('PROFESIONAL', $this->profesional->id, $this->corte)['cantidad'])->toBe(0);
});

test('funcionario con acuerdo general de 0 %: se carga por la pantalla actual, su línea en cero no se liquida y la clínica y el dueño quedan bien', function () {
    // La Dra. Insfrán es funcionaria: acuerdo general de 0 % por la pantalla de acuerdos, sin cambios.
    $this->actingAs($this->gerente)->post(route('admin.acuerdos-reparto.store'), ['tipo' => 'PROFESIONAL', 'profesional_id' => $this->otroProfesional->id,
        'procedimiento_id' => '', 'porcentaje' => '0', 'vigente_desde' => '06/10/2026'])->assertSessionHasNoErrors();
    expect(AcuerdoReparto::where('profesional_id', $this->otroProfesional->id)->sole()->porcentaje)->toBe('0.00');

    $this->profesional = $this->otroProfesional; // hcTurno usa test()->profesional
    $this->medico = $this->otroMedico;
    $eco = liqBenefPrestacion(eco: true);
    liqBenefCobrar($eco);

    $lineas = LineaReparto::whereHas('reparto', fn ($q) => $q->where('prestacion_id', $eco->id))->get()->keyBy('beneficiario');
    expect($eco->fresh()->reparto->repartido())->toBeTrue()
        ->and([(string) $lineas['PROFESIONAL']->porcentaje, (int) $lineas['PROFESIONAL']->monto])->toBe(['0.00', 0])
        ->and([(string) $lineas['EQUIPO']->porcentaje, (int) $lineas['EQUIPO']->monto])->toBe(['20.00', 50000])
        ->and([(string) $lineas['CLINICA']->porcentaje, (int) $lineas['CLINICA']->monto])->toBe(['80.00', 200000])
        ->and(Armar::vistaPrevia('PROFESIONAL', $this->otroProfesional->id, $this->corte)['cantidad'])->toBe(0)
        ->and(Armar::vistaPrevia('EQUIPO', $this->duenio->id, $this->corte)['total'])->toBe(50000);
    expect(fn () => liqBenefArmar('PROFESIONAL', $this->otroProfesional->id))->toThrow(AccionRechazada::class, Armar::SIN_LINEAS);
});

test('sin acuerdo el reparto sigue pendiente (no cambia el cálculo) y nada se liquida hasta repartirlo', function () {
    $this->profesional = $this->otroProfesional;
    $this->medico = $this->otroMedico;
    $prestacion = liqBenefPrestacion();
    liqBenefCobrar($prestacion);

    expect($prestacion->fresh()->reparto->pendiente())->toBeTrue()
        ->and($prestacion->fresh()->reparto->motivo_pendiente)->toContain('Falta el acuerdo del profesional')
        ->and(Armar::vistaPrevia('PROFESIONAL', $this->otroProfesional->id, $this->corte)['cantidad'])->toBe(0);
});

test('aseguradora: entra solo con su liquidación PAGADA del todo; con copago, cuando además se cobró a la paciente', function () {
    $aseguradora = ResponsablePago::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Seguros de Prueba', 'nombres' => 'S.A.'])->id]);
    $convenio = GestionConvenios::alta($this->facturacion, $aseguradora->id, 'Convenio de prueba', Carbon::parse('2026-10-01'), null, ['Plan Oro']);
    GestionCoberturas::cargarCobertura($this->recepcion, $this->paciente, $convenio->planes()->sole()->id, 'AS-0001', 'TITULAR', Carbon::parse('2026-10-01'), null);
    $turno = hcTurno(['procedimiento_id' => $this->consultaProc->id]);
    GestionCoberturas::cargarVisacion($this->recepcion, $this->paciente, 'turno', $turno->id, 'V-1', Carbon::parse('2026-10-06'),
        [['procedimiento_id' => $this->consultaProc->id, 'resultado' => LineaVisacion::APROBADO, 'copago' => LineaVisacion::PORCENTAJE, 'valor' => '20']]);
    $this->actingAs($this->medico);
    hcGuardarNueva([], $turno)->assertSessionHasNoErrors();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    FirmaRecibida::marcar($this->recepcion, $prestacion);
    expect([$prestacion->fresh()->montoPaciente(), (int) $prestacion->fresh()->monto_aseguradora])->toBe([20000, 80000]);

    $vacia = fn () => Armar::vistaPrevia('PROFESIONAL', $this->profesional->id, $this->corte)['cantidad'];
    liqBenefCobrar($prestacion);
    expect($vacia())->toBe(0)->and(Elegibilidad::motivoNoPercibida($prestacion->fresh()))->toBe(Elegibilidad::ASEGURADORA_SIN_LIQUIDAR);

    $liquidacion = ArmarLiquidacion::ejecutar($this->facturacion, $aseguradora, $this->corte);
    FlujoLiquidacion::enviar($this->facturacion, $liquidacion);
    FlujoLiquidacion::aprobar($this->gerente, $liquidacion->fresh());
    PagosAseguradora::registrar($this->facturacion, $liquidacion->fresh(), 50000, Carbon::parse('2026-10-06'), $this->transferencia->id, 'TR-1');
    // Pagada en parte: los pagos parciales no se asignan por prestación; todavía no entra.
    expect($vacia())->toBe(0)->and(Elegibilidad::motivoNoPercibida($prestacion->fresh()))->toStartWith(Elegibilidad::ASEGURADORA_SIN_PAGAR);

    PagosAseguradora::registrar($this->facturacion, $liquidacion->fresh(), 30000, Carbon::parse('2026-10-06'), $this->transferencia->id, 'TR-2');
    // La línea entra entera: el 40 % del total (₲ 100.000), no solo de una parte.
    expect(Armar::vistaPrevia('PROFESIONAL', $this->profesional->id, $this->corte)['total'])->toBe(40000);
});

test('una sola abierta por beneficiario, corte no futuro, la vista previa que cambió se rechaza y una línea no entra dos veces', function () {
    liqBenefCobrar(liqBenefPrestacion());
    $liquidacion = liqBenefArmar();

    expect(fn () => liqBenefArmar())->toThrow(AccionRechazada::class, Armar::YA_ABIERTA)
        ->and(fn () => Armar::ejecutar($this->facturacion, 'EQUIPO', $this->duenio->id, Carbon::parse('2026-10-07')))->toThrow(AccionRechazada::class, Armar::CORTE_FUTURO)
        ->and(fn () => Armar::ejecutar($this->facturacion, 'PROFESIONAL', 999999, $this->corte))->toThrow(AccionRechazada::class, Armar::BENEFICIARIO_INVALIDO);
    // El índice frena una segunda abierta aunque se saltee la verificación.
    expect(fn () => LiquidacionBeneficiario::create(['beneficiario' => 'PROFESIONAL', 'profesional_id' => $this->profesional->id, 'fecha_corte' => '2026-10-06',
        'total' => 1, 'moneda_id' => $liquidacion->moneda_id, 'armada_en' => now(), 'armada_por' => $this->facturacion->id]))->toThrow(QueryException::class);

    Flujo::enviar($this->facturacion, $liquidacion);
    Flujo::aprobar($this->gerente, $liquidacion->fresh());
    expect(Armar::vistaPrevia('PROFESIONAL', $this->profesional->id, $this->corte)['cantidad'])->toBe(0)
        ->and(fn () => liqBenefArmar())->toThrow(AccionRechazada::class, Armar::SIN_LINEAS);

    liqBenefCobrar(liqBenefPrestacion());
    expect(fn () => Armar::ejecutar($this->facturacion, 'PROFESIONAL', $this->profesional->id, $this->corte, 2, 80000))->toThrow(AccionRechazada::class, Armar::CAMBIO);
    expect(Armar::ejecutar($this->facturacion, 'PROFESIONAL', $this->profesional->id, $this->corte, 1, 40000)->totalEntero())->toBe(40000);
});

test('el corte: lo de después queda para la siguiente; lo pendiente anterior entra', function () {
    $ayer = liqBenefPrestacion();
    $hoy = liqBenefPrestacion();
    liqBenefCobrar($ayer, $hoy);
    // Como si la de ayer se hubiera atendido el 05/10 (sin pasar por el modelo, que no deja cambiar la fecha).
    DB::table('prestaciones')->where('id', $ayer->id)->update(['fecha_servicio' => '2026-10-05']);

    expect(Armar::ejecutar($this->facturacion, 'PROFESIONAL', $this->profesional->id, Carbon::parse('2026-10-05'))->lineas()->pluck('prestacion_id')->all())
        ->toBe([$ayer->id]);
    expect(Armar::vistaPrevia('PROFESIONAL', $this->profesional->id, $this->corte)['cantidad'])->toBe(1);
});

test('postergar = quitar con motivo: baja el total y la línea entra en la siguiente', function () {
    $a = liqBenefPrestacion();
    $b = liqBenefPrestacion();
    liqBenefCobrar($a, $b);
    $liquidacion = liqBenefArmar();
    $linea = $liquidacion->lineas()->where('prestacion_id', $b->id)->sole();

    expect(fn () => Flujo::quitarLinea($this->facturacion, $linea, 'no'))->toThrow(AccionRechazada::class, 'al menos 5');
    Flujo::quitarLinea($this->facturacion, $linea, 'Se posterga al mes siguiente');
    expect($liquidacion->fresh()->totalEntero())->toBe(40000)->and($linea->fresh()->liberacion)->toBe('QUITADA');

    Flujo::enviar($this->facturacion, $liquidacion->fresh());
    Flujo::aprobar($this->gerente, $liquidacion->fresh());
    expect(liqBenefArmar()->lineas()->pluck('prestacion_id')->all())->toBe([$b->id]);
});

test('enviar, aprobar y devolver: nadie aprueba lo propio; lo de Gerencia lo aprueba el Administrador', function () {
    liqBenefCobrar(liqBenefPrestacion());
    $liquidacion = liqBenefArmar(usuario: $this->gerente);

    expect(fn () => Flujo::aprobar($this->gerente, $liquidacion))->toThrow(AccionRechazada::class, 'no está pendiente');
    Flujo::enviar($this->gerente, $liquidacion);
    expect(fn () => Flujo::aprobar($this->gerente, $liquidacion->fresh()))->toThrow(AccionRechazada::class, Flujo::PROPIA)
        ->and(fn () => Flujo::devolver($this->gerente, $liquidacion->fresh(), 'Revisar montos'))->toThrow(AccionRechazada::class, Flujo::PROPIA);

    Flujo::devolver($this->admin, $liquidacion->fresh(), 'Revisar montos');
    expect($liquidacion->fresh()->enPreparacion())->toBeTrue()->and($liquidacion->fresh()->nota_devolucion)->toBe('Revisar montos');
    Flujo::enviar($this->gerente, $liquidacion->fresh());
    Flujo::aprobar($this->admin, $liquidacion->fresh());
    expect($liquidacion->fresh()->tieneEstado(Estado::APROBADO))->toBeTrue();
    expect(fn () => Flujo::quitarLinea($this->gerente, $liquidacion->lineas()->first(), 'Ya aprobada'))->toThrow(AccionRechazada::class, 'en preparación');
});

test('línea marcada: un cobro revertido en una abierta impide enviar o aprobar hasta quitarla', function () {
    $a = liqBenefPrestacion();
    $b = liqBenefPrestacion();
    liqBenefCobrar($a);
    $cobroB = liqBenefCobrar($b);
    $liquidacion = liqBenefArmar();

    liqBenefRevertir($cobroB);
    $marcadas = Flujo::marcadas($liquidacion->fresh());
    expect($marcadas)->toHaveCount(1)->and($marcadas[0]['linea']->prestacion_id)->toBe($b->id)->and($marcadas[0]['motivo'])->toContain('se revirtió')
        ->and(fn () => Flujo::enviar($this->facturacion, $liquidacion->fresh()))->toThrow(AccionRechazada::class, Flujo::conMarcadas(1, false));

    // Ya enviada (antes de revertir otro): Gerencia no la aprueba; la devuelve.
    Flujo::quitarLinea($this->facturacion, $marcadas[0]['linea'], 'Cobro revertido');
    Flujo::enviar($this->facturacion, $liquidacion->fresh());
    liqBenefRevertir(Cobro::whereHas('prestaciones', fn ($q) => $q->where('prestacion_id', $a->id))->sole());
    expect(fn () => Flujo::aprobar($this->gerente, $liquidacion->fresh()))->toThrow(AccionRechazada::class, Flujo::conMarcadas(1, true));
});

test('ajuste en contra: lo aprobado que se revierte va en la siguiente; el neto negativo no se paga y pasa a la siguiente; si se vuelve a cobrar, entra de nuevo', function () {
    $a = liqBenefPrestacion();
    $cobro = liqBenefCobrar($a);
    $primera = liqBenefAprobada();
    expect($primera->totalEntero())->toBe(40000);

    liqBenefRevertir($cobro);
    $segunda = liqBenefArmar();
    expect(liqBenefLineas($segunda))->toBe([['AJUSTE', -40000]])->and($segunda->totalEntero())->toBe(-40000)
        ->and($segunda->lineas()->sole()->ajusta_linea_id)->toBe($primera->lineas()->sole()->id)
        ->and($segunda->lineas()->sole()->motivo_ajuste)->toContain('se revirtió')->toContain("N.º {$primera->id}");
    Flujo::enviar($this->facturacion, $segunda);
    Flujo::aprobar($this->gerente, $segunda->fresh());
    $segunda = $segunda->fresh();
    expect($segunda->saldo())->toBe(0)->and($segunda->saldoEnContra())->toBe(40000)->and($segunda->etiquetaEstado())->toBe('Aprobada (saldo en contra)')
        ->and(fn () => liqBenefPagar($segunda, 1))->toThrow(AccionRechazada::class, Pagos::NO_APROBADA);

    // Vuelve a cobrarse y hay otra prestación: entran las dos y el saldo anterior se descuenta.
    liqBenefCobrar($a->fresh());
    liqBenefCobrar(liqBenefPrestacion());
    $tercera = liqBenefArmar();
    expect(liqBenefLineas($tercera))->toBe([['ORDINARIA', 40000], ['ORDINARIA', 40000], ['SALDO_ANTERIOR', -40000]])
        ->and($tercera->totalEntero())->toBe(40000)
        ->and($tercera->lineas()->where('prestacion_id', $a->id)->sole()->vez)->toBe(2);
});

test('el ajuste pendiente de una prestación que se vuelve a cobrar queda marcado', function () {
    $a = liqBenefPrestacion();
    $cobro = liqBenefCobrar($a);
    liqBenefAprobada();
    liqBenefRevertir($cobro);
    $segunda = liqBenefArmar();

    liqBenefCobrar($a->fresh());
    $marcadas = Flujo::marcadas($segunda->fresh());
    expect($marcadas)->toHaveCount(1)->and($marcadas[0]['linea']->tipo)->toBe('AJUSTE')->and($marcadas[0]['motivo'])->toContain('volvió a corresponder');
});

test('prestación anulada (tras revertir el cobro): el ajuste lleva el motivo; la línea del reparto anulado no vuelve', function () {
    $a = liqBenefPrestacion();
    $cobro = liqBenefCobrar($a);
    liqBenefAprobada();
    liqBenefRevertir($cobro);
    AnularPrestacion::ejecutar($this->gerente, $a->fresh(), 'Prestación cargada por error');

    $ajuste = liqBenefArmar()->lineas()->sole();
    expect($ajuste->tipo)->toBe('AJUSTE')->and($ajuste->motivo_ajuste)->toContain('Prestación cargada por error');
});

test('pagos: parciales con su saldo y estado, el comprobante según el medio, el que supera el saldo y la anulación sin caja', function () {
    liqBenefCobrar(liqBenefPrestacion(), liqBenefPrestacion());
    $liquidacion = liqBenefAprobada();

    expect(fn () => liqBenefPagar($liquidacion, 10000, ['referencia' => '']))->toThrow(AccionRechazada::class, $this->transferencia->mensajeComprobanteFaltante())
        ->and(fn () => liqBenefPagar($liquidacion, 90000))->toThrow(AccionRechazada::class, 'supera el saldo')
        ->and(fn () => liqBenefPagar($liquidacion, 10000, ['fecha' => Carbon::parse('2026-10-07')]))->toThrow(AccionRechazada::class, 'posterior a hoy');

    $pago = liqBenefPagar($liquidacion, 30000);
    expect($liquidacion->fresh()->tieneEstado(Estado::PARCIAL))->toBeTrue()->and($liquidacion->fresh()->saldo())->toBe(50000)
        ->and($pago->caja_id)->toBeNull()->and($pago->cuenta_por_pagar_id)->toBeNull();
    liqBenefPagar($liquidacion, 50000, ['medio_pago_id' => $this->efectivo->id, 'referencia' => '']);
    expect($liquidacion->fresh()->tieneEstado(Estado::PAGADO))->toBeTrue()->and($liquidacion->fresh()->saldo())->toBe(0);

    // Lo anula quien lo registró o Gerencia; otra persona de Tesorería, no.
    expect(fn () => Pagos::anular($this->otraTesoreria, $pago, 'Pago duplicado'))->toThrow(AccionRechazada::class, Pagos::AJENO);
    Pagos::anular($this->gerente, $pago, 'Pago duplicado');
    expect($pago->fresh()->anulado())->toBeTrue()->and($liquidacion->fresh()->tieneEstado(Estado::PARCIAL))->toBeTrue()
        ->and(fn () => Pagos::anular($this->tesoreria, $pago->fresh(), 'Otra vez'))->toThrow(AccionRechazada::class, 'ya está anulado');
});

test('pago en efectivo desde la caja de Tesorería: sale como salida vinculada; se anula con las reglas de la salida', function () {
    liqBenefCobrar(liqBenefPrestacion(), liqBenefPrestacion());
    $liquidacion = liqBenefAprobada();
    $caja = AbrirCaja::ejecutar($this->tesoreria, 500000);
    $efectivo = fn () => $caja->fresh()->esperadoPorMedio()[$this->efectivo->id];

    expect(fn () => liqBenefPagar($liquidacion, 10000, ['caja_id' => $caja->id, 'categoria_gasto_id' => $this->categoria->id]))
        ->toThrow(AccionRechazada::class, Pagos::CAJA_SOLO_EFECTIVO)
        ->and(fn () => liqBenefPagar($liquidacion, 10000, ['medio_pago_id' => $this->efectivo->id, 'referencia' => '', 'caja_id' => $caja->id]))
        ->toThrow(AccionRechazada::class, Pagos::SIN_CATEGORIA)
        // La caja es la propia: otra persona no paga desde ella.
        ->and(fn () => Pagos::registrar($this->otraTesoreria, $liquidacion, ['monto' => 10000, 'fecha' => Carbon::parse('2026-10-06'),
            'medio_pago_id' => $this->efectivo->id, 'referencia' => null, 'caja_id' => $caja->id, 'categoria_gasto_id' => $this->categoria->id]))
        ->toThrow(AccionRechazada::class, 'Solo el cajero de la caja');

    $enCaja = ['medio_pago_id' => $this->efectivo->id, 'referencia' => 'REC-1', 'caja_id' => $caja->id, 'categoria_gasto_id' => $this->categoria->id];
    $pago = liqBenefPagar($liquidacion, 30000, $enCaja);
    $salida = SalidaCaja::findOrFail($pago->salida_caja_id);
    expect($salida->only(['caja_id', 'beneficiario', 'comprobante']))->toBe(['caja_id' => $caja->id, 'beneficiario' => 'Benítez, Rosa', 'comprobante' => 'REC-1'])
        ->and((int) $salida->monto)->toBe(30000)->and($efectivo())->toBe(470000);

    // Dentro del plazo: quien lo registró lo anula y el efectivo vuelve.
    expect(Pagos::anular($this->tesoreria, $pago, 'Monto equivocado'))->toContain('Pago anulado');
    expect($pago->fresh()->anulado())->toBeTrue()->and($salida->fresh()->anulada())->toBeTrue()->and($efectivo())->toBe(500000)
        ->and($liquidacion->fresh()->tieneEstado(Estado::APROBADO))->toBeTrue();

    // Pasado el plazo: va la solicitud y Gerencia la aprueba (ahí se anulan la salida y el pago).
    $otro = liqBenefPagar($liquidacion, 20000, $enCaja);
    Carbon::setTestNow(now()->addMinutes(AnularSalida::plazoMinutos() + 1));
    expect(fn () => Pagos::anular($this->gerente, $otro, 'Desde Gerencia'))->toThrow(AccionRechazada::class, 'salió de su caja');
    expect(Pagos::anular($this->tesoreria, $otro, 'Se pagó dos veces'))->toBe(Pagos::SOLICITADA);
    expect($otro->fresh()->anulado())->toBeFalse();
    AnularSalida::aprobar($this->gerente, SolicitudReversionCobro::where('salida_caja_id', $otro->salida_caja_id)->sole());
    expect($otro->fresh()->anulado())->toBeTrue()->and($otro->fresh()->motivo_anulacion)->toContain('Se pagó dos veces');

    // Con la caja cerrada ya no se anula.
    $tercero = liqBenefPagar($liquidacion, 10000, $enCaja);
    $caja->forceFill(['cerrada_en' => now()])->saveQuietly();
    expect(fn () => Pagos::anular($this->tesoreria, $tercero, 'Tarde'))->toThrow(AccionRechazada::class, Pagos::CAJA_CERRADA);
});

test('anular la salida desde la caja también anula su pago', function () {
    liqBenefCobrar(liqBenefPrestacion());
    $liquidacion = liqBenefAprobada();
    $caja = AbrirCaja::ejecutar($this->tesoreria, 100000);
    $pago = liqBenefPagar($liquidacion, 40000, ['medio_pago_id' => $this->efectivo->id, 'referencia' => '', 'caja_id' => $caja->id, 'categoria_gasto_id' => $this->categoria->id]);
    expect($liquidacion->fresh()->tieneEstado(Estado::PAGADO))->toBeTrue();

    AnularSalida::anular($this->tesoreria, $pago->salida, 'Se anula desde la caja');
    expect($pago->fresh()->anulado())->toBeTrue()->and($liquidacion->fresh()->tieneEstado(Estado::APROBADO))->toBeTrue();
});

test('anular: con pagos no; sin pagos libera sus líneas; con una liquidación que depende de ella, no', function () {
    $a = liqBenefPrestacion();
    $cobro = liqBenefCobrar($a);
    $primera = liqBenefAprobada();
    $pago = liqBenefPagar($primera, 40000);

    expect(fn () => Flujo::anular($this->gerente, $primera->fresh(), 'Error de armado'))->toThrow(AccionRechazada::class, Flujo::CON_PAGOS);
    Pagos::anular($this->tesoreria, $pago, 'Pago mal cargado');

    liqBenefRevertir($cobro);
    $segunda = liqBenefArmar();
    expect(fn () => Flujo::anular($this->gerente, $primera->fresh(), 'Error de armado'))->toThrow(AccionRechazada::class, "liquidación N.º {$segunda->id}");

    Flujo::anular($this->facturacion, $segunda, 'Se arma de nuevo');
    expect($segunda->fresh()->anulada())->toBeTrue()->and($segunda->lineas()->sole()->liberacion)->toBe('ANULADA');
    Flujo::anular($this->gerente, $primera->fresh(), 'Error de armado');
    expect($primera->lineas()->sole()->liberada())->toBeTrue()
        ->and(fn () => Flujo::anular($this->gerente, $primera->fresh(), 'Otra vez'))->toThrow(AccionRechazada::class, 'ya está anulada');
});

test('el modelo rechaza los cambios fuera del flujo y el borrado', function () {
    liqBenefCobrar(liqBenefPrestacion());
    $liquidacion = liqBenefAprobada();
    $linea = $liquidacion->lineas()->sole();
    $pago = liqBenefPagar($liquidacion, 10000);

    expect(fn () => $liquidacion->fresh()->forceFill(['total' => 1])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $liquidacion->fresh()->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $linea->forceFill(['monto' => 1])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $linea->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $pago->forceFill(['monto' => 1])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $pago->delete())->toThrow(RegistroEconomicoInmutable::class);
});

test('los permisos de la matriz: quién recibe cada módulo nuevo (y Tesorería abre su caja sin cobrar ni recibir arqueos)', function () {
    $de = fn (string $perfil, string $modulo) => PerfilesPredefinidos::MATRIZ[$perfil][$modulo] ?? [];
    expect($de(PerfilesPredefinidos::FACTURACION, 'LIQUIDACIONES_BENEFICIARIOS'))->toBe(['VER', 'CREAR', 'EDITAR', 'DESACTIVAR', 'EXPORTAR'])
        ->and($de(PerfilesPredefinidos::FACTURACION, 'APROBACION_LIQ_BENEFICIARIOS'))->toBe([])
        ->and($de(PerfilesPredefinidos::GERENCIA, 'APROBACION_LIQ_BENEFICIARIOS'))->toBe(['VER', 'EDITAR'])
        ->and($de(PerfilesPredefinidos::GERENCIA, 'PAGOS_BENEFICIARIOS'))->toBe(['VER', 'DESACTIVAR'])
        ->and($de(PerfilesPredefinidos::COMPRAS_TESORERIA, 'PAGOS_BENEFICIARIOS'))->toBe(['VER', 'CREAR', 'DESACTIVAR'])
        ->and($de(PerfilesPredefinidos::COMPRAS_TESORERIA, 'CAJAS'))->toBe(['VER', 'CREAR', 'EDITAR'])
        ->and($de(PerfilesPredefinidos::COMPRAS_TESORERIA, 'COBROS'))->toBe([])
        ->and($de(PerfilesPredefinidos::COMPRAS_TESORERIA, 'CIERRES_CAJA'))->toBe([])
        ->and($de(PerfilesPredefinidos::MEDICO, 'MIS_LIQUIDACIONES'))->toBe(['VER', 'EXPORTAR'])
        ->and($de(PerfilesPredefinidos::MEDICO, 'LIQUIDACIONES_BENEFICIARIOS'))->toBe([]);
    foreach ([PerfilesPredefinidos::AUDITOR, PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::ENFERMERIA] as $perfil) {
        foreach (array_keys(PerfilesPredefinidos::MODULOS_LIQUIDACION_BENEFICIARIOS) as $modulo) {
            expect($de($perfil, $modulo))->toBe([], "{$perfil} {$modulo}");
        }
    }
    // Lo que suman las migraciones es lo mismo que la matriz.
    foreach ([...PerfilesPredefinidos::PERMISOS_LIQUIDACION_BENEFICIARIOS, ...PerfilesPredefinidos::PERMISOS_TESORERIA_CAJA] as $modulo => $porPerfil) {
        foreach ($porPerfil as $perfil => $acciones) {
            expect($acciones)->toBe($de($perfil, $modulo), "{$perfil} {$modulo}");
        }
    }
});
