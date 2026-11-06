<?php

use App\Models\Clinica;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Factura;
use App\Models\LineaVisacion;
use App\Models\Liquidacion;
use App\Models\MedioPago;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\ResponsablePago;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\Cobrar;
use App\Support\Convenios\CalcularCobertura;
use App\Support\Convenios\FirmaRecibida;
use App\Support\Convenios\GestionCoberturas;
use App\Support\Convenios\GestionConvenios;
use App\Support\Facturacion\EmitirFactura;
use App\Support\Facturacion\FacturarLiquidacion;
use App\Support\Facturacion\GestionTimbrados;
use App\Support\Liquidaciones\ArmarLiquidacion;
use App\Support\Liquidaciones\FlujoLiquidacion;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Ruc;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Database\Seeders\MotivosAnulacionFacturaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * La factura de la liquidación en PostgreSQL (docs/facturacion.md): la migración con facturas, cobros, cajas y una liquidación ya
 * cargados (no cambia ninguna fila), los CHECK de la condición y del cliente según la condición, la marca de facturación de la
 * liquidación y el índice único parcial de una factura no anulada por liquidación. La numeración bajo concurrencia, con dos conexiones,
 * en FacturaNumeracionConcurrenciaTest.php. Datos inventados. Prefijo "pgFacturaLiquidacion".
 */
beforeEach(function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    (new MotivosAnulacionFacturaSeeder)->run();
    $procedimiento = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $usuario = fn (string $codigo) => User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
    [$this->gerente, $this->recepcion, $this->facturacion, $this->cajero] = [$usuario(PerfilesPredefinidos::GERENCIA), $usuario(PerfilesPredefinidos::RECEPCION),
        $usuario(PerfilesPredefinidos::FACTURACION), $usuario(PerfilesPredefinidos::CAJA)];
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO, CalcularCobertura::HITO, EmitirFactura::HITO])
        ->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $procedimiento->id, null, 100000, Carbon::parse('2026-10-01'));
    $clinica = Clinica::create(['tipo_contribuyente' => Clinica::SOCIEDAD, 'razon_social' => 'Clínica de Prueba S.A.', 'ruc' => Ruc::conDigito('80999001'),
        'actividad_economica' => 'Servicios de salud (prueba)', 'direccion_fiscal' => 'Calle Inventada 123']);
    Sucursal::query()->update(['clinica_id' => $clinica->id]);
    GestionTimbrados::cargar($this->gerente, ['sucursal_id' => Sucursal::principal()->id, 'numero' => '12345678', 'vigente_desde' => '2026-10-01',
        'vigente_hasta' => '2027-09-30', 'establecimiento' => '001', 'punto_expedicion' => '001', 'numero_inicial' => 1, 'numero_final' => 9999999]);
    $aseguradora = ResponsablePago::create(['persona_id' => Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Seguros de Prueba S.A.', 'ruc' => Ruc::conDigito('80888001')])->id]);
    $convenio = GestionConvenios::alta($this->facturacion, $aseguradora->id, 'Convenio de prueba', Carbon::parse('2026-10-01'), null, ['Plan Oro']);
    GestionCoberturas::cargarCobertura($this->recepcion, $this->paciente, $convenio->planes()->sole()->id, 'AS-0001', 'TITULAR', Carbon::parse('2026-10-01'), null);
    $turno = hcTurno(['procedimiento_id' => $procedimiento->id]);
    GestionCoberturas::cargarVisacion($this->recepcion, $this->paciente, 'turno', $turno->id, 'V-1', Carbon::parse('2026-10-06'),
        [['procedimiento_id' => $procedimiento->id, 'resultado' => LineaVisacion::APROBADO, 'copago' => LineaVisacion::PORCENTAJE, 'valor' => '20']]);
    $this->actingAs($this->medico);
    hcGuardarNueva([], $turno)->assertSessionHasNoErrors();
    $this->prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    FirmaRecibida::marcar($this->recepcion, $this->prestacion);
    // El copago en Caja (factura CONTADO) y la liquidación aprobada, pendiente de facturar.
    AbrirCaja::ejecutar($this->cajero, 0);
    $this->cobro = Cobrar::ejecutar($this->cajero, $this->paciente->id, [$this->prestacion->id], [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => 20000, 'referencia' => null]]);
    $this->liquidacion = ArmarLiquidacion::ejecutar($this->facturacion, $aseguradora, Carbon::parse('2026-10-06'));
    FlujoLiquidacion::enviar($this->facturacion, $this->liquidacion);
    $this->liquidacion = FlujoLiquidacion::aprobar($this->gerente, $this->liquidacion);
});

afterEach(fn () => Carbon::setTestNow());

function pgFacturaLiquidacionMigracion(): object
{
    return require base_path('database/migrations/2026_11_10_100001_factura_de_liquidacion.php');
}

/** La restricción con que la base rechaza la sentencia (en su propio savepoint), o null si la acepta. */
function pgFacturaLiquidacionRechazo(Closure $sentencia): ?string
{
    try {
        DB::transaction($sentencia);
    } catch (QueryException $e) {
        return collect(['facturas_condicion_valida', 'facturas_cliente_segun_condicion', 'facturas_una_por_liquidacion', 'liquidaciones_facturacion_valida',
            'facturas_numero_unico'])->first(fn (string $nombre) => str_contains($e->getMessage(), $nombre)) ?? $e->getMessage();
    }

    return null;
}

test('la migración, con facturas, cobros, cajas y una liquidación aprobada ya cargados: no cambia ninguna fila; la liquidación se puede facturar', function () {
    $migracion = pgFacturaLiquidacionMigracion();
    $migracion->down();
    $tablas = ['facturas', 'detalles_factura', 'cobros', 'cobro_prestaciones', 'movimientos_caja', 'cajas', 'liquidaciones', 'lineas_liquidacion', 'prestaciones',
        'timbrados', 'repartos_prestacion', 'lineas_reparto'];
    $foto = fn () => collect($tablas)->mapWithKeys(fn (string $tabla) => [$tabla => DB::table($tabla)->orderBy('id')->get()
        ->map(fn ($fila) => collect((array) $fila)->except('liquidacion_id')->all())->all()])->all();
    $antes = $foto();
    expect($antes['facturas'])->toHaveCount(1)->and($antes['liquidaciones'][0]['facturacion'])->toBe(Liquidacion::PENDIENTE_DE_FACTURAR);

    $migracion->up();

    expect($foto())->toBe($antes)->and(DB::table('facturas')->whereNotNull('liquidacion_id')->count())->toBe(0);
    $restricciones = DB::table('pg_constraint')->whereIn('conname', ['facturas_condicion_valida', 'facturas_cliente_segun_condicion', 'liquidaciones_facturacion_valida'])->pluck('conname');
    expect($restricciones)->toHaveCount(3)
        ->and(DB::table('pg_indexes')->where('indexname', 'facturas_una_por_liquidacion')->value('indexdef'))->toContain('WHERE (anulada_en IS NULL)');

    $factura = FacturarLiquidacion::emitir($this->facturacion, Liquidacion::sole());
    expect($factura->numero_completo)->toBe('001-001-0000002')->and($factura->condicion)->toBe(Factura::CREDITO);
});

test('CHECK de la condición y del cliente: CONTADO con paciente y caja; CRÉDITO con la liquidación, sin paciente, caja ni reemplazo', function () {
    $factura = FacturarLiquidacion::emitir($this->facturacion, $this->liquidacion);
    $contado = Factura::where('condicion', Factura::CONTADO)->sole();

    expect(pgFacturaLiquidacionRechazo(fn () => DB::table('facturas')->where('id', $factura->id)->update(['condicion' => 'OTRA'])))->toBeIn(['facturas_condicion_valida', 'facturas_cliente_segun_condicion'])
        ->and(pgFacturaLiquidacionRechazo(fn () => DB::table('facturas')->where('id', $factura->id)->update(['paciente_id' => $this->paciente->id])))->toBe('facturas_cliente_segun_condicion')
        ->and(pgFacturaLiquidacionRechazo(fn () => DB::table('facturas')->where('id', $factura->id)->update(['caja_id' => $contado->caja_id])))->toBe('facturas_cliente_segun_condicion')
        ->and(pgFacturaLiquidacionRechazo(fn () => DB::table('facturas')->where('id', $factura->id)->update(['liquidacion_id' => null])))->toBe('facturas_cliente_segun_condicion')
        ->and(pgFacturaLiquidacionRechazo(fn () => DB::table('facturas')->where('id', $contado->id)->update(['caja_id' => null])))->toBe('facturas_cliente_segun_condicion')
        ->and(pgFacturaLiquidacionRechazo(fn () => DB::table('facturas')->where('id', $contado->id)->update(['liquidacion_id' => $this->liquidacion->id])))->toBe('facturas_cliente_segun_condicion');
});

test('la marca de facturación de la liquidación (PENDIENTE o FACTURADA, solo aprobada) y una sola factura no anulada por liquidación', function () {
    $factura = FacturarLiquidacion::emitir($this->facturacion, $this->liquidacion);
    expect($this->liquidacion->fresh()->facturacion)->toBe(Liquidacion::FACTURADA)
        ->and(pgFacturaLiquidacionRechazo(fn () => DB::table('liquidaciones')->where('id', $this->liquidacion->id)->update(['facturacion' => 'OTRA'])))->toBe('liquidaciones_facturacion_valida');

    $copia = collect((array) DB::table('facturas')->where('id', $factura->id)->first())->except(['id', 'numero', 'numero_completo'])->all();
    expect(pgFacturaLiquidacionRechazo(fn () => DB::table('facturas')->insert([...$copia, 'numero' => 3, 'numero_completo' => '001-001-0000003'])))->toBe('facturas_una_por_liquidacion');
    // Anulada la primera, la base admite otra no anulada para la misma liquidación.
    DB::table('facturas')->where('id', $factura->id)->update(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_en' => now(), 'anulada_por' => $this->gerente->id,
        'motivo_anulacion_id' => DB::table('motivos_anulacion_factura')->value('id')]);
    expect(pgFacturaLiquidacionRechazo(fn () => DB::table('facturas')->insert([...$copia, 'numero' => 3, 'numero_completo' => '001-001-0000003'])))->toBeNull();
});
