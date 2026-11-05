<?php

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\CierreCaja;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\CerrarCaja;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\RevisarCierre;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Arqueo de caja en PostgreSQL (docs/cobros-caja.md): `cajas.sucursal_id` NOT NULL, y concurrencia de verdad con DOS conexiones a
 * clinexa_test (como EntrePacientesConcurrenciaTest): mientras A cierra la caja, B no cobra con ella (espera el bloqueo: 55P03) y,
 * cuando A confirma, ya no hay caja para cobrar; mientras A cobra, B no la cierra; una recepción y una corrección no se cruzan. Este
 * test CONFIRMA sus datos (fuera de la transacción de RefreshDatabase) y los borra al final, por id (solo lo que creó). Datos inventados.
 */
beforeEach(function () {
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');

    DB::commit(); // cierra la transacción de RefreshDatabase: lo que sigue queda confirmado
    $this->sucursal = Sucursal::create(['nombre' => 'Sucursal Arqueo Concurrente', 'direccion' => 'Calle Inventada 1', 'telefono' => '0900']);
    $this->cajero = User::factory()->sinPerfiles()->create();
    $this->gerente = User::factory()->sinPerfiles()->create();
    $this->caja = AbrirCaja::ejecutar($this->cajero, 0, $this->sucursal->id);
});

afterEach(function () {
    DB::setDefaultConnection('pgsql');
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::connection('pgsql_b')->rollBack(0);
    DB::disconnect('pgsql_b');

    // Solo lo que creó este test, por id.
    $cajas = DB::table('cajas')->where('usuario_id', $this->cajero->id)->pluck('id');
    $cierres = DB::table('cierres_caja')->whereIn('caja_id', $cajas)->pluck('id');
    DB::table('cierre_caja_medios')->whereIn('cierre_caja_id', $cierres)->delete();
    DB::table('cierres_caja')->whereIn('id', $cierres)->delete();
    DB::table('movimientos_caja')->whereIn('caja_id', $cajas)->delete();
    DB::table('cajas')->whereIn('id', $cajas)->delete();
    DB::table('sucursales')->where('id', $this->sucursal->id)->delete();
    $personas = [$this->cajero->persona_id, $this->gerente->persona_id];
    DB::table('usuario_perfil')->whereIn('usuario_id', [$this->cajero->id, $this->gerente->id])->delete();
    DB::table('users')->whereIn('id', [$this->cajero->id, $this->gerente->id])->delete();
    DB::table('personas')->whereIn('id', $personas)->delete();

    DB::beginTransaction(); // la que RefreshDatabase espera deshacer al terminar
});

/** Corre $accion en la conexión B con un tiempo de espera de bloqueo corto. */
function pgArqueoCajaEnB(Closure $accion): mixed
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

/** Lo esperado de cada medio a declarar (sin diferencia). */
function pgArqueoCajaContado(Caja $caja): array
{
    $esperado = $caja->esperadoPorMedio();

    return CerrarCaja::mediosADeclarar($caja)->mapWithKeys(fn (MedioPago $medio) => [$medio->id => $esperado[$medio->id] ?? 0])->all();
}

test('la sucursal de la caja es NOT NULL en la base', function () {
    $columna = DB::selectOne("select is_nullable from information_schema.columns where table_name = 'cajas' and column_name = 'sucursal_id'");
    expect(DB::connection()->getDatabaseName())->toBe('clinexa_test')->and($columna->is_nullable)->toBe('NO')
        ->and($this->caja->fresh()->sucursal_id)->toBe($this->sucursal->id);

    $sinSucursal = (array) DB::table('cajas')->where('id', $this->caja->id)->first();
    unset($sinSucursal['id']);
    expect(fn () => DB::transaction(fn () => DB::table('cajas')->insert([...$sinSucursal, 'sucursal_id' => null, 'cerrada_en' => now()])))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23502'));
});

test('mientras A cierra la caja, B no cobra con ella; confirmado el cierre, ya no hay caja para cobrar', function () {
    DB::beginTransaction(); // A: cierra y todavía no confirma
    CerrarCaja::ejecutar($this->cajero, $this->caja, pgArqueoCajaContado($this->caja), null);

    expect(fn () => pgArqueoCajaEnB(fn () => Cobrar::cajaParaCobrar($this->cajero)))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03')); // B espera el bloqueo de A

    DB::commit();
    expect(fn () => pgArqueoCajaEnB(fn () => Cobrar::cajaParaCobrar($this->cajero)))->toThrow(AccionRechazada::class, Cobrar::SIN_CAJA);
});

test('mientras A tiene la caja tomada para cobrar, B no la cierra', function () {
    DB::beginTransaction(); // A: toma la caja para cobrar
    Cobrar::cajaParaCobrar($this->cajero);

    expect(fn () => pgArqueoCajaEnB(fn () => CerrarCaja::ejecutar($this->cajero, $this->caja, pgArqueoCajaContado($this->caja), null)))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));

    DB::commit();
    pgArqueoCajaEnB(fn () => CerrarCaja::ejecutar($this->cajero, $this->caja, pgArqueoCajaContado($this->caja), null));
    expect($this->caja->fresh()->cerrada())->toBeTrue();
});

test('una recepción en curso y un reenvío no se cruzan: el segundo espera y después ve el estado nuevo', function () {
    CerrarCaja::ejecutar($this->cajero, $this->caja, pgArqueoCajaContado($this->caja), null);
    RevisarCierre::devolver($this->gerente, $this->caja, 'Observación inventada.');

    DB::beginTransaction(); // A: el cajero reenvía y todavía no confirma
    CerrarCaja::ejecutar($this->cajero, $this->caja, pgArqueoCajaContado($this->caja), null);

    expect(fn () => pgArqueoCajaEnB(fn () => RevisarCierre::aprobar($this->gerente, $this->caja)))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));

    DB::commit();
    pgArqueoCajaEnB(fn () => RevisarCierre::aprobar($this->gerente, $this->caja));
    expect($this->caja->fresh()->tieneEstado(Estado::APROBADO))->toBeTrue()
        ->and(CierreCaja::where('caja_id', $this->caja->id)->count())->toBe(2);
});

test('una devolución y una apertura del mismo cajero no se cruzan: con la caja devuelta, no abre otra', function () {
    CerrarCaja::ejecutar($this->cajero, $this->caja, pgArqueoCajaContado($this->caja), null);

    DB::beginTransaction(); // A: Gerencia devuelve y todavía no confirma
    RevisarCierre::devolver($this->gerente, $this->caja, 'Observación inventada.');

    expect(fn () => pgArqueoCajaEnB(fn () => AbrirCaja::ejecutar($this->cajero, 0, $this->sucursal->id)))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));

    DB::commit();
    expect(fn () => pgArqueoCajaEnB(fn () => AbrirCaja::ejecutar($this->cajero, 0, $this->sucursal->id)))
        ->toThrow(AccionRechazada::class, AbrirCaja::CON_DEVUELTA);
    expect(Caja::where('usuario_id', $this->cajero->id)->count())->toBe(1);
});
