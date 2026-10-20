<?php

use App\Models\Estado;
use App\Models\Moneda;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Persona;
use App\Models\Tarifa;
use App\Models\User;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de las tarifas son de PostgreSQL (docs/tarifas-prestaciones.md): EXCLUDE con daterange para que los rangos
 * de un mismo procedimiento y profesional no se solapen, y los CHECK del monto. Base de prueba clinexa_test. Lo demás (alta,
 * cambio, búsqueda, prestaciones) está en tests/Feature/Economico. Precios inventados.
 */
beforeEach(function () {
    $this->seed(ModuloSistemaSeeder::class);
    $this->procedimiento = Procedimiento::create(['codigo' => 'PRUEBA-1', 'nombre' => 'Procedimiento de prueba', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);
    $usuario = User::factory()->create();

    // Cada inserción en su propia transacción (savepoint): si la base la rechaza, el test sigue.
    $this->tarifa = fn (array $datos) => DB::transaction(fn () => DB::table('tarifas')->insert([
        'procedimiento_id' => $this->procedimiento->id, 'profesional_id' => null, 'monto' => 100000, 'moneda_id' => Moneda::guarani()->id,
        'vigente_desde' => '2026-10-01', 'vigente_hasta' => null, 'estado_id' => Estado::idDe(Estado::VIGENTE), 'usuario_id' => $usuario->id,
        'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]));
    $this->historica = ['estado_id' => Estado::idDe(Estado::HISTORICO)];
});

test('corre de verdad contra PostgreSQL, con las restricciones creadas', function () {
    expect(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('clinexa_test')
        ->and(collect(DB::select("select conname from pg_constraint where conrelid = 'tarifas'::regclass and contype in ('x', 'c')"))->pluck('conname')->sort()->values()->all())
        ->toBe(['tarifas_monto_positivo', 'tarifas_rango_valido', 'tarifas_sin_solapamiento']);
});

test('rechaza rangos que se solapan para el mismo procedimiento y profesional', function () {
    ($this->tarifa)([...$this->historica, 'vigente_desde' => '2026-10-01', 'vigente_hasta' => '2026-10-10']);

    expect(fn () => ($this->tarifa)([...$this->historica, 'vigente_desde' => '2026-10-10', 'vigente_hasta' => '2026-10-20']))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23P01')->and($e->getMessage())->toContain('tarifas_sin_solapamiento'));
    // Una abierta que empieza dentro del rango cerrado, también.
    expect(fn () => ($this->tarifa)(['vigente_desde' => '2026-10-05']))->toThrow(QueryException::class);
    expect(Tarifa::count())->toBe(1);
});

test('rangos consecutivos, otro profesional, la general y una reemplazada el mismo día conviven', function () {
    ($this->tarifa)([...$this->historica, 'vigente_desde' => '2026-10-01', 'vigente_hasta' => '2026-10-10']);
    ($this->tarifa)([...$this->historica, 'vigente_desde' => '2026-10-11', 'vigente_hasta' => '2026-10-10']); // sin vigencia
    ($this->tarifa)(['vigente_desde' => '2026-10-11']);
    ($this->tarifa)(['profesional_id' => $this->profesional->id, 'vigente_desde' => '2026-10-01']);

    expect(Tarifa::count())->toBe(4);
});

test('una sola abierta por procedimiento y profesional, monto positivo y rango válido', function () {
    ($this->tarifa)(['vigente_desde' => '2026-10-01']);

    expect(fn () => ($this->tarifa)(['vigente_desde' => '2027-01-01']))->toThrow(QueryException::class); // índice tarifas_una_vigente (y el solape)
    expect(fn () => ($this->tarifa)(['profesional_id' => $this->profesional->id, 'monto' => 0]))
        ->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('tarifas_monto_positivo'));
    expect(fn () => ($this->tarifa)([...$this->historica, 'profesional_id' => $this->profesional->id, 'vigente_desde' => '2026-10-10', 'vigente_hasta' => '2026-10-01']))
        ->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('tarifas_rango_valido'));
    expect(Tarifa::count())->toBe(1);
});
