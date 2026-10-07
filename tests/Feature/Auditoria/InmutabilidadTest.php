<?php

use App\Enums\AccionAuditoria;
use App\Models\LogAuditoria;
use App\Support\Auditoria;

/*
 * El modelo LogAuditoria no deja modificar ni borrar registros, ni de a uno ni en masa. (El trigger
 * de PostgreSQL, que cubre también las consultas directas, se prueba en tests/Postgres.)
 */
beforeEach(function () {
    Auditoria::registrar(AccionAuditoria::INICIO_SESION_FALLIDO, 'users', detalle: 'Correo: x@example.com', usuarioId: null);
    $this->log = LogAuditoria::sole();
});

test('no se puede editar un registro', function () {
    $this->log->detalle = 'cambiado';

    expect(fn () => $this->log->save())->toThrow(LogicException::class, 'no se puede modificar ni borrar');
    expect(LogAuditoria::sole()->detalle)->toBe('Correo: x@example.com');
});

test('no se puede borrar un registro', function () {
    expect(fn () => $this->log->delete())->toThrow(LogicException::class);
    expect(LogAuditoria::count())->toBe(1);
});

test('tampoco en masa: update, delete ni truncate', function () {
    expect(fn () => LogAuditoria::query()->update(['detalle' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => LogAuditoria::where('id', $this->log->id)->delete())->toThrow(LogicException::class)
        ->and(fn () => LogAuditoria::truncate())->toThrow(LogicException::class);

    expect(LogAuditoria::sole()->detalle)->toBe('Correo: x@example.com');
});
