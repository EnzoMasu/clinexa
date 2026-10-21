<?php

use App\Models\AcuerdoReparto;
use App\Models\Equipo;
use App\Models\Estado;
use App\Models\Persona;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Proveedor;
use App\Models\TipoEquipo;
use App\Models\User;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Las restricciones de los acuerdos de reparto y de las líneas son de PostgreSQL (docs/reparto-economico.md): EXCLUDE con
 * daterange para que los rangos de una misma clave no se solapen, y los CHECK del porcentaje, de la clave y del beneficiario.
 * Base de prueba clinexa_test. Lo demás (alta, cambio, cálculo, pantallas) está en tests/Feature/Economico. Datos inventados.
 */
beforeEach(function () {
    $this->seed(ModuloSistemaSeeder::class);
    $this->procedimiento = Procedimiento::create(['codigo' => 'PRUEBA-1', 'nombre' => 'Procedimiento de prueba', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);
    $this->equipo = Equipo::create(['nombre' => 'Equipo de prueba', 'tipo_equipo_id' => TipoEquipo::create(['nombre' => 'Tipo de prueba'])->id,
        'proveedor_id' => Proveedor::create(['persona_id' => Persona::factory()->create()->id])->id]);
    $usuario = User::factory()->create();

    // Cada inserción en su propia transacción (savepoint): si la base la rechaza, el test sigue.
    $this->acuerdo = fn (array $datos) => DB::transaction(fn () => DB::table('acuerdos_reparto')->insert([
        'tipo' => 'PROFESIONAL', 'profesional_id' => $this->profesional->id, 'procedimiento_id' => null, 'equipo_id' => null, 'porcentaje' => 40,
        'vigente_desde' => '2026-10-01', 'vigente_hasta' => null, 'estado_id' => Estado::idDe(Estado::VIGENTE), 'usuario_id' => $usuario->id,
        'created_at' => now(), 'updated_at' => now(), ...$datos,
    ]));
    $this->historico = ['estado_id' => Estado::idDe(Estado::HISTORICO)];
    $this->deEquipo = ['tipo' => 'EQUIPO', 'profesional_id' => null, 'equipo_id' => $this->equipo->id];
});

test('corre de verdad contra PostgreSQL, con las restricciones creadas', function () {
    expect(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('clinexa_test')
        ->and(collect(DB::select("select conname from pg_constraint where conrelid = 'acuerdos_reparto'::regclass and contype in ('x', 'c')"))->pluck('conname')->sort()->values()->all())
        ->toBe(['acuerdos_reparto_clave_valida', 'acuerdos_reparto_porcentaje_valido', 'acuerdos_reparto_rango_valido', 'acuerdos_reparto_sin_solapamiento'])
        ->and(collect(DB::select("select conname from pg_constraint where conrelid = 'lineas_reparto'::regclass and contype = 'c'"))->pluck('conname')->sort()->values()->all())
        ->toBe(['lineas_reparto_beneficiario_valido', 'lineas_reparto_valores_validos']);
});

test('solape: rechaza rangos que se solapan para el mismo profesional y procedimiento, y para el mismo equipo', function () {
    ($this->acuerdo)([...$this->historico, 'vigente_desde' => '2026-10-01', 'vigente_hasta' => '2026-10-10']);
    ($this->acuerdo)([...$this->deEquipo, ...$this->historico, 'vigente_desde' => '2026-10-01', 'vigente_hasta' => '2026-10-10']);

    expect(fn () => ($this->acuerdo)([...$this->historico, 'vigente_desde' => '2026-10-10', 'vigente_hasta' => '2026-10-20']))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23P01')->and($e->getMessage())->toContain('acuerdos_reparto_sin_solapamiento'));
    expect(fn () => ($this->acuerdo)(['vigente_desde' => '2026-10-05']))->toThrow(QueryException::class);
    expect(fn () => ($this->acuerdo)([...$this->deEquipo, 'vigente_desde' => '2026-10-05']))
        ->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('acuerdos_reparto_sin_solapamiento'));
    expect(AcuerdoReparto::count())->toBe(2);
});

test('solape: rangos consecutivos, la excepción por procedimiento, el equipo y uno reemplazado el mismo día conviven', function () {
    ($this->acuerdo)([...$this->historico, 'vigente_desde' => '2026-10-01', 'vigente_hasta' => '2026-10-10']);
    ($this->acuerdo)([...$this->historico, 'vigente_desde' => '2026-10-11', 'vigente_hasta' => '2026-10-10']); // sin vigencia
    ($this->acuerdo)(['vigente_desde' => '2026-10-11']);
    ($this->acuerdo)(['procedimiento_id' => $this->procedimiento->id, 'vigente_desde' => '2026-10-01']);
    ($this->acuerdo)([...$this->deEquipo, 'vigente_desde' => '2026-10-01']);

    expect(AcuerdoReparto::count())->toBe(5);
});

test('uno solo abierto por clave, porcentaje de 0 a 100, rango válido y clave coherente con el tipo', function () {
    ($this->acuerdo)(['vigente_desde' => '2026-10-01']);

    expect(fn () => ($this->acuerdo)(['vigente_desde' => '2027-01-01']))->toThrow(QueryException::class); // acuerdos_reparto_una_vigente (y el solape)
    expect(fn () => ($this->acuerdo)(['procedimiento_id' => $this->procedimiento->id, 'porcentaje' => 100.01]))->toThrow(QueryException::class);
    expect(fn () => ($this->acuerdo)(['procedimiento_id' => $this->procedimiento->id, 'porcentaje' => -1]))
        ->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('acuerdos_reparto_porcentaje_valido'));
    expect(fn () => ($this->acuerdo)([...$this->historico, 'procedimiento_id' => $this->procedimiento->id, 'vigente_desde' => '2026-10-10', 'vigente_hasta' => '2026-10-01']))
        ->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('acuerdos_reparto_rango_valido'));
    expect(fn () => ($this->acuerdo)([...$this->deEquipo, 'procedimiento_id' => $this->procedimiento->id]))
        ->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('acuerdos_reparto_clave_valida'));
    expect(fn () => ($this->acuerdo)(['tipo' => 'EQUIPO']))
        ->toThrow(fn (QueryException $e) => expect($e->getMessage())->toContain('acuerdos_reparto_clave_valida'));
    expect(AcuerdoReparto::count())->toBe(1);
});
