<?php

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * El índice único parcial de users.administrador_principal en PostgreSQL (base de prueba clinexa_test): a lo
 * sumo un usuario en true, cualquier cantidad en false. Datos inventados.
 */
test('PostgreSQL: el índice único parcial admite un solo administrador principal', function () {
    $indice = DB::selectOne("select indexdef from pg_indexes where tablename = 'users' and indexname = 'users_un_solo_administrador_principal'");
    expect($indice?->indexdef)->toContain('UNIQUE')->toContain('WHERE (administrador_principal = true)');

    [$uno, $otro] = User::factory()->count(2)->create();
    User::factory()->count(3)->create(); // varios en false: el índice es parcial

    DB::table('users')->where('id', $uno->id)->update(['administrador_principal' => true]);
    // En un savepoint: el error de unicidad no deja abortada la transacción del test.
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $otro->id)->update(['administrador_principal' => true])))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('users')->where('administrador_principal', true)->pluck('id')->all())->toBe([$uno->id])
        ->and(DB::table('users')->where('administrador_principal', false)->count())->toBe(4);
});
