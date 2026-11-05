<?php

/*
 * Comprobante de los cobros (docs/cobros-caja.md): todo medio que lo pide en el catálogo (Transferencia, POS, Depósito, Cheque) exige
 * el número; el efectivo no; no tiene que ser único; los cobros anteriores quedan como están; el comprobante se ve en el detalle y en
 * las grillas; y su corrección queda auditada. Datos inventados.
 */

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Consulta;
use App\Models\LineaCobro;
use App\Models\MedioPago;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\User;
use App\Support\Cobros\Cobrar;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $this->proc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->cajero = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole()])->create();
    $this->gerente = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::GERENCIA)->sole()])->create();
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $this->proc->id, null, 100000, Carbon::parse('2026-10-01'));
    $this->medio = fn (string $codigo) => MedioPago::where('codigo', $codigo)->sole();
    $this->actingAs($this->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
});

afterEach(fn () => Carbon::setTestNow());

/** Una prestación nueva de 100.000 (una consulta finalizada). */
function comprobanteCobrosPrestacion(): Prestacion
{
    test()->actingAs(test()->medico);
    hcConsulta();

    return Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
}

/** Cobra la prestación con un solo pago de ese medio y esa referencia. */
function comprobanteCobrosCobrar(Prestacion $prestacion, string $codigo, ?string $referencia)
{
    return test()->actingAs(test()->cajero)->from(route('admin.cobros.create'))->post(route('admin.cobros.store'), [
        'paciente_id' => test()->paciente->id,
        'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => (test()->medio)($codigo)->id, 'monto' => '100.000', 'referencia' => $referencia]],
    ]);
}

test('cada medio que no es efectivo falla sin comprobante, con el error en el campo', function (string $codigo, string $etiqueta) {
    $prestacion = comprobanteCobrosPrestacion();

    foreach ([null, '', '   '] as $vacio) {
        comprobanteCobrosCobrar($prestacion, $codigo, $vacio)->assertSessionHasErrors(['lineas.0.referencia']);
    }
    expect(Cobro::count())->toBe(0)->and(session('errors')->first('lineas.0.referencia'))->toContain($etiqueta);

    comprobanteCobrosCobrar($prestacion, $codigo, 'ABC-123')->assertSessionHasNoErrors()->assertSessionHas('status');
    expect(LineaCobro::sole()->referencia)->toBe('ABC-123');
})->with([
    'transferencia' => [MedioPago::TRANSFERENCIA, 'Código de la transferencia'],
    'POS' => [MedioPago::TARJETA, 'Voucher del POS'],
    'depósito' => [MedioPago::DEPOSITO, 'Boleta del depósito'],
    'cheque' => [MedioPago::CHEQUE, 'Número del cheque'],
]);

test('el efectivo no pide comprobante', function () {
    comprobanteCobrosCobrar(comprobanteCobrosPrestacion(), MedioPago::EFECTIVO, null)->assertSessionHasNoErrors()->assertSessionHas('status');

    expect(LineaCobro::sole()->referencia)->toBeNull()->and(($this->medio)(MedioPago::EFECTIVO)->exigeComprobante())->toBeFalse();
});

test('el servidor también lo exige al cobrar por el soporte (sin pasar por la pantalla)', function () {
    $prestacion = comprobanteCobrosPrestacion();

    expect(fn () => Cobrar::ejecutar($this->cajero, $this->paciente->id, [$prestacion->id],
        [['medio_pago_id' => ($this->medio)(MedioPago::CHEQUE)->id, 'monto' => 100000, 'referencia' => null]]))
        ->toThrow(AccionRechazada::class, 'Indique el comprobante del pago con Cheque');
});

test('dos cobros con el mismo número de comprobante se aceptan', function () {
    $primera = comprobanteCobrosPrestacion();
    comprobanteCobrosCobrar($primera, MedioPago::TRANSFERENCIA, 'TRF-0001')->assertSessionHas('status');
    $segunda = comprobanteCobrosPrestacion();
    comprobanteCobrosCobrar($segunda, MedioPago::CHEQUE, 'TRF-0001')->assertSessionHasNoErrors()->assertSessionHas('status');

    expect(LineaCobro::where('referencia', 'TRF-0001')->count())->toBe(2);
});

test('los cobros anteriores quedan como están, aunque el medio ahora pida comprobante', function () {
    $medio = ($this->medio)(MedioPago::TRANSFERENCIA);
    // Un cobro de antes de la regla: transferencia sin referencia.
    $medio->forceFill(['requiere_comprobante' => false])->save();
    $prestacion = comprobanteCobrosPrestacion();
    comprobanteCobrosCobrar($prestacion, MedioPago::TRANSFERENCIA, null)->assertSessionHas('status');
    $medio->forceFill(['requiere_comprobante' => true])->save();

    $antes = DB::table('lineas_cobro')->get()->map(fn ($fila) => (array) $fila)->all();
    $this->actingAs($this->cajero)->get(route('admin.cobros.show', Cobro::sole()))->assertOk()->assertSee('Sin comprobante cargado');
    $this->get(route('admin.cobros.index'))->assertOk();

    expect(DB::table('lineas_cobro')->get()->map(fn ($fila) => (array) $fila)->all())->toBe($antes);
});

test('el catálogo: el efectivo nunca pide comprobante y los de referencia vienen marcados', function () {
    foreach ([MedioPago::TRANSFERENCIA, MedioPago::TARJETA, MedioPago::DEPOSITO, MedioPago::CHEQUE] as $codigo) {
        expect(($this->medio)($codigo)->exigeComprobante())->toBeTrue($codigo);
    }
    $admin = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->sole()])->create();
    $efectivo = ($this->medio)(MedioPago::EFECTIVO);
    $this->actingAs($admin)->put(route('admin.medios-pago.update', $efectivo), [
        'nombre' => 'Efectivo', 'estado_id' => $efectivo->estado_id, 'requiere_comprobante' => '1', 'nombre_comprobante' => 'Recibo'])->assertSessionHasNoErrors();
    expect($efectivo->fresh()->requiere_comprobante)->toBeFalse();

    // Un medio nuevo que lo pide necesita decir cómo se llama el comprobante.
    $this->post(route('admin.medios-pago.store'), ['nombre' => 'Giro', 'requiere_comprobante' => '1', 'nombre_comprobante' => ''])
        ->assertSessionHasErrors('nombre_comprobante');
    $this->post(route('admin.medios-pago.store'), ['nombre' => 'Giro', 'requiere_comprobante' => '1', 'nombre_comprobante' => 'Número del giro'])->assertSessionHasNoErrors();
    expect(MedioPago::where('nombre', 'Giro')->sole()->exigeComprobante())->toBeTrue();
});

test('el comprobante se ve en el detalle del cobro, en la grilla de cobros y en la caja', function () {
    comprobanteCobrosCobrar(comprobanteCobrosPrestacion(), MedioPago::TARJETA, 'VOU-5555')->assertSessionHas('status');
    $cobro = Cobro::sole();

    $this->actingAs($this->cajero)->get(route('admin.cobros.show', $cobro))->assertOk()->assertSee('Voucher del POS')->assertSee('VOU-5555');
    $this->get(route('admin.cobros.index'))->assertOk()->assertSee('VOU-5555');
    $this->get(route('admin.cobros.index', ['f' => ['comprobantes' => 'VOU-55']]))->assertOk()->assertSee('VOU-5555');
    $this->get(route('admin.cajas.show', Caja::sole()))->assertOk()->assertSee('VOU-5555');
    $this->get(route('admin.cobros.create'))->assertOk();
});

// La corrección del comprobante y del medio, solo en un arqueo devuelto: tests/Feature/Economico/ArqueoCajaTest.php.
