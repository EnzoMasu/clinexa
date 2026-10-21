<?php

/*
 * Cobros y caja (docs/cobros-caja.md): abrir la caja, cobrar una y varias prestaciones combinando medios, la suma que no cierra,
 * la prestación ya cobrada, sin caja (o con la de otro día) no se cobra, una sola caja abierta por cajero, el cierre con y sin
 * diferencia, la aprobación y la devolución, la caja cerrada sin cobros, la prestación cobrada que no se anula, los permisos, la
 * pantalla de cobro y la auditoría. Datos, precios y montos inventados.
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Caja;
use App\Models\CierreCaja;
use App\Models\Cobro;
use App\Models\CobroPrestacion;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\Cobrar;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\AnularPrestacion;
use App\Support\Prestaciones\GenerarPrestacion;
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
    $this->cajero = cobrosCajaUsuario(PerfilesPredefinidos::CAJA, ['apellidos' => 'Cáceres', 'nombres' => 'Lidia']);
    $this->otroCajero = cobrosCajaUsuario(PerfilesPredefinidos::CAJA, ['apellidos' => 'Ortiz', 'nombres' => 'Marta']);
    $this->gerente = cobrosCajaUsuario(PerfilesPredefinidos::GERENCIA, ['apellidos' => 'Gómez', 'nombres' => 'Ana']);
    // Las migraciones guardaron la hora real en que corrieron; el "hoy" de estos tests es anterior.
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $this->consultaProc->id, null, 100000, Carbon::parse('2026-10-01'));
    AltaTarifa::ejecutar($this->gerente, $this->eco->id, null, 250000, Carbon::parse('2026-10-01'));
    [$this->efectivo, $this->transferencia, $this->tarjeta] = [MedioPago::where('codigo', MedioPago::EFECTIVO)->sole(),
        MedioPago::where('codigo', MedioPago::TRANSFERENCIA)->sole(), MedioPago::where('codigo', MedioPago::TARJETA)->sole()];
});

afterEach(fn () => Carbon::setTestNow());

function cobrosCajaUsuario(string $codigo, array $persona = []): User
{
    $factory = User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()]);

    return ($persona ? $factory->conPersona($persona) : $factory)->create();
}

/** Las prestaciones de una consulta finalizada de Carmen Duarte (sin turno: la de consulta; con turno de ecografía: la eco). */
function cobrosCajaPrestacion(bool $ecografia = false): Prestacion
{
    test()->actingAs(test()->medico);
    if ($ecografia) {
        hcGuardarNueva([], hcTurno(['procedimiento_id' => test()->eco->id]))->assertSessionHasNoErrors();
    } else {
        hcConsulta();
    }

    return Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
}

/** Abre la caja del cajero (por la pantalla) con ese monto inicial. */
function cobrosCajaAbrir(?User $cajero = null, string $monto = '50.000')
{
    return test()->actingAs($cajero ?? test()->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => $monto]);
}

/** Cobra por la pantalla: prestaciones y líneas [medio, monto, referencia]. */
function cobrosCajaCobrar(array $prestaciones, array $lineas, ?User $cajero = null)
{
    return test()->actingAs($cajero ?? test()->cajero)->from(route('admin.cobros.create'))->post(route('admin.cobros.store'), [
        'paciente_id' => test()->paciente->id,
        'prestaciones' => array_map(fn (Prestacion $prestacion) => $prestacion->id, $prestaciones),
        'lineas' => array_map(fn (array $linea) => ['medio_pago_id' => $linea[0]->id, 'monto' => $linea[1], 'referencia' => $linea[2] ?? ''], $lineas),
    ]);
}

function cobrosCajaMia(?User $cajero = null): Caja
{
    return Caja::where('usuario_id', ($cajero ?? test()->cajero)->id)->latest('id')->firstOrFail();
}

/** Cierra la caja por la pantalla con lo contado por medio [MedioPago, monto]. */
function cobrosCajaCerrar(Caja $caja, array $contado, ?string $motivo = null)
{
    $declarado = [];
    foreach (MedioPago::all() as $medio) {
        $declarado[$medio->id] = '0';
    }
    foreach ($contado as [$medio, $monto]) {
        $declarado[$medio->id] = $monto;
    }

    return test()->actingAs(test()->cajero)->from(route('admin.cajas.show', $caja))
        ->post(route('admin.cajas.cerrar', $caja), ['contado' => $declarado, 'motivo' => $motivo]);
}

test('cobro de una prestación en efectivo: queda Cobrada y es un movimiento de la caja', function () {
    $prestacion = cobrosCajaPrestacion();
    cobrosCajaAbrir()->assertSessionHasNoErrors()->assertSessionHas('status', 'Caja abierta con ₲ 50.000 en efectivo.');
    $caja = cobrosCajaMia();
    expect($caja->abierta())->toBeTrue()->and($caja->fecha->format('Y-m-d'))->toBe('2026-10-06');

    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']])->assertSessionHas('status', 'Cobro registrado: ₲ 100.000.');

    $cobro = Cobro::sole();
    expect((int) $cobro->total)->toBe(100000)->and($cobro->caja_id)->toBe($caja->id)->and($cobro->paciente_id)->toBe($this->paciente->id)
        ->and($cobro->tieneEstado(Estado::VIGENTE))->toBeTrue()
        ->and($prestacion->fresh()->estadoCobroTexto())->toBe(Prestacion::COBRADA)
        // El estado de la prestación no cambia: el de cobro es derivado.
        ->and($prestacion->fresh()->tieneEstado(Estado::TARIFADO))->toBeTrue()
        ->and(MovimientoCaja::where('caja_id', $caja->id)->orderBy('id')->get()->map(fn ($m) => [$m->tipo, (int) $m->monto])->all())
        ->toBe([[MovimientoCaja::APERTURA, 50000], [MovimientoCaja::COBRO, 100000]])
        ->and($caja->esperadoPorMedio())->toBe([$this->efectivo->id => 150000]);
});

test('cobro de varias prestaciones combinando medios, con referencia; el reparto pendiente no lo impide', function () {
    $consulta = cobrosCajaPrestacion();
    $eco = cobrosCajaPrestacion(ecografia: true);
    // Sin acuerdos: las dos quedaron pendientes de reparto, y se cobran igual.
    expect(RepartirPrestacion::pendientes()->count())->toBe(2);
    cobrosCajaAbrir(monto: '0');

    cobrosCajaCobrar([$consulta, $eco], [[$this->efectivo, '50000'], [$this->transferencia, '200.000', 'TRF-0001'], [$this->tarjeta, '100.000', 'POS-77']])
        ->assertSessionHas('status', 'Cobro registrado: ₲ 350.000.');

    $cobro = Cobro::with(['lineas', 'prestaciones'])->sole();
    expect((int) $cobro->total)->toBe(350000)
        ->and($cobro->prestaciones->map(fn ($p) => [$p->prestacion_id, (int) $p->monto])->all())->toBe([[$consulta->id, 100000], [$eco->id, 250000]])
        ->and($cobro->lineas->map(fn ($l) => [$l->medio_pago_id, (int) $l->monto, $l->referencia])->all())
        ->toBe([[$this->efectivo->id, 50000, null], [$this->transferencia->id, 200000, 'TRF-0001'], [$this->tarjeta->id, 100000, 'POS-77']])
        ->and(cobrosCajaMia()->esperadoPorMedio())->toEqualCanonicalizing([$this->efectivo->id => 50000, $this->transferencia->id => 200000, $this->tarjeta->id => 100000])
        ->and(MovimientoCaja::where('caja_id', cobrosCajaMia()->id)->count())->toBe(3); // sin apertura: empezó en cero
});

test('la suma de los pagos que no cierra con el total se rechaza y no registra nada', function () {
    $prestacion = cobrosCajaPrestacion();
    cobrosCajaAbrir();

    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '90.000']])
        ->assertSessionHas('error', 'La suma de los pagos (₲ 90.000) debe ser igual al total de las prestaciones elegidas (₲ 100.000).');
    // Con vuelto tampoco: se carga el monto exacto.
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000'], [$this->tarjeta, '5.000']])->assertSessionHas('error');
    // Una línea a medias (medio sin monto) se rechaza.
    test()->actingAs($this->cajero)->from(route('admin.cobros.create'))->post(route('admin.cobros.store'), [
        'paciente_id' => $this->paciente->id, 'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => $this->efectivo->id, 'monto' => '100000'], ['medio_pago_id' => $this->tarjeta->id, 'monto' => '']],
    ])->assertSessionHas('error', 'Cada pago necesita el medio y el monto.');

    expect(Cobro::count())->toBe(0)->and(CobroPrestacion::count())->toBe(0)->and(MovimientoCaja::where('tipo', MovimientoCaja::COBRO)->count())->toBe(0)
        ->and($prestacion->fresh()->estadoCobroTexto())->toBe(Prestacion::PENDIENTE_DE_COBRO);
});

test('una prestación ya cobrada no se cobra de nuevo; tampoco una sin tarifar, anulada o de otra paciente', function () {
    $prestacion = cobrosCajaPrestacion();
    cobrosCajaAbrir();
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']])->assertSessionHas('status');

    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']])->assertSessionHas('error', Cobrar::YA_COBRADA);
    // Otra cajera, con su propia caja, tampoco.
    cobrosCajaAbrir($this->otroCajero);
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']], $this->otroCajero)->assertSessionHas('error', Cobrar::YA_COBRADA);
    expect(Cobro::count())->toBe(1);

    // La base no admite un segundo cobro vigente aunque se saltee la verificación.
    $otro = Cobro::sole()->replicate();
    $otro->save();
    expect(fn () => DB::transaction(fn () => DB::table('cobro_prestaciones')->insert([
        'cobro_id' => $otro->id, 'prestacion_id' => $prestacion->id, 'monto' => 100000, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    // Sin tarifar (un procedimiento sin tarifa): no se ofrece ni se cobra.
    $sinTarifaProc = Procedimiento::create(['codigo' => 'SIN-TARIFA', 'nombre' => 'Procedimiento sin tarifa', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->actingAs($this->medico);
    hcGuardarNueva([], hcTurno(['procedimiento_id' => $sinTarifaProc->id, 'hora_inicio' => '10:00', 'hora_fin' => '10:30']))->assertSessionHasNoErrors();
    $sinTarifa = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    expect($sinTarifa->pendiente())->toBeTrue()->and(Cobrar::pendientes()->whereKey($sinTarifa->id)->exists())->toBeFalse();
    cobrosCajaCobrar([$sinTarifa], [[$this->efectivo, '1']])->assertSessionHas('error', 'Una de las prestaciones elegidas no está tarifada: tarifela antes de cobrarla.');
});

test('sin caja abierta no se cobra, ni con la caja abierta de un día anterior', function () {
    $prestacion = cobrosCajaPrestacion();

    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']])->assertSessionHas('error', Cobrar::SIN_CAJA);
    $this->get(route('admin.cobros.create'))->assertOk()->assertSee(Cobrar::SIN_CAJA);

    cobrosCajaAbrir();
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC')); // al día siguiente, sin cerrarla
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']])
        ->assertSessionHas('error', 'Su caja está abierta desde el 06/10/2026: ciérrela y abra la de hoy para cobrar.');
    $this->get(route('admin.cajas.index'))->assertOk()->assertSee('Su caja está abierta desde el 06/10/2026');
    expect(Cobro::count())->toBe(0);
});

test('una sola caja abierta por cajero; cada cajero la suya', function () {
    cobrosCajaAbrir()->assertSessionHasNoErrors();
    cobrosCajaAbrir(monto: '10.000')->assertSessionHas('error', AbrirCaja::YA_ABIERTA);
    cobrosCajaAbrir($this->otroCajero)->assertSessionHas('status');
    expect(Caja::count())->toBe(2)->and(Caja::where('usuario_id', $this->cajero->id)->count())->toBe(1);

    // La base lo frena aunque se saltee la verificación (índice único parcial).
    $copia = cobrosCajaMia()->replicate();
    expect(fn () => DB::transaction(fn () => $copia->save()))->toThrow(QueryException::class);

    // Un monto inicial con decimales o negativo no se acepta; Gerencia no abre cajas.
    $tercero = cobrosCajaUsuario(PerfilesPredefinidos::CAJA);
    cobrosCajaAbrir($tercero, '10,5')->assertSessionHasErrors('monto_inicial');
    cobrosCajaAbrir($tercero, '-1')->assertSessionHasErrors('monto_inicial');
    cobrosCajaAbrir($this->gerente, '0')->assertForbidden();
    expect(Caja::count())->toBe(2);
});

test('cierre sin diferencia: queda cerrada y pendiente de aprobación, y ya no recibe cobros', function () {
    $prestacion = cobrosCajaPrestacion();
    $otra = cobrosCajaPrestacion();
    cobrosCajaAbrir();
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '60.000'], [$this->transferencia, '40.000']]);
    $caja = cobrosCajaMia();
    $this->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('Cerrar la caja')->assertSee('₲ 110.000')->assertSee('₲ 40.000');

    cobrosCajaCerrar($caja, [[$this->efectivo, '110.000'], [$this->transferencia, '40000']])->assertSessionHas('status', 'Caja cerrada: queda pendiente de aprobación.');

    $caja->refresh();
    $cierre = CierreCaja::with('medios')->sole();
    expect($caja->cerrada())->toBeTrue()->and($caja->cerrada_en)->not->toBeNull()
        ->and($cierre->tieneEstado(Estado::PENDIENTE))->toBeTrue()->and($cierre->motivo_diferencia)->toBeNull()
        ->and($cierre->medios->mapWithKeys(fn ($m) => [$m->medio_pago_id => [(int) $m->esperado, (int) $m->contado, (int) $m->diferencia]])->only([$this->efectivo->id, $this->transferencia->id])->all())
        ->toBe([$this->efectivo->id => [110000, 110000, 0], $this->transferencia->id => [40000, 40000, 0]]);

    // Cerrada no cobra, ni se vuelve a cerrar.
    cobrosCajaCobrar([$otra], [[$this->efectivo, '100.000']])->assertSessionHas('error', Cobrar::SIN_CAJA);
    cobrosCajaCerrar($caja, [[$this->efectivo, '110.000'], [$this->transferencia, '40000']])->assertSessionHas('error', 'Esta caja ya está cerrada.');
    expect(Cobro::count())->toBe(1)->and(CierreCaja::count())->toBe(1);
});

test('cierre con diferencia: sin motivo se rechaza; con motivo queda la diferencia', function () {
    $prestacion = cobrosCajaPrestacion();
    cobrosCajaAbrir();
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']]);
    $caja = cobrosCajaMia();

    cobrosCajaCerrar($caja, [[$this->efectivo, '145.000']])->assertSessionHas('error', 'Lo contado no coincide con lo esperado: indique el motivo de la diferencia (al menos 5 caracteres).');
    expect($caja->fresh()->abierta())->toBeTrue()->and(CierreCaja::count())->toBe(0);

    cobrosCajaCerrar($caja, [[$this->efectivo, '145.000']], 'Faltante de cambio inventado.')->assertSessionHas('status');
    $cierre = CierreCaja::with('medios')->sole();
    $efectivo = $cierre->medios->firstWhere('medio_pago_id', $this->efectivo->id);
    expect([(int) $efectivo->esperado, (int) $efectivo->contado, (int) $efectivo->diferencia])->toBe([150000, 145000, -5000])
        ->and($cierre->motivo_diferencia)->toBe('Faltante de cambio inventado.');
    $this->actingAs($this->gerente)->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('Faltante de cambio inventado.')->assertSee('-5.000');
});

test('Gerencia aprueba o devuelve con nota; la devuelta se corrige y se vuelve a cerrar, sin recibir cobros ni impedir abrir otra', function () {
    $prestacion = cobrosCajaPrestacion();
    $otra = cobrosCajaPrestacion();
    cobrosCajaAbrir();
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']]);
    $caja = cobrosCajaMia();
    cobrosCajaCerrar($caja, [[$this->efectivo, '140.000']], 'Diferencia inventada.');

    // Gerencia la ve entre las pendientes; el cajero no puede revisar (ni tiene el permiso).
    $this->actingAs($this->gerente)->get(route('admin.cajas.index'))->assertOk()->assertSee('Hay 1 caja cerrada pendiente de aprobación.')->assertSee('Todas las cajas');
    $this->actingAs($this->cajero)->post(route('admin.cajas.aprobar', $caja))->assertForbidden();

    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'x'])->assertSessionHasErrors('nota_devolucion');
    $this->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Recuente el efectivo, falta un comprobante.'])->assertSessionHas('status');
    expect($caja->fresh()->devuelta())->toBeTrue()
        ->and(CierreCaja::sole()->tieneEstado(Estado::DEVUELTO))->toBeTrue()
        ->and(CierreCaja::sole()->nota_revision)->toBe('Recuente el efectivo, falta un comprobante.')
        ->and(CierreCaja::sole()->revisado_por)->toBe($this->gerente->id);

    // La devuelta no cobra y no impide abrir la del día.
    $this->actingAs($this->cajero)->get(route('admin.cajas.index'))->assertOk()->assertSee('Gerencia devolvió el cierre de su caja del 06/10/2026: Recuente el efectivo');
    cobrosCajaCobrar([$otra], [[$this->efectivo, '100.000']])->assertSessionHas('error', Cobrar::SIN_CAJA);
    cobrosCajaAbrir(monto: '0')->assertSessionHas('status');
    $nueva = cobrosCajaMia();
    expect($nueva->id)->not->toBe($caja->id);

    // Se vuelve a cerrar: otro cierre, el anterior se conserva.
    cobrosCajaCerrar($caja, [[$this->efectivo, '150.000']])->assertSessionHas('status');
    expect($caja->fresh()->cerrada())->toBeTrue()->and(CierreCaja::where('caja_id', $caja->id)->count())->toBe(2);

    $this->actingAs($this->gerente)->post(route('admin.cajas.aprobar', $caja))->assertSessionHas('status', 'Cierre aprobado.');
    expect($caja->fresh()->tieneEstado(Estado::APROBADO))->toBeTrue()
        ->and(CierreCaja::where('caja_id', $caja->id)->latest('id')->first()->tieneEstado(Estado::APROBADO))->toBeTrue()
        ->and(CierreCaja::where('caja_id', $caja->id)->oldest('id')->first()->tieneEstado(Estado::DEVUELTO))->toBeTrue();
    // Aprobada, ya no se revisa ni se cierra.
    $this->post(route('admin.cajas.aprobar', $caja))->assertSessionHas('error', 'Esta caja no está pendiente de aprobación.');
    cobrosCajaCerrar($caja, [[$this->efectivo, '150.000']])->assertSessionHas('error', 'Esta caja ya está cerrada.');
});

test('nadie aprueba ni devuelve el cierre de su propia caja', function () {
    $gerenteCajero = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::GERENCIA)->sole(), PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole()])->create();
    cobrosCajaAbrir($gerenteCajero, '0');
    $caja = cobrosCajaMia($gerenteCajero);
    $this->actingAs($gerenteCajero)->post(route('admin.cajas.cerrar', $caja), ['contado' => MedioPago::pluck('id')->mapWithKeys(fn ($id) => [$id => '0'])->all()])->assertSessionHas('status');

    $this->post(route('admin.cajas.aprobar', $caja))->assertSessionHas('error', 'No puede aprobar ni devolver el cierre de su propia caja.');
    $this->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Nota inventada.'])->assertSessionHas('error', 'No puede aprobar ni devolver el cierre de su propia caja.');
    expect($caja->fresh()->cerrada())->toBeTrue();
});

test('una prestación cobrada no se anula (la reversión es de la fase 3b)', function () {
    $prestacion = cobrosCajaPrestacion();
    cobrosCajaAbrir();
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']]);

    $this->actingAs($this->gerente)->get(route('admin.prestaciones.show', $prestacion))->assertOk()
        ->assertSee('Cobrada')->assertSee('Está cobrada: no se puede anular mientras su cobro esté vigente.')->assertDontSee('Anular la prestación');
    $this->post(route('admin.prestaciones.anular', $prestacion), ['motivo' => 'Motivo inventado de prueba.'])->assertSessionHas('error', AnularPrestacion::COBRADA);
    expect($prestacion->fresh()->anulada())->toBeFalse();

    // Una sin cobrar sí se anula, y ya no se ofrece para cobrar.
    $otra = cobrosCajaPrestacion();
    $this->actingAs($this->gerente)->post(route('admin.prestaciones.anular', $otra), ['motivo' => 'Motivo inventado de prueba.'])->assertSessionHas('status');
    expect(Cobrar::pendientes()->whereKey($otra->id)->exists())->toBeFalse()->and($otra->fresh()->estadoCobroTexto())->toBe('');
    cobrosCajaCobrar([$otra], [[$this->efectivo, '100.000']])->assertSessionHas('error', 'Una de las prestaciones elegidas está anulada.');
});

test('la pantalla de cobro: pacientes con prestaciones sin cobrar, sus prestaciones para tildar y los medios activos', function () {
    $prestacion = cobrosCajaPrestacion();
    $this->tarjeta->desactivar();
    cobrosCajaAbrir();

    $this->get(route('admin.cobros.create'))->assertOk()->assertSee('Pacientes con prestaciones sin cobrar')->assertSee('Duarte')->assertSee('₲ 100.000');
    $this->get(route('admin.cobros.create', ['q' => 'Inexistente']))->assertOk()->assertSee('No hay pacientes con prestaciones sin cobrar para «Inexistente».', false);
    $this->get(route('admin.cobros.create', ['paciente' => $this->paciente->id]))->assertOk()
        ->assertSee('Prestaciones sin cobrar')->assertSee('value="'.$prestacion->id.'"', false)->assertSee('Confirmar el cobro')
        ->assertSee('<form method="POST" action="'.route('admin.cobros.store').'" novalidate', false)
        ->assertSee('Efectivo')->assertSee('Transferencia')->assertDontSee('Tarjeta (POS)');
    // Un medio inactivo no se acepta.
    cobrosCajaCobrar([$prestacion], [[$this->tarjeta, '100.000']])->assertSessionHas('error', 'Uno de los medios de pago elegidos no existe o no está activo.');

    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']]);
    $this->get(route('admin.cobros.create', ['paciente' => $this->paciente->id]))->assertOk()->assertSee('No tiene prestaciones sin cobrar');
    $this->get(route('admin.cobros.index'))->assertOk()->assertSee('Duarte')->assertSee('₲ 100.000');
    $this->get(route('admin.cobros.show', Cobro::sole()))->assertOk()->assertSee('Consulta')->assertSee('Efectivo');
});

test('permisos: Recepción ve el estado de cobro pero no cobra ni ve cajas; Médico sin montos; Facturación ve cobros; cada cajero sus cajas', function () {
    $prestacion = cobrosCajaPrestacion();
    cobrosCajaAbrir();
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']]);
    $caja = cobrosCajaMia();
    $cobro = Cobro::sole();

    $recepcion = cobrosCajaUsuario(PerfilesPredefinidos::RECEPCION);
    $this->actingAs($recepcion)->get(route('admin.prestaciones.index'))->assertOk()->assertSee('Cobrada');
    $this->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee('Cobrada')->assertDontSee(route('admin.cobros.show', $cobro));
    foreach ([route('admin.cobros.index'), route('admin.cobros.create'), route('admin.cobros.show', $cobro), route('admin.cajas.index'), route('admin.cajas.show', $caja)] as $url) {
        $this->get($url)->assertForbidden();
    }
    $this->post(route('admin.cobros.store'), ['paciente_id' => $this->paciente->id, 'prestaciones' => [$prestacion->id], 'lineas' => []])->assertForbidden();
    $this->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertForbidden();
    $this->get('/dashboard')->assertOk()->assertDontSee(route('admin.cobros.index'))->assertDontSee(route('admin.cajas.index'));

    // El Médico no tiene los módulos económicos: ni montos, ni cobros, ni cajas.
    $this->actingAs($this->medico);
    foreach ([route('admin.cobros.index'), route('admin.cobros.show', $cobro), route('admin.cajas.index'), route('admin.cajas.show', $caja), route('admin.prestaciones.index')] as $url) {
        $this->get($url)->assertForbidden();
    }
    $this->get('/dashboard')->assertOk()->assertDontSee(route('admin.cobros.index'))->assertDontSee('₲ 100.000');

    $facturacion = cobrosCajaUsuario(PerfilesPredefinidos::FACTURACION);
    $this->actingAs($facturacion)->get(route('admin.cobros.index'))->assertOk()->assertSee('₲ 100.000');
    $this->get(route('admin.cobros.show', $cobro))->assertOk()->assertDontSee(route('admin.cajas.show', $caja));
    $this->get(route('admin.cobros.create'))->assertForbidden();
    $this->get(route('admin.cajas.index'))->assertForbidden();

    // Otro cajero no ve la caja ajena; Gerencia ve todas pero no cobra ni abre.
    $this->actingAs($this->otroCajero)->get(route('admin.cajas.show', $caja))->assertForbidden();
    $this->get(route('admin.cajas.index'))->assertOk()->assertSee('Mis cajas')->assertDontSee(route('admin.cajas.show', $caja));
    $this->actingAs($this->gerente)->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('₲ 150.000');
    $this->get(route('admin.cobros.create'))->assertForbidden();
    $this->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertForbidden();
    $this->get(route('admin.cajas.show', $caja))->assertDontSee('Revisar el cierre'); // todavía abierta
});

test('auditoría de la apertura, el cobro, el cierre y la aprobación; sin el módulo no se ven los montos', function () {
    $prestacion = cobrosCajaPrestacion();
    cobrosCajaAbrir(monto: '12.345');
    $caja = cobrosCajaMia();
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']]);
    $cobro = Cobro::with(['lineas', 'prestaciones'])->sole();
    cobrosCajaCerrar($caja, [[$this->efectivo, '112.345']]);
    $this->actingAs($this->gerente)->post(route('admin.cajas.aprobar', $caja), ['nota' => 'Todo en orden.']);
    $cierre = CierreCaja::sole();

    $log = fn (string $tabla, int $id, AccionAuditoria $accion = AccionAuditoria::CREAR) => LogAuditoria::where('tabla_afectada', $tabla)
        ->where('registro_afectado_id', (string) $id)->where('accion', $accion->value)->orderBy('id')->get();
    expect($log('cajas', $caja->id)->sole()->detalle)->toBe('Apertura de caja')
        ->and($log('cobros', $cobro->id)->sole()->detalle)->toBe('Cobro de 1 prestación')
        ->and($log('cobro_prestaciones', $cobro->prestaciones->sole()->id)->sole()->detalle)->toBe('Cobro de 1 prestación')
        ->and($log('lineas_cobro', $cobro->lineas->sole()->id)->sole()->detalle)->toBe('Cobro de 1 prestación')
        ->and($log('cierres_caja', $cierre->id)->sole()->detalle)->toBe("Cierre de la caja {$caja->id}")
        ->and($log('cajas', $caja->id, AccionAuditoria::EDITAR)->pluck('detalle')->all())->toBe(['Cierre de caja', 'Cierre aprobado'])
        ->and($log('cierres_caja', $cierre->id, AccionAuditoria::EDITAR)->sole()->detalle)->toBe('Nota: Todo en orden.')
        ->and($log('cierres_caja', $cierre->id, AccionAuditoria::EDITAR)->sole()->usuario_id)->toBe($this->gerente->id);

    // El Auditor ve los eventos, sin los montos; Facturación ve los del cobro pero no los de la caja.
    $auditor = cobrosCajaUsuario(PerfilesPredefinidos::AUDITOR);
    $this->actingAs($auditor)->get(route('admin.auditoria.show', $log('cajas', $caja->id)->sole()))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Cajas')->assertDontSee('12345');
    $this->get(route('admin.auditoria.show', $log('cobros', $cobro->id)->sole()))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Cobros')->assertDontSee('100000');
    $this->actingAs($this->gerente)->get(route('admin.auditoria.show', $log('cajas', $caja->id)->sole()))->assertOk()->assertDontSee('Contenido económico')->assertSee('12345');
});

test('cajas, cierres y cobros no se editan ni se borran', function () {
    $prestacion = cobrosCajaPrestacion();
    cobrosCajaAbrir();
    cobrosCajaCobrar([$prestacion], [[$this->efectivo, '100.000']]);
    $cobro = Cobro::with(['lineas', 'prestaciones'])->sole();
    $caja = cobrosCajaMia();

    expect(fn () => $cobro->update(['total' => 1]))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $cobro->lineas->first()->update(['monto' => 1]))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $cobro->prestaciones->first()->update(['monto' => 1]))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $cobro->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $caja->update(['monto_inicial' => 1]))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $caja->forceFill(['estado_id' => Estado::idDe(Estado::APROBADO)])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => MovimientoCaja::first()->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $caja->delete())->toThrow(RegistroEconomicoInmutable::class);
});

test('el servicio rechaza un cobro sin prestaciones o sin pagos', function () {
    cobrosCajaAbrir();
    expect(fn () => Cobrar::ejecutar($this->cajero, $this->paciente->id, [], [['medio_pago_id' => $this->efectivo->id, 'monto' => 1, 'referencia' => null]]))
        ->toThrow(AccionRechazada::class, 'Elija al menos una prestación para cobrar.');
    $prestacion = cobrosCajaPrestacion();
    expect(fn () => Cobrar::ejecutar($this->cajero, $this->paciente->id, [$prestacion->id], []))->toThrow(AccionRechazada::class, 'Indique al menos un medio de pago.');
});
