<?php

/*
 * Salidas de caja (docs/cobros-caja.md): retiros, gastos y pagos menores en efectivo desde la caja abierta de hoy. Salida válida,
 * rechazo por efectivo insuficiente, caja cerrada o devuelta, anulación dentro y fuera de los 10 minutos (con la solicitud de las
 * reversiones y la aprobación de Gerencia), nadie revisa la propia, lo esperado y el cierre con salidas, la pantalla y la impresión
 * del arqueo, permisos, la migración de permisos (solo suma, repetible) y la auditoría. Datos y montos inventados.
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Caja;
use App\Models\CategoriaGasto;
use App\Models\CierreCajaMedio;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\SalidaCaja;
use App\Models\SolicitudReversionCobro;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Cobros\AnularSalida;
use App\Support\Cobros\PdfArqueo;
use App\Support\Cobros\RegistrarSalida;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    hcEscenario(); // "hoy": martes 06/10/2026, 09:00 en Paraguay
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $this->cajero = salidasCajaUsuario([PerfilesPredefinidos::CAJA], ['apellidos' => 'Cáceres', 'nombres' => 'Lidia']);
    $this->otroCajero = salidasCajaUsuario([PerfilesPredefinidos::CAJA], ['apellidos' => 'Ortiz', 'nombres' => 'Marta']);
    $this->gerente = salidasCajaUsuario([PerfilesPredefinidos::GERENCIA], ['apellidos' => 'Gómez', 'nombres' => 'Ana']);
    $this->efectivo = MedioPago::efectivo();
    $this->categoria = CategoriaGasto::create(['nombre' => 'Limpieza (prueba)', 'estado_id' => Estado::idDe(Estado::ACTIVO)]);
});

afterEach(fn () => Carbon::setTestNow());

function salidasCajaUsuario(array $perfiles, array $persona = []): User
{
    $factory = User::factory()->conPerfiles(PerfilAcceso::whereIn('codigo', $perfiles)->get()->all());

    return ($persona ? $factory->conPersona($persona) : $factory)->create();
}

function salidasCajaAbrir(User $cajero, string $monto = '100.000'): Caja
{
    test()->actingAs($cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => $monto])->assertSessionHas('status');

    return Caja::where('usuario_id', $cajero->id)->latest('id')->firstOrFail();
}

function salidasCajaRegistrar(User $cajero, Caja $caja, array $datos = [])
{
    return test()->actingAs($cajero)->from(route('admin.cajas.show', $caja))->post(route('admin.cajas.salidas.store', $caja), [
        'categoria_gasto_id' => test()->categoria->id, 'beneficiario' => 'Ferretería Inventada', 'comprobante' => 'REC-0001',
        'concepto' => 'Compra de artículos de limpieza (prueba).', 'monto' => '30.000', ...$datos,
    ]);
}

function salidasCajaCerrar(User $cajero, Caja $caja): void
{
    $contado = MedioPago::pluck('id')->mapWithKeys(fn ($id) => [$id => (string) ($caja->esperadoPorMedio()[$id] ?? 0)])->all();
    test()->actingAs($cajero)->post(route('admin.cajas.cerrar', $caja), ['contado' => $contado])->assertSessionHas('status');
}

function salidasCajaMovimientos(Caja $caja): array
{
    return MovimientoCaja::where('caja_id', $caja->id)->orderBy('id')->get()->map(fn ($m) => [$m->tipo, $m->medio_pago_id, (int) $m->monto])->all();
}

test('salida válida: en efectivo, resta de lo esperado, con sus datos y sin compra; se ve en la caja y en Mi caja', function () {
    $caja = salidasCajaAbrir($this->cajero);
    salidasCajaRegistrar($this->cajero, $caja)->assertRedirect(route('admin.cajas.show', $caja).'#salidas-caja')
        ->assertSessionHas('status', 'Salida registrada: ₲ 30.000 en efectivo.');

    $salida = SalidaCaja::sole();
    expect($salida->only(['caja_id', 'categoria_gasto_id', 'beneficiario', 'comprobante', 'concepto', 'monto', 'medio_pago_id', 'usuario_id', 'compra_id']))->toBe([
        'caja_id' => $caja->id, 'categoria_gasto_id' => $this->categoria->id, 'beneficiario' => 'Ferretería Inventada', 'comprobante' => 'REC-0001',
        'concepto' => 'Compra de artículos de limpieza (prueba).', 'monto' => 30000, 'medio_pago_id' => $this->efectivo->id, 'usuario_id' => $this->cajero->id, 'compra_id' => null,
    ])->and($salida->tieneEstado(Estado::VIGENTE))->toBeTrue()
        ->and(salidasCajaMovimientos($caja))->toBe([[MovimientoCaja::APERTURA, $this->efectivo->id, 100000], [MovimientoCaja::SALIDA, $this->efectivo->id, -30000]])
        ->and($caja->esperadoPorMedio()[$this->efectivo->id])->toBe(70000);

    $this->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('Salidas de caja (1)')->assertSee('Ferretería Inventada')
        ->assertSee('REC-0001')->assertSee('Limpieza (prueba)')->assertSee('Total de salidas vigentes')->assertSee('₲ -30.000')->assertSee('₲ 70.000')
        ->assertSee('Registrar una salida');
    $this->get(route('admin.cajas.index'))->assertOk()->assertSee('Registrar una salida');

    // Sin comprobante también: es opcional.
    salidasCajaRegistrar($this->cajero, $caja, ['comprobante' => '', 'monto' => '5000'])->assertSessionHas('status');
    expect(SalidaCaja::latest('id')->first()->comprobante)->toBeNull();
});

test('validaciones: concepto, beneficiario, monto mayor que cero y categoría activa', function () {
    $caja = salidasCajaAbrir($this->cajero);
    salidasCajaRegistrar($this->cajero, $caja, ['concepto' => 'abc'])->assertSessionHasErrors('concepto');
    salidasCajaRegistrar($this->cajero, $caja, ['beneficiario' => ''])->assertSessionHasErrors('beneficiario');
    salidasCajaRegistrar($this->cajero, $caja, ['monto' => '0'])->assertSessionHasErrors('monto');
    salidasCajaRegistrar($this->cajero, $caja, ['monto' => '1.500,50'])->assertSessionHasErrors('monto');
    salidasCajaRegistrar($this->cajero, $caja, ['categoria_gasto_id' => ''])->assertSessionHasErrors('categoria_gasto_id');
    $inactiva = CategoriaGasto::create(['nombre' => 'Vieja (prueba)', 'estado_id' => Estado::idDe(Estado::INACTIVO)]);
    salidasCajaRegistrar($this->cajero, $caja, ['categoria_gasto_id' => $inactiva->id])->assertSessionHas('error', RegistrarSalida::SIN_CATEGORIA);
    expect(SalidaCaja::count())->toBe(0);
});

test('efectivo insuficiente: se rechaza con un mensaje claro; con lo justo, la caja queda en cero', function () {
    $caja = salidasCajaAbrir($this->cajero, '50.000');
    salidasCajaRegistrar($this->cajero, $caja, ['monto' => '50.001'])
        ->assertSessionHas('error', 'No alcanza el efectivo de la caja para esta salida de ₲ 50.001 (hay ₲ 50.000): la caja no puede quedar con efectivo negativo.');
    expect(SalidaCaja::count())->toBe(0)->and(MovimientoCaja::where('tipo', MovimientoCaja::SALIDA)->count())->toBe(0);

    salidasCajaRegistrar($this->cajero, $caja, ['monto' => '50.000'])->assertSessionHas('status');
    expect($caja->esperadoPorMedio()[$this->efectivo->id])->toBe(0);
    salidasCajaRegistrar($this->cajero, $caja, ['monto' => '1'])->assertSessionHas('error');

    // Sin monto inicial no hay efectivo.
    $otra = salidasCajaAbrir($this->otroCajero, '0');
    salidasCajaRegistrar($this->otroCajero, $otra, ['monto' => '1.000'])->assertSessionHas('error');
});

test('solo la caja abierta de hoy de su cajero: ni otra caja, ni pendiente de recepción, ni devuelta, ni de otro día', function () {
    $caja = salidasCajaAbrir($this->cajero);
    $ajena = salidasCajaAbrir($this->otroCajero);
    salidasCajaRegistrar($this->cajero, $ajena)->assertSessionHas('error', 'Solo el cajero de la caja registra salidas en ella.');

    salidasCajaCerrar($this->cajero, $caja);
    salidasCajaRegistrar($this->cajero, $caja)->assertSessionHas('error', RegistrarSalida::NO_ABIERTA);
    $this->get(route('admin.cajas.show', $caja))->assertOk()->assertDontSee('Registrar una salida');

    $this->actingAs($this->gerente)->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Falta un comprobante (prueba).'])->assertSessionHas('status');
    expect($caja->fresh()->devuelta())->toBeTrue();
    salidasCajaRegistrar($this->cajero, $caja)->assertSessionHas('error', RegistrarSalida::NO_ABIERTA);

    // Una caja abierta de ayer no admite salidas.
    $tercero = salidasCajaUsuario([PerfilesPredefinidos::CAJA]);
    $deAyer = salidasCajaAbrir($tercero);
    Carbon::setTestNow(now()->addDay());
    salidasCajaRegistrar($tercero, $deAyer)->assertSessionHas('error');
    expect(SalidaCaja::count())->toBe(0);
});

test('anulación dentro de los 10 minutos: quien la registró, con motivo; el monto vuelve al efectivo y queda registrada', function () {
    $caja = salidasCajaAbrir($this->cajero);
    salidasCajaRegistrar($this->cajero, $caja);
    $salida = SalidaCaja::sole();
    Carbon::setTestNow(now()->addMinutes(9));

    $this->post(route('admin.salidas-caja.anular', $salida), ['motivo' => 'abc'])->assertSessionHasErrors('motivo', null, 'salida'.$salida->id);
    // Otro cajero no la anula (no es suya).
    $this->actingAs($this->otroCajero)->post(route('admin.salidas-caja.anular', $salida), ['motivo' => 'No es mía (prueba).'])->assertSessionHas('error', AnularSalida::AJENA);

    $this->actingAs($this->cajero)->get(route('admin.cajas.show', $caja))->assertSee('Anular la salida');
    $this->post(route('admin.salidas-caja.anular', $salida), ['motivo' => 'Monto mal cargado (prueba).'])->assertSessionHas('status');
    $salida->refresh();
    expect($salida->anulada())->toBeTrue()->and($salida->tieneEstado(Estado::ANULADO))->toBeTrue()
        ->and($salida->anulada_por)->toBe($this->cajero->id)->and($salida->motivo_anulacion)->toBe('Monto mal cargado (prueba).')
        ->and(salidasCajaMovimientos($caja))->toBe([[MovimientoCaja::APERTURA, $this->efectivo->id, 100000], [MovimientoCaja::SALIDA, $this->efectivo->id, -30000],
            [MovimientoCaja::ANULACION_SALIDA, $this->efectivo->id, 30000]])
        ->and($caja->esperadoPorMedio()[$this->efectivo->id])->toBe(100000);
    $this->post(route('admin.salidas-caja.anular', $salida), ['motivo' => 'Otra vez (prueba).'])->assertSessionHas('error', AnularSalida::YA_ANULADA);
    $this->get(route('admin.cajas.show', $caja))->assertSee('(anulada)')->assertSee('Motivo: Monto mal cargado (prueba).');
});

test('pasados los 10 minutos: solo por solicitud; Gerencia aprueba (se anula) o rechaza con nota; una sola pendiente', function () {
    $caja = salidasCajaAbrir($this->cajero);
    salidasCajaRegistrar($this->cajero, $caja);
    salidasCajaRegistrar($this->cajero, $caja, ['beneficiario' => 'Librería Inventada', 'monto' => '10.000']);
    [$aprobar, $rechazar] = SalidaCaja::orderBy('id')->get()->all();

    // Dentro del plazo no se solicita: se anula directo.
    $this->post(route('admin.salidas-caja.solicitar-anulacion', $aprobar), ['motivo' => 'Antes de tiempo (prueba).'])->assertSessionHas('error', AnularSalida::ES_DIRECTA);

    Carbon::setTestNow(now()->addMinutes(11));
    $this->post(route('admin.salidas-caja.anular', $aprobar), ['motivo' => 'Tarde (prueba).'])->assertSessionHas('error', AnularSalida::necesitaSolicitud());
    $this->get(route('admin.cajas.show', $caja))->assertSee('Solicitar la anulación')->assertDontSee('Anular la salida');

    $this->post(route('admin.salidas-caja.solicitar-anulacion', $aprobar), ['motivo' => 'El proveedor devolvió el dinero (prueba).'])->assertSessionHas('status');
    $this->post(route('admin.salidas-caja.solicitar-anulacion', $aprobar), ['motivo' => 'Repetida (prueba).'])->assertSessionHas('error', AnularSalida::YA_SOLICITADA);
    $this->post(route('admin.salidas-caja.anular', $aprobar), ['motivo' => 'Con pendiente (prueba).'])->assertSessionHas('error', AnularSalida::YA_SOLICITADA);
    $this->post(route('admin.salidas-caja.solicitar-anulacion', $rechazar), ['motivo' => 'Se cargó dos veces (prueba).'])->assertSessionHas('status');
    $this->get(route('admin.cajas.show', $caja))->assertSee('pendiente de aprobación');

    $solicitudAprobar = SolicitudReversionCobro::where('salida_caja_id', $aprobar->id)->sole();
    $solicitudRechazar = SolicitudReversionCobro::where('salida_caja_id', $rechazar->id)->sole();
    expect($solicitudAprobar->cobro_id)->toBeNull()->and($solicitudAprobar->esDeSalida())->toBeTrue();

    $this->actingAs($this->gerente)->get(route('admin.cobros.reversiones'))->assertOk()->assertSee('Anulaciones de salidas de caja (2)')
        ->assertSee('Ferretería Inventada')->assertSee('El proveedor devolvió el dinero (prueba).')->assertSee('No hay solicitudes de reversión pendientes.');
    $this->post(route('admin.cobros.reversiones.aprobar', $solicitudAprobar), ['nota' => 'Visto (prueba).'])
        ->assertSessionHas('status', "Anulación aprobada: la salida de caja N.º {$aprobar->id} quedó anulada y su monto volvió al efectivo de la caja.");
    $this->post(route('admin.cobros.reversiones.rechazar', $solicitudRechazar), ['nota_rechazo' => 'abc'])->assertSessionHasErrors('nota_rechazo');
    $this->post(route('admin.cobros.reversiones.rechazar', $solicitudRechazar), ['nota_rechazo' => 'No corresponde (prueba).'])->assertSessionHas('status', 'Solicitud de anulación de la salida rechazada.');
    $this->post(route('admin.cobros.reversiones.aprobar', $solicitudRechazar))->assertSessionHas('error', 'Esta solicitud ya fue revisada.');

    expect($aprobar->fresh()->anulada())->toBeTrue()->and($aprobar->fresh()->anulada_por)->toBe($this->gerente->id)
        ->and($aprobar->fresh()->motivo_anulacion)->toBe('El proveedor devolvió el dinero (prueba).')
        ->and($rechazar->fresh()->anulada())->toBeFalse()
        ->and($solicitudAprobar->fresh()->tieneEstado(Estado::APROBADO))->toBeTrue()->and($solicitudRechazar->fresh()->tieneEstado(Estado::RECHAZADO))->toBeTrue()
        ->and($caja->esperadoPorMedio()[$this->efectivo->id])->toBe(90000);

    // Rechazada, se puede volver a pedir.
    $this->actingAs($this->cajero)->post(route('admin.salidas-caja.solicitar-anulacion', $rechazar), ['motivo' => 'Con el recibo anulado (prueba).'])->assertSessionHas('status');
    expect(SolicitudReversionCobro::where('salida_caja_id', $rechazar->id)->count())->toBe(2);
});

test('nadie aprueba ni rechaza su propia solicitud', function () {
    // Una persona con Caja y Gerencia: registra, pide la anulación y no puede revisarla; otro gerente sí.
    $ambos = salidasCajaUsuario([PerfilesPredefinidos::CAJA, PerfilesPredefinidos::GERENCIA]);
    $caja = salidasCajaAbrir($ambos);
    salidasCajaRegistrar($ambos, $caja);
    $salida = SalidaCaja::sole();
    Carbon::setTestNow(now()->addMinutes(11));
    $this->post(route('admin.salidas-caja.solicitar-anulacion', $salida), ['motivo' => 'Error de carga (prueba).'])->assertSessionHas('status');
    $solicitud = SolicitudReversionCobro::sole();

    $this->post(route('admin.cobros.reversiones.aprobar', $solicitud))->assertSessionHas('error', AnularSalida::PROPIA);
    $this->post(route('admin.cobros.reversiones.rechazar', $solicitud), ['nota_rechazo' => 'Me rechazo (prueba).'])->assertSessionHas('error', AnularSalida::PROPIA);
    $this->get(route('admin.cobros.reversiones'))->assertOk()->assertDontSee('>Aprobar<', false);
    expect($salida->fresh()->anulada())->toBeFalse();

    $this->actingAs($this->gerente)->post(route('admin.cobros.reversiones.aprobar', $solicitud))->assertSessionHas('status');
    expect($salida->fresh()->anulada())->toBeTrue();
});

test('con la caja cerrada no se anula: ni directa, ni por solicitud, ni aprobando una pendiente', function () {
    $caja = salidasCajaAbrir($this->cajero);
    salidasCajaRegistrar($this->cajero, $caja);
    salidasCajaRegistrar($this->cajero, $caja, ['monto' => '10.000']);
    [$directa, $pendiente] = SalidaCaja::orderBy('id')->get()->all();
    Carbon::setTestNow(now()->addMinutes(11));
    $this->post(route('admin.salidas-caja.solicitar-anulacion', $pendiente), ['motivo' => 'Error de carga (prueba).'])->assertSessionHas('status');
    salidasCajaCerrar($this->cajero, $caja);

    $this->post(route('admin.salidas-caja.anular', $directa), ['motivo' => 'Ya cerrada (prueba).'])->assertSessionHas('error', AnularSalida::CAJA_CERRADA);
    Carbon::setTestNow(now()->addMinutes(20));
    $this->post(route('admin.salidas-caja.solicitar-anulacion', $directa), ['motivo' => 'Ya cerrada (prueba).'])->assertSessionHas('error', AnularSalida::CAJA_CERRADA);
    $this->get(route('admin.cajas.show', $caja))->assertDontSee('Solicitar la anulación')->assertDontSee('Anular la salida');

    $solicitud = SolicitudReversionCobro::sole();
    $this->actingAs($this->gerente)->post(route('admin.cobros.reversiones.aprobar', $solicitud))
        ->assertSessionHas('error', 'La caja de esta salida ya no está abierta (o no es la de hoy): la anulación no se puede aprobar; rechace la solicitud.');
    $this->post(route('admin.cobros.reversiones.rechazar', $solicitud), ['nota_rechazo' => 'La caja ya se cerró (prueba).'])->assertSessionHas('status');
    expect(SalidaCaja::whereNotNull('anulada_en')->count())->toBe(0)
        ->and(MovimientoCaja::where('tipo', MovimientoCaja::ANULACION_SALIDA)->count())->toBe(0);

    // Devuelta por diferencias, tampoco.
    $this->post(route('admin.cajas.devolver', $caja), ['nota_devolucion' => 'Revisar salidas (prueba).'])->assertSessionHas('status');
    $this->actingAs($this->cajero)->post(route('admin.salidas-caja.solicitar-anulacion', $directa), ['motivo' => 'Devuelta (prueba).'])->assertSessionHas('error', AnularSalida::CAJA_CERRADA);
});

test('lo esperado, el cierre, la pantalla y la impresión del arqueo con salidas (la anulada, marcada y fuera del total)', function () {
    $caja = salidasCajaAbrir($this->cajero, '200.000');
    salidasCajaRegistrar($this->cajero, $caja, ['monto' => '45.000']);
    salidasCajaRegistrar($this->cajero, $caja, ['beneficiario' => 'Kiosco Inventado', 'comprobante' => '', 'monto' => '15.000', 'concepto' => 'Agua para la sala de espera (prueba).']);
    $anulada = SalidaCaja::latest('id')->first();
    $this->post(route('admin.salidas-caja.anular', $anulada), ['motivo' => 'Se pagó con otra plata (prueba).'])->assertSessionHas('status');
    expect($caja->esperadoPorMedio()[$this->efectivo->id])->toBe(155000);

    // El formulario de cierre trae lo esperado ya descontado; el cierre lo guarda.
    $this->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('data-esperado="155000"', false);
    salidasCajaCerrar($this->cajero, $caja);
    expect(CierreCajaMedio::where('medio_pago_id', $this->efectivo->id)->sole()->only(['esperado', 'contado', 'diferencia']))
        ->toEqual(['esperado' => 155000, 'contado' => 155000, 'diferencia' => 0]);

    $this->actingAs($this->gerente)->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('Salidas de caja (2)')->assertSee('Kiosco Inventado');
    $html = PdfArqueo::html($caja->fresh());
    expect($html)->toContain('Salidas de caja (2)')->toContain('Ferretería Inventada')->toContain('Kiosco Inventado')->toContain('REC-0001')
        ->toContain('Limpieza (prueba)')->toContain('Agua para la sala de espera (prueba).')->toContain('₲ -45.000')->toContain('₲ -15.000')
        ->toContain('(anulada)')->toContain('Anulada: Se pagó con otra plata (prueba).')->toContain('Total de salidas vigentes (en efectivo)')
        ->toContain('₲ 155.000');
    $this->get(route('admin.cajas.arqueo', $caja))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('permisos: Caja y Gerencia; Recepción y Facturación, no; Caja elige la categoría sin poder administrar el catálogo', function () {
    $caja = salidasCajaAbrir($this->cajero);
    $recepcion = salidasCajaUsuario([PerfilesPredefinidos::RECEPCION]);
    $facturacion = salidasCajaUsuario([PerfilesPredefinidos::FACTURACION]);
    salidasCajaRegistrar($recepcion, $caja)->assertForbidden();
    salidasCajaRegistrar($facturacion, $caja)->assertForbidden();
    salidasCajaRegistrar($this->cajero, $caja)->assertSessionHas('status');
    $salida = SalidaCaja::sole();
    $this->actingAs($recepcion)->post(route('admin.salidas-caja.anular', $salida), ['motivo' => 'Sin permiso (prueba).'])->assertForbidden();
    $this->actingAs($this->cajero)->get(route('admin.cobros.reversiones'))->assertForbidden();

    foreach ([PerfilesPredefinidos::CAJA, PerfilesPredefinidos::GERENCIA] as $codigo) {
        expect(collect(PerfilAcceso::where('codigo', $codigo)->sole()->clavesPermisos())->filter(fn ($clave) => str_starts_with($clave, 'SALIDAS_CAJA:'))->sort()->values()->all())
            ->toBe(['SALIDAS_CAJA:CREAR', 'SALIDAS_CAJA:DESACTIVAR', 'SALIDAS_CAJA:VER']);
    }
    expect(Permiso::accionesDe('SALIDAS_CAJA'))->toBe(['VER', 'CREAR', 'DESACTIVAR'])
        ->and($this->cajero->tienePermiso('CATEGORIAS_GASTO', 'VER'))->toBeFalse();

    // La categoría se elige en el formulario, pero el catálogo no lo administra.
    $this->actingAs($this->cajero)->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('Limpieza (prueba)');
    $this->get(route('admin.categorias-gasto.index'))->assertForbidden();
    $this->post(route('admin.categorias-gasto.store'), ['nombre' => 'Nueva (prueba)'])->assertForbidden();
});

test('la migración de permisos solo suma, a Caja y Gerencia, y se puede repetir', function () {
    $caja = PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole();
    $antes = $caja->clavesPermisos();
    $modulo = DB::table('modulos_sistema')->where('codigo', 'SALIDAS_CAJA')->value('id');
    $caja->permisos()->detach(Permiso::where('modulo_sistema_id', $modulo)->pluck('id'));
    $recepcion = PerfilAcceso::where('codigo', PerfilesPredefinidos::RECEPCION)->sole()->clavesPermisos();

    $migracion = require base_path('database/migrations/2026_11_09_100002_permisos_salidas_caja.php');
    $migracion->up();
    $migracion->up();

    expect(collect($caja->fresh()->clavesPermisos())->sort()->values()->all())->toBe(collect($antes)->sort()->values()->all())
        ->and(PerfilAcceso::where('codigo', PerfilesPredefinidos::RECEPCION)->sole()->clavesPermisos())->toBe($recepcion)
        ->and(PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->sole()->clavesPermisos())->toContain('SALIDAS_CAJA:CREAR');
});

test('auditoría: CREAR de la salida con sus datos, ANULAR con el motivo, la solicitud y su revisión; sin VER sobre Cajas, sin contenido', function () {
    $caja = salidasCajaAbrir($this->cajero);
    salidasCajaRegistrar($this->cajero, $caja);
    salidasCajaRegistrar($this->cajero, $caja, ['monto' => '12.345', 'beneficiario' => 'Taller Inventado']);
    [$directa, $porSolicitud] = SalidaCaja::orderBy('id')->get()->all();
    $this->post(route('admin.salidas-caja.anular', $directa), ['motivo' => 'Motivo directo (prueba).'])->assertSessionHas('status');
    Carbon::setTestNow(now()->addMinutes(11));
    $this->post(route('admin.salidas-caja.solicitar-anulacion', $porSolicitud), ['motivo' => 'Motivo pedido (prueba).'])->assertSessionHas('status');
    $solicitud = SolicitudReversionCobro::sole();
    $this->actingAs($this->gerente)->post(route('admin.cobros.reversiones.aprobar', $solicitud), ['nota' => 'Visto.'])->assertSessionHas('status');

    $log = fn (string $tabla, int $id, AccionAuditoria $accion) => LogAuditoria::where('tabla_afectada', $tabla)
        ->where('registro_afectado_id', (string) $id)->where('accion', $accion->value)->orderBy('id')->get();
    $crear = $log('salidas_caja', $porSolicitud->id, AccionAuditoria::CREAR)->sole();
    expect($crear->detalle)->toBe('Salida de caja: Limpieza (prueba), a Taller Inventado')->and($crear->usuario_id)->toBe($this->cajero->id)
        ->and($crear->valor_nuevo)->toMatchArray(['monto' => 12345, 'categoria_gasto_id' => $this->categoria->id, 'beneficiario' => 'Taller Inventado',
            'concepto' => 'Compra de artículos de limpieza (prueba).', 'usuario_id' => $this->cajero->id])
        ->and($crear->valor_nuevo)->toHaveKey('registrada_en')
        ->and($log('salidas_caja', $directa->id, AccionAuditoria::ANULAR)->sole()->detalle)->toBe('Anulación de la salida de caja. Motivo: Motivo directo (prueba).')
        ->and($log('salidas_caja', $porSolicitud->id, AccionAuditoria::ANULAR)->sole()->usuario_id)->toBe($this->gerente->id)
        ->and($log('solicitudes_reversion_cobro', $solicitud->id, AccionAuditoria::CREAR)->sole()->detalle)->toBe("Solicitud de anulación de la salida de caja {$porSolicitud->id}")
        ->and($log('solicitudes_reversion_cobro', $solicitud->id, AccionAuditoria::EDITAR)->sole()->detalle)->toBe('Anulación de la salida de caja aprobada. Nota: Visto.')
        ->and(Auditoria::permisoDeContenido('salidas_caja'))->toBe('CAJAS');

    $auditor = salidasCajaUsuario([PerfilesPredefinidos::AUDITOR]);
    $this->actingAs($auditor)->get(route('admin.auditoria.show', $crear))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Cajas')->assertDontSee('12345')->assertDontSee('Taller Inventado');
    $this->actingAs($this->gerente)->get(route('admin.auditoria.show', $crear))->assertOk()->assertDontSee('Contenido económico')->assertSee('Taller Inventado');
});

test('una salida no se edita ni se borra; su movimiento tampoco', function () {
    $caja = salidasCajaAbrir($this->cajero);
    salidasCajaRegistrar($this->cajero, $caja);
    $salida = SalidaCaja::sole();
    expect(fn () => $salida->update(['monto' => 1]))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $salida->fresh()->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => MovimientoCaja::where('tipo', MovimientoCaja::SALIDA)->sole()->delete())->toThrow(RegistroEconomicoInmutable::class);
    expect(fn () => AnularSalida::anular($this->otroCajero, $salida, 'Ajena (prueba).'))->toThrow(AccionRechazada::class, AnularSalida::AJENA);
});
