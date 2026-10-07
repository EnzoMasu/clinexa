<?php

use App\Enums\AccionAuditoria;
use App\Support\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * El trigger de PostgreSQL rechaza UPDATE, DELETE y TRUNCATE sobre logs_auditoria aunque se
 * hagan por fuera de la aplicación (consultas directas). La protección del modelo está probada
 * en tests/Feature/Auditoria.
 */
beforeEach(function () {
    Auditoria::registrar(AccionAuditoria::INICIO_SESION_FALLIDO, 'users', detalle: 'Correo: x@example.com', usuarioId: null);
    $this->id = DB::table('logs_auditoria')->value('id');
});

/** Ejecuta la sentencia en un savepoint: si la base la rechaza, la transacción del test sigue usable. */
function intentarEnLog(Closure $sentencia): void
{
    DB::transaction($sentencia);
}

test('los triggers están creados', function () {
    expect(collect(DB::select("select tgname from pg_trigger where tgrelid = 'logs_auditoria'::regclass and not tgisinternal"))->pluck('tgname')->sort()->values()->all())
        ->toBe(['logs_auditoria_sin_cambios', 'logs_auditoria_sin_truncate']);
});

test('la base rechaza UPDATE', function () {
    expect(fn () => intentarEnLog(fn () => DB::table('logs_auditoria')->where('id', $this->id)->update(['detalle' => 'cambiado'])))
        ->toThrow(QueryException::class, 'solo agregar: no se puede UPDATE');

    expect(DB::table('logs_auditoria')->where('id', $this->id)->value('detalle'))->toBe('Correo: x@example.com');
});

test('la base rechaza DELETE', function () {
    expect(fn () => intentarEnLog(fn () => DB::table('logs_auditoria')->where('id', $this->id)->delete()))
        ->toThrow(QueryException::class, 'no se puede DELETE');

    expect(DB::table('logs_auditoria')->count())->toBe(1);
});

test('la base rechaza TRUNCATE', function () {
    expect(fn () => intentarEnLog(fn () => DB::statement('TRUNCATE logs_auditoria')))
        ->toThrow(QueryException::class, 'no se puede TRUNCATE');

    expect(DB::table('logs_auditoria')->count())->toBe(1);
});

test('agregar sí se puede', function () {
    Auditoria::registrar(AccionAuditoria::CIERRE_SESION, 'users', usuarioId: null);

    expect(DB::table('logs_auditoria')->count())->toBe(2);
});
