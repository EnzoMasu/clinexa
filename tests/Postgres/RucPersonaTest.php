<?php

use App\Models\Persona;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * El RUC de la persona es único solo cuando no es nulo: índice único parcial de PostgreSQL (docs/ruc.md). Base de prueba
 * clinexa_test; lo demás (formato, dígito, formulario) está en tests/Feature/Admin/RucPersonaTest.php.
 */
test('el índice único del RUC es parcial: rechaza un RUC repetido y admite muchas personas sin RUC', function () {
    expect(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('clinexa_test')
        ->and(DB::selectOne("select indexdef from pg_indexes where indexname = 'personas_ruc_unico'")->indexdef)
        ->toContain('UNIQUE')->toContain('(ruc)')->toContain('WHERE (ruc IS NOT NULL)');

    Persona::factory()->count(3)->create(); // sin RUC: no chocan entre sí
    Persona::factory()->create(['ruc' => '80080099-0']);

    // Cada intento en su propia transacción (savepoint): si la base lo rechaza, el test sigue.
    $otra = fn (string $ruc) => DB::transaction(fn () => Persona::factory()->create(['ruc' => $ruc]));
    expect(fn () => $otra('80080099-0'))->toThrow(QueryException::class)
        ->and($otra('44444401-7'))->ruc->toBe('44444401-7');
});

test('una persona jurídica puede no tener documento (tipo y número vacíos)', function () {
    $juridica = Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Prueba S.A.', 'tipo_documento_id' => null, 'nro_documento' => null, 'ruc' => '80080099-0']);

    expect($juridica->fresh())->tipo_documento_id->toBeNull()->nro_documento->toBeNull()->ruc->toBe('80080099-0');
});
