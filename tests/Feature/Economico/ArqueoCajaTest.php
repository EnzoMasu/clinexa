<?php

/*
 * Arqueo de caja (docs/cobros-caja.md): estados Abierta, Pendiente de recepción, Devuelto por diferencias y Recibido; Gerencia acepta o
 * rechaza (con observación obligatoria); el cajero corrige el medio y el comprobante de sus cobros solo en un arqueo devuelto y lo
 * reenvía; la observación del cierre solo con diferencia; bloqueo de cobros y de la apertura; la sucursal de la caja; la impresión.
 * Datos inventados.
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Caja;
use App\Models\CierreCaja;
use App\Models\Clinica;
use App\Models\Cobro;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LineaCobro;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\CerrarCaja;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\CorregirComprobante;
use App\Support\Cobros\PdfArqueo;
use App\Support\Cobros\RevisarCierre;
use App\Support\Facturacion\EmitirFactura;
use App\Support\Facturacion\GestionTimbrados;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Ruc;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    hcEscenario(); // "hoy": martes 06/10/2026
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $this->proc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->cajero = arqueoCajaUsuario(PerfilesPredefinidos::CAJA, ['apellidos' => 'Cáceres', 'nombres' => 'Lidia']);
    $this->otroCajero = arqueoCajaUsuario(PerfilesPredefinidos::CAJA, ['apellidos' => 'Ortiz', 'nombres' => 'Marta']);
    $this->gerente = arqueoCajaUsuario(PerfilesPredefinidos::GERENCIA, ['apellidos' => 'Gómez', 'nombres' => 'Ana']);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $this->proc->id, null, 100000, Carbon::parse('2026-10-01'));
    $this->medio = fn (string $codigo) => MedioPago::where('codigo', $codigo)->sole();
});

afterEach(fn () => Carbon::setTestNow());

function arqueoCajaUsuario(string $codigo, array $persona = []): User
{
    $factory = User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()]);

    return ($persona ? $factory->conPersona($persona) : $factory)->create();
}

function arqueoCajaPrestacion(): Prestacion
{
    test()->actingAs(test()->medico);
    hcConsulta();

    return Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
}

function arqueoCajaAbrir(?User $cajero = null, string $monto = '50.000', array $extra = [])
{
    return test()->actingAs($cajero ?? test()->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => $monto, ...$extra]);
}

function arqueoCajaMia(?User $cajero = null): Caja
{
    return Caja::where('usuario_id', ($cajero ?? test()->cajero)->id)->latest('id')->firstOrFail();
}

/** Cobra una prestación nueva de 100.000 con un pago de ese medio y comprobante. */
function arqueoCajaCobrar(string $codigo, ?string $comprobante = null, ?User $cajero = null)
{
    $prestacion = arqueoCajaPrestacion();

    return test()->actingAs($cajero ?? test()->cajero)->from(route('admin.cobros.create'))->post(route('admin.cobros.store'), [
        'paciente_id' => test()->paciente->id,
        'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => (test()->medio)($codigo)->id, 'monto' => '100.000', 'referencia' => $comprobante]],
    ]);
}

/** Declara lo contado: lo esperado de cada medio, salvo lo indicado [codigo => monto]. */
function arqueoCajaCerrar(Caja $caja, array $distinto = [], ?string $motivo = null, ?User $cajero = null)
{
    $esperado = $caja->esperadoPorMedio();
    $contado = CerrarCaja::mediosADeclarar($caja)->mapWithKeys(fn (MedioPago $medio) => [$medio->id => (string) ($esperado[$medio->id] ?? 0)])->all();
    foreach ($distinto as $codigo => $monto) {
        $contado[(test()->medio)($codigo)->id] = $monto;
    }

    return test()->actingAs($cajero ?? test()->cajero)->from(route('admin.cajas.show', $caja))->post(route('admin.cajas.cerrar', $caja), ['confirmar' => '1', 'contado' => $contado, 'motivo' => $motivo]);
}

function arqueoCajaCorregir(LineaCobro $linea, array $datos, ?User $usuario = null)
{
    return test()->actingAs($usuario ?? test()->cajero)->from(route('admin.cobros.show', $linea->cobro_id))
        ->post(route('admin.cobros.corregir-comprobante', [$linea->cobro_id, $linea]), $datos);
}

test('transiciones: Abierta → Pendiente de recepción → Devuelto por diferencias → Pendiente → Recibido, con el historial de cada vuelta', function () {
    arqueoCajaAbrir()->assertSessionHas('status');
    $caja = arqueoCajaMia();
    expect($caja->etiquetaEstado())->toBe('Abierta');
    arqueoCajaCobrar(MedioPago::EFECTIVO)->assertSessionHas('status');

    // Abierta: no se acepta ni se rechaza.
    $this->actingAs($this->gerente)->post(route('admin.cajas.aprobar', $caja))->assertSessionHas('error', 'Esta caja no está pendiente de recepción.');

    arqueoCajaCerrar($caja, [MedioPago::EFECTIVO => '140.000'], 'Faltan diez mil.')->assertSessionHas('status', 'Caja cerrada: queda pendiente de recepción.');
    expect($caja->fresh()->etiquetaEstado())->toBe('Pendiente de recepción');

    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Recuente el efectivo, por favor.'])->assertSessionHas('status');
    expect($caja->fresh()->etiquetaEstado())->toBe('Devuelto por diferencias');
    // Devuelta: no se acepta hasta que se reenvíe.
    $this->post(route('admin.cajas.aprobar', $caja))->assertSessionHas('error', 'Esta caja no está pendiente de recepción.');

    arqueoCajaCerrar($caja, [MedioPago::EFECTIVO => '150.000'])->assertSessionHas('status');
    expect($caja->fresh()->etiquetaEstado())->toBe('Pendiente de recepción');

    $this->actingAs($this->gerente)->post(route('admin.cajas.aprobar', $caja))->assertSessionHas('status', 'Arqueo recibido.');
    expect($caja->fresh()->etiquetaEstado())->toBe('Recibido');
    // Recibida: ya no se reenvía, ni se rechaza.
    arqueoCajaCerrar($caja)->assertSessionHas('error', 'Esta caja ya está cerrada.');
    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Otra observación.'])->assertSessionHas('error');

    // El historial: cada intento con lo declarado, quién devolvió, cuándo y el motivo.
    [$primero, $segundo] = CierreCaja::with('medios')->orderBy('id')->get()->all();
    $efectivo = ($this->medio)(MedioPago::EFECTIVO)->id;
    expect($primero->tieneEstado(Estado::DEVUELTO))->toBeTrue()->and($primero->revisado_por)->toBe($this->gerente->id)
        ->and($primero->revisado_en)->not->toBeNull()->and($primero->nota_revision)->toBe('Recuente el efectivo, por favor.')
        ->and((int) $primero->medios->firstWhere('medio_pago_id', $efectivo)->contado)->toBe(140000)
        ->and($primero->motivo_diferencia)->toBe('Faltan diez mil.')
        ->and($segundo->tieneEstado(Estado::APROBADO))->toBeTrue()
        ->and((int) $segundo->medios->firstWhere('medio_pago_id', $efectivo)->contado)->toBe(150000);
    $this->actingAs($this->cajero)->get(route('admin.cajas.show', $caja))->assertOk()
        ->assertSee('Historial del arqueo')->assertSee('Intento 1: Devuelto por diferencias')->assertSee('Intento 2: Recibido')
        ->assertSee('Motivo de la devolución:')->assertSee('Recuente el efectivo, por favor.')->assertSee('por Gómez');
});

test('rechazar sin observación falla (pantalla y servicio); aceptar no la pide', function () {
    arqueoCajaAbrir();
    $caja = arqueoCajaMia();
    arqueoCajaCerrar($caja);

    foreach (['', '   ', 'abc'] as $nota) {
        $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => $nota])->assertSessionHasErrors('nota_devolucion');
    }
    expect(fn () => RevisarCierre::devolver($this->gerente, $caja, '  x  '))->toThrow(AccionRechazada::class, RevisarCierre::FALTA_MOTIVO)
        ->and($caja->fresh()->cerrada())->toBeTrue()->and(CierreCaja::sole()->tieneEstado(Estado::PENDIENTE))->toBeTrue();

    $this->post(route('admin.cajas.aprobar', $caja))->assertSessionHas('status');
    expect($caja->fresh()->recibida())->toBeTrue()->and(CierreCaja::sole()->nota_revision)->toBeNull();
});

test('observación: obligatoria solo con diferencia; sin diferencia se descarta sin error', function () {
    arqueoCajaAbrir();
    $caja = arqueoCajaMia();
    arqueoCajaCobrar(MedioPago::TRANSFERENCIA, 'TRF-1')->assertSessionHas('status');

    // El formulario viene precargado con lo esperado, la diferencia y el total.
    $this->actingAs($this->cajero)->get(route('admin.cajas.cierre', $caja))->assertOk()
        ->assertSee('value="50.000"', false)->assertSee('value="100.000"', false)->assertSee('Observación (obligatoria solo si hay diferencia)')
        ->assertSee('x-data="arqueoCaja"', false);

    // Con diferencia y sin observación (o muy corta), no cierra.
    arqueoCajaCerrar($caja, [MedioPago::TRANSFERENCIA => '90.000'])->assertSessionHas('error', CerrarCaja::FALTA_MOTIVO);
    arqueoCajaCerrar($caja, [MedioPago::TRANSFERENCIA => '90.000'], 'abc')->assertSessionHas('error', CerrarCaja::FALTA_MOTIVO);
    expect($caja->fresh()->abierta())->toBeTrue()->and(CierreCaja::count())->toBe(0);

    // Sin diferencia, lo escrito se descarta y no hay error.
    arqueoCajaCerrar($caja, [], 'Algo escrito sin diferencia.')->assertSessionHasNoErrors()->assertSessionHas('status');
    expect(CierreCaja::sole()->motivo_diferencia)->toBeNull();

    // Con diferencia y observación, queda.
    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Revise la transferencia.']);
    arqueoCajaCerrar($caja, [MedioPago::TRANSFERENCIA => '90.000'], 'Faltan diez mil de la transferencia.')->assertSessionHas('status');
    $ultimo = CierreCaja::latest('id')->first();
    expect($ultimo->motivo_diferencia)->toBe('Faltan diez mil de la transferencia.')
        ->and((int) $ultimo->medios->firstWhere('medio_pago_id', ($this->medio)(MedioPago::TRANSFERENCIA)->id)->diferencia)->toBe(-10000);
});

test('bloqueo: pendiente o devuelta no cobra; con una devuelta no se abre otra, con una pendiente sí', function () {
    arqueoCajaAbrir();
    $caja = arqueoCajaMia();
    arqueoCajaCerrar($caja);

    // Pendiente: no cobra (la caja ya no está abierta), pero se puede abrir otra.
    arqueoCajaCobrar(MedioPago::EFECTIVO)->assertSessionHas('error', Cobrar::SIN_CAJA);
    expect(fn () => Cobrar::cajaParaCobrar($this->cajero))->toThrow(AccionRechazada::class, Cobrar::SIN_CAJA);

    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Falta un comprobante.']);
    // Devuelta: no cobra ni deja abrir otra (pantalla y servicio).
    arqueoCajaAbrir()->assertSessionHas('error', AbrirCaja::CON_DEVUELTA);
    expect(fn () => AbrirCaja::ejecutar($this->cajero, 0))->toThrow(AccionRechazada::class, AbrirCaja::CON_DEVUELTA)
        ->and(Caja::count())->toBe(1);
    $this->actingAs($this->cajero)->get(route('admin.cajas.index'))->assertOk()->assertSee(AbrirCaja::CON_DEVUELTA)->assertDontSee('Abrir mi caja');
    arqueoCajaCobrar(MedioPago::EFECTIVO)->assertSessionHas('error', Cobrar::SIN_CAJA);
    expect(Cobro::count())->toBe(0);

    // Reenviada (pendiente), ya abre otra; la devuelta de otro cajero no lo afecta.
    arqueoCajaCerrar($caja)->assertSessionHas('status');
    arqueoCajaAbrir()->assertSessionHas('status');
    arqueoCajaAbrir($this->otroCajero)->assertSessionHas('status');
    expect(Caja::count())->toBe(3);
});

test('corrección en un arqueo devuelto: medio y comprobante, lo esperado se recalcula, auditada con el valor anterior y el nuevo', function () {
    arqueoCajaAbrir(monto: '0');
    $caja = arqueoCajaMia();
    arqueoCajaCobrar(MedioPago::TARJETA, 'VOU-1')->assertSessionHas('status');
    $linea = LineaCobro::sole();
    [$tarjeta, $transferencia, $efectivo] = [($this->medio)(MedioPago::TARJETA), ($this->medio)(MedioPago::TRANSFERENCIA), ($this->medio)(MedioPago::EFECTIVO)];

    // Abierta: no se corrige (ni se ofrece).
    $this->actingAs($this->cajero)->get(route('admin.cobros.show', $linea->cobro_id))->assertOk()->assertDontSee('Guardar la corrección');
    arqueoCajaCorregir($linea, ['medio_pago_id' => $transferencia->id, 'comprobante' => 'TRF-9', 'motivo' => 'Era transferencia.'])
        ->assertSessionHas('error', CorregirComprobante::NO_DEVUELTA);
    // Pendiente de recepción: tampoco.
    arqueoCajaCerrar($caja, [MedioPago::TARJETA => '0'], 'No encuentro el voucher.');
    arqueoCajaCorregir($linea, ['medio_pago_id' => $transferencia->id, 'comprobante' => 'TRF-9', 'motivo' => 'Era transferencia.'])
        ->assertSessionHas('error', CorregirComprobante::NO_DEVUELTA);
    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'El pago fue por transferencia.']);

    // Devuelta: se ofrece a su cajero.
    $this->actingAs($this->cajero)->get(route('admin.cobros.show', $linea->cobro_id))->assertOk()->assertSee('Guardar la corrección');
    $this->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('Arqueo devuelto por diferencias. Puede corregir el medio de pago');

    // Sin motivo, o al nuevo medio sin el comprobante que exige: no cambia.
    arqueoCajaCorregir($linea, ['medio_pago_id' => $transferencia->id, 'comprobante' => 'TRF-9', 'motivo' => ''])->assertSessionHasErrors('motivo');
    arqueoCajaCorregir($linea, ['medio_pago_id' => $transferencia->id, 'comprobante' => '', 'motivo' => 'Era transferencia.'])
        ->assertSessionHas('error', $transferencia->mensajeComprobanteFaltante());
    arqueoCajaCorregir($linea, ['medio_pago_id' => $tarjeta->id, 'comprobante' => 'VOU-1', 'motivo' => 'Nada cambia aquí.'])->assertSessionHas('error');
    expect($linea->fresh()->medio_pago_id)->toBe($tarjeta->id);

    // Cambio de medio: la línea y su movimiento pasan al nuevo; el monto y el cobro no cambian; lo esperado se recalcula.
    arqueoCajaCorregir($linea, ['medio_pago_id' => $transferencia->id, 'comprobante' => 'TRF-9', 'motivo' => 'Era transferencia.'])->assertSessionHas('status', 'Pago corregido.');
    $linea->refresh();
    expect($linea->medio_pago_id)->toBe($transferencia->id)->and($linea->referencia)->toBe('TRF-9')->and((int) $linea->monto)->toBe(100000)
        ->and(Cobro::sole()->revertido())->toBeFalse()
        ->and(MovimientoCaja::where('linea_cobro_id', $linea->id)->sole()->medio_pago_id)->toBe($transferencia->id)
        ->and($caja->fresh()->esperadoPorMedio())->toBe([$transferencia->id => 100000]);
    $this->get(route('admin.cajas.cierre', $caja))->assertOk()->assertSee('value="100.000"', false);

    $log = LogAuditoria::where('tabla_afectada', 'lineas_cobro')->where('registro_afectado_id', (string) $linea->id)->where('accion', AccionAuditoria::EDITAR->value)->sole();
    expect($log->usuario_id)->toBe($this->cajero->id)->and($log->fecha_hora)->not->toBeNull()
        ->and($log->valor_anterior)->toBe(['medio_pago_id' => $tarjeta->id, 'referencia' => 'VOU-1'])
        ->and($log->valor_nuevo)->toBe(['medio_pago_id' => $transferencia->id, 'referencia' => 'TRF-9'])
        ->and($log->detalle)->toBe("Corrección del medio de pago del cobro {$linea->cobro_id}. Motivo: Era transferencia.");

    // Solo el comprobante.
    arqueoCajaCorregir($linea, ['medio_pago_id' => $transferencia->id, 'comprobante' => 'TRF-10', 'motivo' => 'Error de tipeo.'])->assertSessionHas('status');
    expect(LogAuditoria::where('tabla_afectada', 'lineas_cobro')->where('accion', AccionAuditoria::EDITAR->value)->latest('id')->first()->detalle)
        ->toBe("Corrección del comprobante del cobro {$linea->cobro_id}. Motivo: Error de tipeo.");

    // A efectivo: el comprobante queda vacío (lo escrito se descarta).
    arqueoCajaCorregir($linea, ['medio_pago_id' => $efectivo->id, 'comprobante' => 'ALGO', 'motivo' => 'Al final fue efectivo.'])->assertSessionHas('status');
    expect($linea->fresh()->referencia)->toBeNull()->and($caja->fresh()->esperadoPorMedio())->toBe([$efectivo->id => 100000]);

    // Se reenvía con el nuevo esperado, sin diferencia.
    arqueoCajaCerrar($caja)->assertSessionHas('status');
    expect((int) CierreCaja::latest('id')->first()->medios->firstWhere('medio_pago_id', $efectivo->id)->esperado)->toBe(100000);
});

test('la corrección: solo su cajero, solo a un medio activo, nunca el monto, ni en un cobro revertido o una caja recibida', function () {
    arqueoCajaAbrir(monto: '0');
    $caja = arqueoCajaMia();
    arqueoCajaCobrar(MedioPago::TARJETA, 'VOU-1')->assertSessionHas('status');
    $linea = LineaCobro::sole();
    $transferencia = ($this->medio)(MedioPago::TRANSFERENCIA);
    $datos = ['medio_pago_id' => $transferencia->id, 'comprobante' => 'TRF-9', 'motivo' => 'Era transferencia.'];
    arqueoCajaCerrar($caja);
    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Revise los pagos.']);

    // Otro cajero (aunque tenga el permiso) y el Administrador: no. Gerencia: sin el permiso.
    arqueoCajaCorregir($linea, $datos, $this->otroCajero)->assertSessionHas('error', CorregirComprobante::NO_ES_SU_CAJA);
    $admin = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->sole()])->create();
    arqueoCajaCorregir($linea, $datos, $admin)->assertSessionHas('error', CorregirComprobante::NO_ES_SU_CAJA);
    arqueoCajaCorregir($linea, $datos, $this->gerente)->assertForbidden();

    // Un medio inactivo no se elige.
    $transferencia->desactivar();
    arqueoCajaCorregir($linea, $datos)->assertSessionHas('error', 'Elija un medio de pago activo.');
    $transferencia->forceFill(['estado_id' => Estado::idDe(Estado::ACTIVO)])->save();

    // El monto y el medio no se cambian por fuera de la corrección; el movimiento tampoco.
    expect(fn () => $linea->forceFill(['monto' => 1])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $linea->fresh()->forceFill(['medio_pago_id' => $transferencia->id])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => MovimientoCaja::sole()->forceFill(['medio_pago_id' => $transferencia->id])->save())->toThrow(RegistroEconomicoInmutable::class);

    // Recibida: ya no.
    arqueoCajaCerrar($caja);
    $this->actingAs($this->gerente)->post(route('admin.cajas.aprobar', $caja));
    arqueoCajaCorregir($linea, $datos)->assertSessionHas('error', CorregirComprobante::NO_DEVUELTA);
    expect($linea->fresh()->medio_pago_id)->toBe(($this->medio)(MedioPago::TARJETA)->id);
});

test('sucursal: con una sola activa se asigna sola; con más, el cajero la elige entre las activas', function () {
    arqueoCajaAbrir()->assertSessionHas('status');
    expect(arqueoCajaMia()->sucursal_id)->toBe(Sucursal::principal()->id);
    $this->actingAs($this->cajero)->get(route('admin.cajas.show', arqueoCajaMia()))->assertSee(Sucursal::principal()->nombre);

    $otra = Sucursal::create(['nombre' => 'Sucursal Norte de prueba', 'direccion' => 'Calle Inventada 9', 'telefono' => '0900']);
    $inactiva = Sucursal::create(['nombre' => 'Sucursal Cerrada de prueba', 'direccion' => 'Calle Inventada 10', 'telefono' => '0901']);
    $inactiva->desactivar();
    $this->actingAs($this->otroCajero)->get(route('admin.cajas.index'))->assertOk()->assertSee('Sucursal (obligatorio)')
        ->assertSee('Sucursal Norte de prueba')->assertDontSee('Sucursal Cerrada de prueba');
    arqueoCajaAbrir($this->otroCajero)->assertSessionHas('error', AbrirCaja::ELIJA_SUCURSAL);
    arqueoCajaAbrir($this->otroCajero, extra: ['sucursal_id' => $inactiva->id])->assertSessionHas('error', AbrirCaja::ELIJA_SUCURSAL);
    arqueoCajaAbrir($this->otroCajero, extra: ['sucursal_id' => $otra->id])->assertSessionHas('status');
    expect(arqueoCajaMia($this->otroCajero)->sucursal_id)->toBe($otra->id);

    // La sucursal de una caja no cambia.
    expect(fn () => arqueoCajaMia($this->otroCajero)->forceFill(['sucursal_id' => Sucursal::principal()->id])->save())->toThrow(RegistroEconomicoInmutable::class);
});

test('la migración: las cajas existentes toman la única sucursal; con más de una se detiene sin cambiar nada', function () {
    $migracion = require database_path('migrations/2026_11_05_100001_sucursal_de_la_caja.php');
    arqueoCajaAbrir();
    arqueoCajaAbrir($this->otroCajero);
    $migracion->down();
    expect(DB::table('cajas')->count())->toBe(2);

    $migracion->up();
    expect(DB::table('cajas')->whereNull('sucursal_id')->count())->toBe(0)
        ->and(DB::table('cajas')->pluck('sucursal_id')->unique()->all())->toBe([Sucursal::sole()->id]);
    // Sigue valiendo una sola abierta por cajero, y otra caja después de cerrar (el índice parcial se conserva).
    arqueoCajaAbrir()->assertSessionHas('error', AbrirCaja::YA_ABIERTA);
    arqueoCajaCerrar(arqueoCajaMia())->assertSessionHas('status');
    arqueoCajaAbrir()->assertSessionHas('status');

    $migracion->down();
    Sucursal::create(['nombre' => 'Sucursal Sur de prueba', 'direccion' => 'Calle Inventada 11', 'telefono' => '0902']);
    expect(fn () => $migracion->up())->toThrow(RuntimeException::class, 'Hay cajas y 2 sucursales');
    expect(Schema::hasColumn('cajas', 'sucursal_id'))->toBeFalse();
    DB::table('sucursales')->where('nombre', 'Sucursal Sur de prueba')->delete();
    $migracion->up();
});

test('la impresión del arqueo: datos, facturas, cada transacción con su comprobante, revertidos, devoluciones, totales, observación y firmas', function () {
    // Con facturación: la clínica, el hito y un timbrado.
    $clinica = Clinica::create(['tipo_contribuyente' => Clinica::SOCIEDAD, 'razon_social' => 'Clínica de Prueba S.A.', 'nombre_fantasia' => 'Plenitud de Prueba',
        'ruc' => Ruc::conDigito('80999001'), 'actividad_economica' => 'Servicios de salud (prueba)', 'direccion_fiscal' => 'Calle Inventada 123']);
    Sucursal::query()->update(['clinica_id' => $clinica->id]);
    DB::table('hitos_sistema')->where('codigo', EmitirFactura::HITO)->update(['fecha_hora' => '2026-10-01 00:00:00']);
    GestionTimbrados::cargar($this->gerente, ['sucursal_id' => Sucursal::principal()->id, 'numero' => '12345678', 'vigente_desde' => '2026-10-01',
        'vigente_hasta' => '2027-09-30', 'establecimiento' => '001', 'punto_expedicion' => '001', 'numero_inicial' => 1, 'numero_final' => 9999999]);

    arqueoCajaAbrir(monto: '200.000');
    $caja = arqueoCajaMia();
    arqueoCajaCobrar(MedioPago::TARJETA, 'VOU-777')->assertSessionHas('status');
    arqueoCajaCobrar(MedioPago::CHEQUE, 'CHQ-555')->assertSessionHas('status');
    arqueoCajaCobrar(MedioPago::EFECTIVO)->assertSessionHas('status');
    // La tercera se anula en el plazo: su cobro queda revertido y el egreso sale de esta caja.
    $tercera = Cobro::latest('id')->first();
    $this->actingAs($this->cajero)->post(route('admin.facturas.anular', $tercera->factura_id), ['motivo_anulacion_id' => DB::table('motivos_anulacion_factura')->value('id'), 'detalle' => 'Datos mal cargados.']);

    // Abierta: todavía no hay arqueo.
    $this->actingAs($this->cajero)->get(route('admin.cajas.show', $caja))->assertDontSee('Imprimir el arqueo');
    expect(fn () => PdfArqueo::html($caja))->toThrow(AccionRechazada::class, PdfArqueo::SIN_ARQUEO);

    arqueoCajaCerrar($caja, [MedioPago::TARJETA => '90.000'], 'Falta un voucher de diez mil.')->assertSessionHas('status');
    $html = PdfArqueo::html($caja->fresh());
    expect($html)->toContain('Arqueo de la caja N.º '.$caja->id)->toContain('06/10/2026')->toContain('Cáceres')->toContain(Sucursal::principal()->nombre)
        ->toContain('Facturas cobradas (3)')->toContain(Cobro::oldest('id')->first()->factura->numero_completo)
        ->toContain('VOU-777')->toContain('CHQ-555')->toContain('(revertido)')
        ->toContain('Devoluciones de cobros revertidos hechas desde esta caja (1)')
        ->toContain('Totales por medio de pago')->toContain('₲ -10.000')->toContain('Falta un voucher de diez mil.')
        ->toContain('Firma del cajero')->toContain('Firma del gerente')->not->toContain('Diagnóstico');

    // Sin diferencia, la observación sale en blanco.
    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Busque el voucher.']);
    arqueoCajaCerrar($caja, [], 'Esto se descarta.')->assertSessionHas('status');
    expect(PdfArqueo::html($caja->fresh()))->toContain('<div class="observacion"></div>')->not->toContain('Esto se descarta.');

    // El PDF: el cajero el suyo y Gerencia cualquiera; otro cajero no. Queda en Auditoría.
    $this->actingAs($this->cajero)->get(route('admin.cajas.arqueo', $caja))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->gerente)->get(route('admin.cajas.arqueo', $caja))->assertOk();
    $this->actingAs($this->otroCajero)->get(route('admin.cajas.arqueo', $caja))->assertForbidden();
    expect(LogAuditoria::where('tabla_afectada', 'cajas')->where('accion', AccionAuditoria::EXPORTAR->value)->count())->toBe(2);
});

test('cierre: el detalle ya no trae el formulario, solo el acceso a la pantalla de cierre', function () {
    arqueoCajaAbrir();
    $caja = arqueoCajaMia();

    $this->actingAs($this->cajero)->get(route('admin.cajas.show', $caja))->assertOk()
        ->assertDontSee('x-data="arqueoCaja"', false)->assertDontSee(route('admin.cajas.cerrar', $caja), false)
        ->assertDontSee('name="contado[', false)->assertSee(route('admin.cajas.cierre', $caja), false);
    // Gerencia (que no es su cajero) no ve el acceso y no entra a la pantalla.
    $this->actingAs($this->gerente)->get(route('admin.cajas.show', $caja))->assertDontSee(route('admin.cajas.cierre', $caja), false);
    $this->actingAs($this->gerente)->get(route('admin.cajas.cierre', $caja))->assertForbidden();
    $this->actingAs($this->otroCajero)->get(route('admin.cajas.cierre', $caja))->assertForbidden();
});

test('cierre: la pantalla pide confirmación y sin confirmar no se cierra', function () {
    arqueoCajaAbrir();
    $caja = arqueoCajaMia();
    $contado = CerrarCaja::mediosADeclarar($caja)->mapWithKeys(fn (MedioPago $medio) => [$medio->id => (string) ($caja->esperadoPorMedio()[$medio->id] ?? 0)])->all();

    // Paso previo: muestra el aviso y los botones, y no cierra nada.
    $this->actingAs($this->cajero)->post(route('admin.cajas.cierre.revisar', $caja), ['contado' => $contado])->assertOk()
        ->assertSee('la caja deja de recibir cobros y queda pendiente de recepción del gerente')->assertSee('Cancelar')->assertSee('Cerrar la caja')
        ->assertSee('name="confirmar"', false);
    expect($caja->fresh()->abierta())->toBeTrue()->and(CierreCaja::count())->toBe(0);

    // Enviar el cierre sin la confirmación: vuelve a la pantalla de cierre y no cierra.
    $this->actingAs($this->cajero)->post(route('admin.cajas.cerrar', $caja), ['contado' => $contado])
        ->assertRedirect(route('admin.cajas.cierre', $caja))->assertSessionHas('error');
    expect($caja->fresh()->abierta())->toBeTrue()->and(CierreCaja::count())->toBe(0);

    // Con la confirmación, cierra.
    $this->post(route('admin.cajas.cerrar', $caja), ['contado' => $contado, 'confirmar' => '1'])->assertRedirect(route('admin.cajas.show', $caja))->assertSessionHas('status');
    expect($caja->fresh()->cerrada())->toBeTrue()->and(CierreCaja::count())->toBe(1);
});

test('cierre: las reglas del cierre se ven en el paso previo (diferencia sin observación no avanza)', function () {
    arqueoCajaAbrir();
    $caja = arqueoCajaMia();
    $contado = CerrarCaja::mediosADeclarar($caja)->mapWithKeys(fn (MedioPago $medio) => [$medio->id => (string) ($caja->esperadoPorMedio()[$medio->id] ?? 0)])->all();
    $contado[($this->medio)(MedioPago::EFECTIVO)->id] = '40.000';

    $this->actingAs($this->cajero)->post(route('admin.cajas.cierre.revisar', $caja), ['contado' => $contado])
        ->assertRedirect(route('admin.cajas.cierre', $caja))->assertSessionHas('error', CerrarCaja::FALTA_MOTIVO);
    $this->post(route('admin.cajas.cierre.revisar', $caja), ['contado' => $contado, 'motivo' => 'Faltan diez mil.'])->assertOk()->assertSee('Faltan diez mil.');
    expect($caja->fresh()->abierta())->toBeTrue();
});

test('cierre: el reenvío de un arqueo devuelto usa la misma pantalla, con la observación de Gerencia', function () {
    arqueoCajaAbrir();
    $caja = arqueoCajaMia();
    arqueoCajaCerrar($caja)->assertSessionHas('status');
    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Revise el efectivo.'])->assertSessionHas('status');

    $this->actingAs($this->cajero)->get(route('admin.cajas.cierre', $caja))->assertOk()->assertSee('Observación de Gerencia: Revise el efectivo.')->assertSee('x-data="arqueoCaja"', false);
    $this->get(route('admin.cajas.show', $caja))->assertSee('Reenviar el arqueo')->assertDontSee('x-data="arqueoCaja"', false);
    arqueoCajaCerrar($caja)->assertSessionHas('status');
    expect($caja->fresh()->cerrada())->toBeTrue()->and(CierreCaja::count())->toBe(2);
});

test('cierre: los permisos no cambian (sin EDITAR sobre CAJAS no hay pantalla ni cierre)', function () {
    arqueoCajaAbrir();
    $caja = arqueoCajaMia();
    $ruta = fn (string $nombre) => route($nombre, $caja);
    $sinPermiso = arqueoCajaUsuario(PerfilesPredefinidos::RECEPCION);

    $this->actingAs($sinPermiso)->get($ruta('admin.cajas.cierre'))->assertForbidden();
    $this->actingAs($sinPermiso)->post($ruta('admin.cajas.cierre.revisar'), [])->assertForbidden();
    $this->actingAs($sinPermiso)->post($ruta('admin.cajas.cerrar'), ['confirmar' => '1'])->assertForbidden();
    expect($caja->fresh()->abierta())->toBeTrue();
});
