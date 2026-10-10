<?php

use App\Models\Receta;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Lo de las recetas que depende del motor: en PostgreSQL (la base real) la emisión corre con el
 * registro bloqueado (lockForUpdate) dentro de la transacción, el snapshot es jsonb y el número es
 * único. Lo demás está en tests/Feature/HistoriaClinica/RecetasTest.php.
 */
beforeEach(function () {
    hcEscenario();
    $this->medico = hcDarPermisos($this->medico, HC_PERMISOS_RECETAS);
    $this->actingAs($this->medico);
    $this->consulta = hcConsultaEnCurso(); // las recetas se cargan con la consulta EN_CURSO
});

afterEach(fn () => Carbon::setTestNow());

test('emite con número, snapshot jsonb y versión con microsegundos', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00.654321', 'UTC'));
    $receta = hcReceta($this->consulta);
    expect($receta->fresh()->version())->toEndWith('.654321');

    hcEmitir($receta)->assertSessionHas('status', 'Receta RE-0000001 emitida.');

    expect(DB::selectOne("select jsonb_typeof(snapshot) as tipo, snapshot->'paciente'->>'ficha' as ficha from recetas where id = ?", [$receta->id]))
        ->tipo->toBe('object')->ficha->toBe('FP-0000001');
    $this->get(route('admin.recetas.imprimir', $receta))->assertOk()->assertSee('Receta N° RE-0000001');
});

test('el número es único en la base', function () {
    hcEmitir(hcReceta($this->consulta));
    $otra = hcReceta($this->consulta);

    $codigo = null;
    try {
        DB::transaction(fn () => $otra->forceFill(['numero' => 'RE-0000001'])->save());
    } catch (QueryException $e) {
        $codigo = $e->getCode();
    }
    expect($codigo)->toBe('23505')->and(Receta::siguienteNumero())->toBe('RE-0000002');
});
