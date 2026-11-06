<?php

use App\Models\Estado;
use App\Models\Persona;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Facturacion\EmitirFactura;
use App\Support\Ruc;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * La numeración de las facturas bajo concurrencia, con DOS conexiones a clinexa_test (docs/facturacion.md): la contado y la de crédito
 * toman el número con el timbrado bloqueado (EmitirFactura::timbradoParaEmitir(bloquear: true) y el avance condicionado de
 * `numero_actual` en EmitirFactura::registrar). Mientras A tiene el timbrado bloqueado, B espera (lock_timeout corto => 55P03); cuando A
 * confirma, B ve el número que usó A y toma el siguiente: ni repetidos ni saltos. Para que B vea los datos, este test los CONFIRMA (fuera
 * de la transacción de RefreshDatabase) y los borra al final, uno por uno por id (solo lo que creó). Datos inventados. Prefijo
 * "pgNumeracion".
 */
beforeEach(function () {
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');

    DB::commit(); // cierra la transacción de RefreshDatabase: lo que sigue queda confirmado
    $this->persona = Persona::factory()->create(['apellidos' => 'Concurrente', 'nombres' => 'Numeración']);
    $this->usuario = User::factory()->sinPerfiles()->create(['persona_id' => $this->persona->id]);
    $this->sucursalCreada = Sucursal::principal() === null;
    $this->sucursal = Sucursal::principal() ?? Sucursal::create(['nombre' => 'Sucursal de prueba', 'direccion' => 'Calle Inventada 1', 'telefono' => '021000000']);
    $this->clinicaAnterior = $this->sucursal->clinica_id;
    $this->clinicaCreada = ! DB::table('clinicas')->exists();
    $this->clinicaId = DB::table('clinicas')->value('id') ?? DB::table('clinicas')->insertGetId(['instalacion' => 1, 'tipo_contribuyente' => 'SOCIEDAD',
        'razon_social' => 'Clínica de Prueba S.A.', 'ruc' => Ruc::conDigito('80999001'), 'actividad_economica' => 'Servicios de salud (prueba)',
        'direccion_fiscal' => 'Calle Inventada 123', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sucursales')->where('id', $this->sucursal->id)->update(['clinica_id' => $this->clinicaId]);
    $this->timbradoId = DB::table('timbrados')->insertGetId(['clinica_id' => $this->clinicaId, 'sucursal_id' => $this->sucursal->id, 'numero' => '87654321',
        'vigente_desde' => now()->subMonth()->format('Y-m-d'), 'vigente_hasta' => now()->addYear()->format('Y-m-d'), 'establecimiento' => '009', 'punto_expedicion' => '009',
        'numero_inicial' => 1, 'numero_final' => 9999999, 'numero_actual' => 0, 'estado_id' => Estado::idDe(Estado::VIGENTE), 'cargado_por' => $this->usuario->id,
        'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function () {
    DB::setDefaultConnection('pgsql');
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::connection('pgsql_b')->rollBack(0);
    DB::disconnect('pgsql_b');

    // Solo lo que creó este test, por id.
    DB::table('timbrados')->where('id', $this->timbradoId)->delete();
    DB::table('sucursales')->where('id', $this->sucursal->id)->update(['clinica_id' => $this->clinicaAnterior]);
    if ($this->clinicaCreada) {
        DB::table('clinicas')->where('id', $this->clinicaId)->delete();
    }
    if ($this->sucursalCreada) {
        DB::table('sucursales')->where('id', $this->sucursal->id)->delete();
    }
    DB::table('users')->where('id', $this->usuario->id)->delete();
    DB::table('personas')->where('id', $this->persona->id)->delete();

    DB::beginTransaction(); // la que RefreshDatabase espera deshacer al terminar
});

/** Corre $accion en la conexión B con un tiempo de espera de bloqueo corto. */
function pgNumeracionEnB(Closure $accion): mixed
{
    DB::setDefaultConnection('pgsql_b');
    try {
        return DB::transaction(function () use ($accion) {
            DB::statement("SET LOCAL lock_timeout = '700ms'");

            return $accion();
        });
    } finally {
        DB::setDefaultConnection('pgsql');
    }
}

/** Lo que hace EmitirFactura::registrar con el timbrado bloqueado: el número siguiente, solo si nadie lo tomó antes. */
function pgNumeracionTomar(): int
{
    [$timbrado, $aviso] = EmitirFactura::timbradoParaEmitir(bloquear: true);
    expect($aviso)->toBeNull();
    $avanzo = DB::table('timbrados')->where('id', $timbrado->id)->where('numero_actual', $timbrado->numero_actual)
        ->update(['numero_actual' => $timbrado->numero_actual + 1]);
    expect($avanzo)->toBe(1);

    return $timbrado->numero_actual + 1;
}

test('dos emisiones casi simultáneas: la segunda espera el timbrado bloqueado y después toma el número siguiente, sin repetir ni saltear', function () {
    DB::beginTransaction(); // A: toma el número y todavía no confirma
    expect(pgNumeracionTomar())->toBe(1);

    expect(fn () => pgNumeracionEnB(fn () => pgNumeracionTomar()))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03')); // B espera el bloqueo de A

    DB::commit(); // A confirma
    expect(pgNumeracionEnB(fn () => pgNumeracionTomar()))->toBe(2)
        ->and(DB::table('timbrados')->where('id', $this->timbradoId)->value('numero_actual'))->toBe(2);

    // Y si alguien se saltea el bloqueo con un número viejo, el avance condicionado no hace nada (no repite el 2).
    expect(DB::table('timbrados')->where('id', $this->timbradoId)->where('numero_actual', 1)->update(['numero_actual' => 2]))->toBe(0);
});
