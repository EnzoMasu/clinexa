<?php

use App\Models\CategoriaGasto;
use App\Models\Estado;
use App\Models\Moneda;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\TipoComprobanteCompra;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de las compras en PostgreSQL (docs/compras-gastos.md): el índice único parcial del comprobante (sin contar las
 * anuladas ni las compras sin número) y cada CHECK (total, desglose de IVA, condición/vencimiento/anticipo, confirmación por otra
 * persona, anulación con motivo, formato legal solo con número). Lo demás está en tests/Feature/Compras. Datos inventados. Prefijo
 * "pgCompras".
 */
beforeEach(function () {
    $this->carga = User::factory()->create();
    $this->gerente = User::factory()->create();
    $this->proveedor = Proveedor::create(['persona_id' => Persona::factory()->create()->id]);
    $this->categoria = CategoriaGasto::create(['nombre' => 'Insumos inventados']);
});

/** El código y la restricción con que la base rechaza la sentencia (en su propio savepoint), o null si la acepta. */
function pgComprasRechazo(Closure $sentencia): ?array
{
    try {
        DB::transaction($sentencia);
    } catch (QueryException $e) {
        $restricciones = ['compras_comprobante_unico', 'compras_total_positivo', 'compras_desglose_valido', 'compras_condicion_valida',
            'compras_confirmacion_completa', 'compras_anulacion_completa', 'tipos_comprobante_compra_formato_con_numero'];

        return [$e->getCode(), collect($restricciones)->first(fn (string $nombre) => str_contains($e->getMessage(), $nombre))];
    }

    return null;
}

/** Un ticket al contado en borrador, con lo que se pase encima. */
function pgComprasCompra(array $datos = []): int
{
    return DB::table('compras')->insertGetId([
        'proveedor_id' => test()->proveedor->id, 'categoria_gasto_id' => test()->categoria->id, 'fecha' => '2026-10-05',
        'tipo_comprobante_id' => TipoComprobanteCompra::where('nombre', 'Ticket')->value('id'), 'numero_comprobante' => 'T-0001',
        'fecha_comprobante' => '2026-10-05', 'moneda_id' => Moneda::guarani()->id, 'total' => 25000, 'condicion' => 'CONTADO',
        'anticipo' => 0, 'estado_id' => Estado::idDe(Estado::PENDIENTE), 'registrada_por' => test()->carga->id,
        'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]);
}

function pgComprasAnulada(): array
{
    return ['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_por' => test()->carga->id, 'anulada_en' => now(), 'motivo_anulacion' => 'Cargada por error'];
}

test('corre de verdad contra PostgreSQL, con las restricciones y el índice creados', function () {
    $restricciones = fn (string $tabla) => collect(DB::select("select conname from pg_constraint where conrelid = '{$tabla}'::regclass and contype = 'c'"))
        ->pluck('conname')->sort()->values()->all();
    $indices = collect(DB::select('select indexname from pg_indexes where tablename = ?', ['compras']))->pluck('indexname')->all();

    expect(DB::getDriverName())->toBe('pgsql')->and(DB::connection()->getDatabaseName())->toBe('clinexa_test')
        ->and($restricciones('compras'))->toBe(['compras_anulacion_completa', 'compras_condicion_valida', 'compras_confirmacion_completa',
            'compras_desglose_valido', 'compras_total_positivo'])
        ->and($restricciones('tipos_comprobante_compra'))->toBe(['tipos_comprobante_compra_formato_con_numero'])
        ->and($indices)->toContain('compras_comprobante_unico');
});

test('compras_comprobante_unico: el mismo comprobante no se repite; anulada la original, se acepta; sin número no choca', function () {
    $original = pgComprasCompra();

    expect(pgComprasRechazo(fn () => pgComprasCompra()))->toBe(['23505', 'compras_comprobante_unico'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra(['numero_comprobante' => 'T-0002'])))->toBeNull()
        ->and(pgComprasRechazo(fn () => pgComprasCompra(['proveedor_id' => Proveedor::create(['persona_id' => Persona::factory()->create()->id])->id])))->toBeNull()
        ->and(pgComprasRechazo(fn () => pgComprasCompra(['tipo_comprobante_id' => TipoComprobanteCompra::where('nombre', 'Recibo')->value('id')])))->toBeNull();

    DB::table('compras')->where('id', $original)->update(pgComprasAnulada());
    expect(pgComprasRechazo(fn () => pgComprasCompra()))->toBeNull();

    $sin = ['tipo_comprobante_id' => TipoComprobanteCompra::where('nombre', 'Sin comprobante')->value('id'), 'numero_comprobante' => null, 'fecha_comprobante' => null];
    expect(pgComprasRechazo(fn () => pgComprasCompra($sin)))->toBeNull()
        ->and(pgComprasRechazo(fn () => pgComprasCompra($sin)))->toBeNull();
});

test('total mayor que cero y desglose todo o nada, con el total igual a la suma', function () {
    $desglose = ['monto_10' => 110000, 'monto_5' => 21000, 'monto_exento' => 0, 'iva_10' => 10000, 'iva_5' => 1000, 'total' => 131000];

    expect(pgComprasRechazo(fn () => pgComprasCompra(['total' => 0])))->toBe(['23514', 'compras_total_positivo'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra(['total' => -5])))->toBe(['23514', 'compras_total_positivo'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$desglose, 'numero_comprobante' => 'T-1'])))->toBeNull()
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$desglose, 'numero_comprobante' => 'T-2', 'total' => 131001])))->toBe(['23514', 'compras_desglose_valido'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$desglose, 'numero_comprobante' => 'T-3', 'iva_5' => null])))->toBe(['23514', 'compras_desglose_valido'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra(['numero_comprobante' => 'T-4', 'monto_10' => 25000])))->toBe(['23514', 'compras_desglose_valido'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$desglose, 'numero_comprobante' => 'T-5', 'monto_5' => -21000, 'monto_exento' => 42000])))->toBe(['23514', 'compras_desglose_valido']);
});

test('contado sin vencimiento ni anticipo; crédito con vencimiento no anterior a la fecha y anticipo menor al total', function () {
    $credito = ['condicion' => 'CREDITO', 'vencimiento' => '2026-11-05', 'anticipo' => 10000];

    expect(pgComprasRechazo(fn () => pgComprasCompra(['numero_comprobante' => 'C-1', 'vencimiento' => '2026-11-05'])))->toBe(['23514', 'compras_condicion_valida'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra(['numero_comprobante' => 'C-2', 'anticipo' => 1000])))->toBe(['23514', 'compras_condicion_valida'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$credito, 'numero_comprobante' => 'C-3'])))->toBeNull()
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$credito, 'numero_comprobante' => 'C-4', 'vencimiento' => '2026-10-05', 'anticipo' => 0])))->toBeNull()
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$credito, 'numero_comprobante' => 'C-5', 'vencimiento' => null])))->toBe(['23514', 'compras_condicion_valida'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$credito, 'numero_comprobante' => 'C-6', 'vencimiento' => '2026-10-04'])))->toBe(['23514', 'compras_condicion_valida'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$credito, 'numero_comprobante' => 'C-7', 'anticipo' => 25000])))->toBe(['23514', 'compras_condicion_valida'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$credito, 'numero_comprobante' => 'C-8', 'anticipo' => -1])))->toBe(['23514', 'compras_condicion_valida'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra(['numero_comprobante' => 'C-9', 'condicion' => 'OTRA'])))->toBe(['23514', 'compras_condicion_valida']);
});

test('la confirmación va completa y nunca la hace quien cargó; la anulación va completa con motivo de 5 a 300, también la propia', function () {
    $confirmada = ['estado_id' => Estado::idDe(Estado::CONFIRMADO), 'confirmada_por' => test()->gerente->id, 'confirmada_en' => now()];

    expect(pgComprasRechazo(fn () => pgComprasCompra([...$confirmada, 'numero_comprobante' => 'F-1'])))->toBeNull()
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$confirmada, 'numero_comprobante' => 'F-2', 'confirmada_por' => test()->carga->id])))->toBe(['23514', 'compras_confirmacion_completa'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...$confirmada, 'numero_comprobante' => 'F-3', 'confirmada_en' => null])))->toBe(['23514', 'compras_confirmacion_completa'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...pgComprasAnulada(), 'numero_comprobante' => 'A-1'])))->toBeNull()
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...pgComprasAnulada(), 'numero_comprobante' => 'A-2', 'motivo_anulacion' => null])))->toBe(['23514', 'compras_anulacion_completa'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...pgComprasAnulada(), 'numero_comprobante' => 'A-3', 'motivo_anulacion' => 'Mal'])))->toBe(['23514', 'compras_anulacion_completa'])
        ->and(pgComprasRechazo(fn () => pgComprasCompra([...pgComprasAnulada(), 'numero_comprobante' => 'A-4', 'anulada_por' => null])))->toBe(['23514', 'compras_anulacion_completa']);
});

test('un tipo de comprobante no exige formato legal sin número', function () {
    $tipo = fn (array $casillas) => DB::table('tipos_comprobante_compra')->insert(['nombre' => 'Tipo '.uniqid(), ...$casillas,
        'estado_id' => Estado::idDe(Estado::ACTIVO), 'created_at' => now(), 'updated_at' => now()]);

    expect(pgComprasRechazo(fn () => $tipo(['requiere_numero' => false, 'numero_formato_legal' => true])))->toBe(['23514', 'tipos_comprobante_compra_formato_con_numero'])
        ->and(pgComprasRechazo(fn () => $tipo(['requiere_numero' => true, 'numero_formato_legal' => true])))->toBeNull();
});
