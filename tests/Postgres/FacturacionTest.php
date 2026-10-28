<?php

use App\Models\Clinica;
use App\Models\Consulta;
use App\Models\DetalleFactura;
use App\Models\Estado;
use App\Models\Factura;
use App\Models\MedioPago;
use App\Models\MotivoAnulacionFactura;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Sucursal;
use App\Models\Timbrado;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\Cobrar;
use App\Support\Facturacion\BloqueoFactura;
use App\Support\Facturacion\EmitirFactura;
use App\Support\Facturacion\GestionTimbrados;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Ruc;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Database\Seeders\MotivosAnulacionFacturaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de la facturación en PostgreSQL (docs/facturacion.md): los tres índices únicos parciales (un timbrado vigente por
 * sucursal, establecimiento y punto; una factura vigente por prestación y parte; una solicitud de anulación pendiente por factura), los CHECK de
 * la numeración y de los totales, y la migración con cobros, cajas y prestaciones ya existentes. Lo demás está en tests/Feature/Economico.
 * Datos inventados. Prefijo "pgFacturacion".
 */
beforeEach(function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    (new MotivosAnulacionFacturaSeeder)->run();
    $procedimiento = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, EmitirFactura::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->medico, $procedimiento->id, null, 100000, Carbon::parse('2026-10-01'));
    $this->cajero = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole()])->create();
    $this->gerente = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::GERENCIA)->sole()])->create();
    $clinica = Clinica::create(['tipo_contribuyente' => Clinica::SOCIEDAD, 'razon_social' => 'Clínica de Prueba S.A.', 'ruc' => Ruc::conDigito('80999001'),
        'actividad_economica' => 'Servicios de salud (prueba)', 'direccion_fiscal' => 'Calle Inventada 123']);
    Sucursal::query()->update(['clinica_id' => $clinica->id]);
    $this->timbrado = GestionTimbrados::cargar($this->gerente, ['sucursal_id' => Sucursal::principal()->id, 'numero' => '12345678', 'vigente_desde' => '2026-10-01',
        'vigente_hasta' => '2027-09-30', 'establecimiento' => '001', 'punto_expedicion' => '001', 'numero_inicial' => 1, 'numero_final' => 9999999]);
    AbrirCaja::ejecutar($this->cajero, 0);
});

afterEach(fn () => Carbon::setTestNow());

/** El código y la restricción con que la base rechaza la sentencia (en su propio savepoint), o null si la acepta. */
function pgFacturacionRechazo(Closure $sentencia): ?array
{
    try {
        DB::transaction($sentencia);
    } catch (QueryException $e) {
        $restricciones = ['timbrados_uno_vigente', 'detalles_factura_una_por_parte', 'solicitudes_anulacion_una_pendiente', 'timbrados_numeracion_valida',
            'timbrados_vigencia_valida', 'facturas_numeracion_valida', 'facturas_numero_unico', 'facturas_totales_validos', 'facturas_anulacion_completa',
            'detalles_factura_montos_validos', 'detalles_factura_parte_valida', 'procedimientos_tasa_iva_valida', 'solicitudes_anulacion_revision_completa', 'clinicas_una_sola', 'clinicas_ruc_formato'];

        return [$e->getCode(), collect($restricciones)->first(fn (string $nombre) => str_contains($e->getMessage(), $nombre))];
    }

    return null;
}

/** Una factura emitida y cobrada de verdad (por el servicio del cobro). */
function pgFacturacionFactura(): Factura
{
    test()->actingAs(test()->medico);
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $cobro = Cobrar::ejecutar(test()->cajero, test()->paciente->id, [$prestacion->id], [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => 100000, 'referencia' => null]]);

    return $cobro->factura;
}

/** Una copia de la fila de la tabla, con cambios (para insertarla como otra). */
function pgFacturacionCopia(string $tabla, int $id, array $cambios = []): array
{
    return [...collect((array) DB::table($tabla)->where('id', $id)->first())->except('id')->all(), ...$cambios];
}

test('corre de verdad contra PostgreSQL, con las restricciones y los índices creados', function () {
    $restricciones = fn (string $tabla) => collect(DB::select("select conname from pg_constraint where conrelid = '{$tabla}'::regclass and contype = 'c'"))
        ->pluck('conname')->sort()->values()->all();
    $indices = fn (string $tabla) => collect(DB::select('select indexname from pg_indexes where tablename = ?', [$tabla]))->pluck('indexname')->all();

    expect(DB::getDriverName())->toBe('pgsql')->and(DB::connection()->getDatabaseName())->toBe('clinexa_test')
        ->and($restricciones('timbrados'))->toBe(['timbrados_historico_completo', 'timbrados_numeracion_valida', 'timbrados_vigencia_valida'])
        ->and($restricciones('facturas'))->toBe(['facturas_anulacion_completa', 'facturas_condicion_valida', 'facturas_no_se_reemplaza_a_si_misma', 'facturas_numeracion_valida', 'facturas_totales_validos'])
        ->and($restricciones('detalles_factura'))->toBe(['detalles_factura_montos_validos', 'detalles_factura_parte_valida'])
        ->and($restricciones('solicitudes_anulacion_factura'))->toBe(['solicitudes_anulacion_revision_completa'])
        ->and($restricciones('clinicas'))->toBe(['clinicas_instalacion_unica', 'clinicas_ruc_formato', 'clinicas_tipo_valido'])
        ->and($indices('timbrados'))->toContain('timbrados_uno_vigente')
        ->and($indices('detalles_factura'))->toContain('detalles_factura_una_por_parte')
        ->and($indices('solicitudes_anulacion_factura'))->toContain('solicitudes_anulacion_una_pendiente')
        ->and($indices('facturas'))->toContain('facturas_numero_unico');
});

test('timbrados_uno_vigente: un timbrado vigente por sucursal, establecimiento y punto; los históricos y otro punto no cuentan', function () {
    $id = $this->timbrado->id;

    expect(pgFacturacionRechazo(fn () => DB::table('timbrados')->insert(pgFacturacionCopia('timbrados', $id, ['numero' => '87654321']))))->toBe(['23505', 'timbrados_uno_vigente'])
        ->and(pgFacturacionRechazo(fn () => DB::table('timbrados')->insert(pgFacturacionCopia('timbrados', $id, ['punto_expedicion' => '002']))))->toBeNull()
        ->and(pgFacturacionRechazo(fn () => DB::table('timbrados')->insert(pgFacturacionCopia('timbrados', $id, ['estado_id' => Estado::idDe(Estado::HISTORICO),
            'historico_en' => now(), 'historico_por' => test()->gerente->id]))))->toBeNull();

    // Pasado el primero a histórico, se carga otro para el mismo punto.
    DB::table('timbrados')->where('id', $id)->update(['estado_id' => Estado::idDe(Estado::HISTORICO), 'historico_en' => now(), 'historico_por' => test()->gerente->id]);
    expect(pgFacturacionRechazo(fn () => DB::table('timbrados')->insert(pgFacturacionCopia('timbrados', $id, ['numero' => '87654321', 'historico_en' => null,
        'historico_por' => null, 'estado_id' => Estado::idDe(Estado::VIGENTE)]))))->toBeNull();
});

test('detalles_factura_una_por_parte: cada parte de una prestación en una sola factura no anulada (la línea liberada, queda libre)', function () {
    $factura = pgFacturacionFactura();
    $linea = $factura->detalles()->sole();
    $otra = DB::table('facturas')->insertGetId(pgFacturacionCopia('facturas', $factura->id, ['numero' => 2, 'numero_completo' => '001-001-0000002']));
    $tercera = DB::table('facturas')->insertGetId(pgFacturacionCopia('facturas', $factura->id, ['numero' => 3, 'numero_completo' => '001-001-0000003']));

    expect($linea->parte)->toBe(DetalleFactura::PACIENTE)
        ->and(pgFacturacionRechazo(fn () => DB::table('detalles_factura')->insert(pgFacturacionCopia('detalles_factura', $linea->id, ['factura_id' => $otra]))))
        ->toBe(['23505', 'detalles_factura_una_por_parte'])
        ->and(pgFacturacionRechazo(fn () => DB::table('detalles_factura')->insert(pgFacturacionCopia('detalles_factura', $linea->id, ['factura_id' => $otra, 'parte' => 'OTRA']))))
        ->toBe(['23514', 'detalles_factura_parte_valida']);

    // La parte de la aseguradora de la misma prestación va en otra factura, pero tampoco dos veces.
    expect(pgFacturacionRechazo(fn () => DB::table('detalles_factura')->insert(pgFacturacionCopia('detalles_factura', $linea->id, ['factura_id' => $otra, 'parte' => DetalleFactura::ASEGURADORA]))))
        ->toBeNull()
        ->and(pgFacturacionRechazo(fn () => DB::table('detalles_factura')->insert(pgFacturacionCopia('detalles_factura', $linea->id, ['factura_id' => $tercera, 'parte' => DetalleFactura::ASEGURADORA]))))
        ->toBe(['23505', 'detalles_factura_una_por_parte']);

    // Facturada en cualquiera de sus partes, la prestación queda bloqueada; la de la paciente sigue siendo la suya.
    $prestacion = Prestacion::findOrFail($linea->prestacion_id);
    expect($prestacion->detallesFactura()->count())->toBe(2)->and($prestacion->detalleFactura->id)->toBe($linea->id)
        ->and(BloqueoFactura::impedimento($prestacion))->toContain($factura->numero_completo);
    DB::table('detalles_factura')->where('id', $linea->id)->update(['liberada_en' => now()]);
    expect(BloqueoFactura::impedimento($prestacion))->toContain('001-001-0000002')->and($prestacion->fresh()->detalleFactura)->toBeNull();
    DB::table('detalles_factura')->where('prestacion_id', $prestacion->id)->update(['liberada_en' => now()]);
    expect(BloqueoFactura::impedimento($prestacion))->toBeNull();

    expect(pgFacturacionRechazo(fn () => DB::table('detalles_factura')->insert(pgFacturacionCopia('detalles_factura', $linea->id, ['factura_id' => $otra]))))->toBeNull()
        ->and(DB::table('detalles_factura')->where('prestacion_id', $linea->prestacion_id)->count())->toBe(3);
});

test('solicitudes_anulacion_una_pendiente: una sola pendiente por factura; revisada, se puede pedir otra (y nadie revisa la propia)', function () {
    $factura = pgFacturacionFactura();
    $solicitud = fn (array $datos = []) => DB::table('solicitudes_anulacion_factura')->insertGetId(['factura_id' => $factura->id, 'usuario_id' => test()->cajero->id,
        'solicitada_en' => now(), 'motivo_anulacion_id' => MotivoAnulacionFactura::where('codigo', 'DATOS_CLIENTE')->value('id'),
        'estado_id' => Estado::idDe(Estado::PENDIENTE), 'created_at' => now(), 'updated_at' => now(), ...$datos]);
    $primera = $solicitud();

    expect(pgFacturacionRechazo(fn () => $solicitud()))->toBe(['23505', 'solicitudes_anulacion_una_pendiente'])
        ->and(pgFacturacionRechazo(fn () => DB::table('solicitudes_anulacion_factura')->where('id', $primera)->update(['estado_id' => Estado::idDe(Estado::RECHAZADO),
            'revisado_en' => now(), 'revisado_por' => test()->cajero->id])))->toBe(['23514', 'solicitudes_anulacion_revision_completa']);

    DB::table('solicitudes_anulacion_factura')->where('id', $primera)->update(['estado_id' => Estado::idDe(Estado::RECHAZADO), 'revisado_en' => now(), 'revisado_por' => test()->gerente->id]);
    expect(pgFacturacionRechazo(fn () => $solicitud()))->toBeNull();
});

test('CHECK de la numeración: formato 001-001-0000001, número de 1 a 9999999, sin repetir en el timbrado; el timbrado con su rango y su vigencia', function () {
    $factura = pgFacturacionFactura();
    $copia = fn (array $cambios) => fn () => DB::table('facturas')->insert(pgFacturacionCopia('facturas', $factura->id, $cambios));
    $timbrado = fn (array $cambios) => fn () => DB::table('timbrados')->insert(pgFacturacionCopia('timbrados', $this->timbrado->id, ['punto_expedicion' => '009', ...$cambios]));

    expect($factura->numero_completo)->toBe('001-001-0000001')
        ->and(pgFacturacionRechazo($copia([])))->toBe(['23505', 'facturas_numero_unico'])
        ->and(pgFacturacionRechazo($copia(['numero' => 2, 'numero_completo' => '001-001-0000003'])))->toBe(['23514', 'facturas_numeracion_valida'])
        ->and(pgFacturacionRechazo($copia(['numero' => 2, 'numero_completo' => '1-1-2'])))->toBe(['23514', 'facturas_numeracion_valida'])
        ->and(pgFacturacionRechazo($copia(['numero' => 0, 'numero_completo' => '001-001-0000000'])))->toBe(['23514', 'facturas_numeracion_valida'])
        ->and(pgFacturacionRechazo($copia(['numero' => 2, 'numero_completo' => '001-001-0000002'])))->toBeNull()
        // El timbrado: 8 dígitos, establecimiento y punto de 3, el actual dentro del rango, la vigencia en orden.
        ->and(pgFacturacionRechazo($timbrado(['numero' => '1234'])))->toBe(['23514', 'timbrados_numeracion_valida'])
        ->and(pgFacturacionRechazo($timbrado(['punto_expedicion' => '9'])))->toBe(['23514', 'timbrados_numeracion_valida'])
        ->and(pgFacturacionRechazo($timbrado(['numero_inicial' => 10, 'numero_final' => 5, 'numero_actual' => 9])))->toBe(['23514', 'timbrados_numeracion_valida'])
        ->and(pgFacturacionRechazo($timbrado(['numero_actual' => 10000000])))->toBe(['23514', 'timbrados_numeracion_valida'])
        ->and(pgFacturacionRechazo($timbrado(['vigente_hasta' => '2026-09-30'])))->toBe(['23514', 'timbrados_vigencia_valida'])
        ->and(pgFacturacionRechazo(fn () => DB::table('timbrados')->where('id', $this->timbrado->id)->update(['numero_actual' => 10000000])))->toBe(['23514', 'timbrados_numeracion_valida']);
});

test('CHECK de los totales: enteros, la suma exacta por tasa, el IVA de cada línea al guaraní; tasa del procedimiento y anulación completa', function () {
    $factura = pgFacturacionFactura();
    $linea = $factura->detalles()->sole();
    $actualizar = fn (array $cambios) => fn () => DB::table('facturas')->where('id', $factura->id)->update($cambios);
    $detalle = fn (array $cambios) => fn () => DB::table('detalles_factura')->where('id', $linea->id)->update($cambios);

    expect([$factura->entero('gravado_10'), $factura->entero('iva_10'), $factura->entero('total')])->toBe([90909, 9091, 100000])
        ->and(pgFacturacionRechazo($actualizar(['total' => 100001])))->toBe(['23514', 'facturas_totales_validos'])
        ->and(pgFacturacionRechazo($actualizar(['iva_10' => 9090.5, 'gravado_10' => 90909.5])))->toBe(['23514', 'facturas_totales_validos'])
        ->and(pgFacturacionRechazo($actualizar(['exento' => -1, 'gravado_10' => 90910])))->toBe(['23514', 'facturas_totales_validos'])
        ->and(pgFacturacionRechazo($actualizar(['gravado_10' => 0, 'iva_10' => 0, 'total' => 0])))->toBe(['23514', 'facturas_totales_validos'])
        // Repartido de otra forma, pero sumando exacto: lo acepta (el IVA por línea lo controla el detalle).
        ->and(pgFacturacionRechazo($actualizar(['gravado_10' => 90000, 'iva_10' => 10000])))->toBeNull()
        ->and(pgFacturacionRechazo($detalle(['iva' => 9090, 'gravado' => 90910])))->toBe(['23514', 'detalles_factura_montos_validos'])
        ->and(pgFacturacionRechazo($detalle(['monto' => 99999])))->toBe(['23514', 'detalles_factura_montos_validos'])
        ->and(pgFacturacionRechazo($detalle(['tasa_iva' => 7])))->toBe(['23514', 'detalles_factura_montos_validos'])
        ->and(pgFacturacionRechazo($detalle(['tasa_iva' => 5, 'iva' => 4762, 'gravado' => 95238])))->toBeNull()
        ->and(pgFacturacionRechazo($detalle(['tasa_iva' => 0, 'iva' => 0, 'gravado' => 100000])))->toBeNull()
        ->and(pgFacturacionRechazo(fn () => DB::table('procedimientos')->update(['tasa_iva' => 12])))->toBe(['23514', 'procedimientos_tasa_iva_valida'])
        ->and(pgFacturacionRechazo($actualizar(['anulada_en' => now()])))->toBe(['23514', 'facturas_anulacion_completa'])
        // Una sola clínica, con el RUC en su formato.
        ->and(pgFacturacionRechazo(fn () => DB::table('clinicas')->insert(pgFacturacionCopia('clinicas', Clinica::actual()->id, ['ruc' => Ruc::conDigito('80999002')]))))
        ->toBe(['23505', 'clinicas_una_sola'])
        ->and(pgFacturacionRechazo(fn () => DB::table('clinicas')->update(['ruc' => '80999001'])))->toBe(['23514', 'clinicas_ruc_formato']);
});

test('la migración, con cobros, cajas y prestaciones ya existentes: no factura ni los cambia', function () {
    DB::table('hitos_sistema')->where('codigo', EmitirFactura::HITO)->update(['fecha_hora' => '2026-10-07 00:00:00']);
    $this->actingAs($this->medico);
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    $cobro = Cobrar::ejecutar($this->cajero, $this->paciente->id, [$prestacion->id], [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => 100000, 'referencia' => null]]);
    expect($cobro->factura_id)->toBeNull();
    $migracion = require base_path('database/migrations/2026_11_02_100001_create_facturacion_individual.php');
    $migracion->down();
    $foto = fn () => [
        (array) DB::table('prestaciones')->where('id', $prestacion->id)->first(),
        collect((array) DB::table('cobros')->where('id', $cobro->id)->first())->except('factura_id')->all(),
        DB::table('cajas')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        DB::table('movimientos_caja')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
    ];
    $antes = $foto();

    $migracion->up();

    expect($foto())->toBe($antes)
        ->and(DB::table('cobros')->where('id', $cobro->id)->value('factura_id'))->toBeNull()
        ->and(DB::table('facturas')->count())->toBe(0)->and(DB::table('detalles_factura')->count())->toBe(0)
        ->and(DB::table('procedimientos')->pluck('tasa_iva')->unique()->values()->all())->toBe([10]);
});
