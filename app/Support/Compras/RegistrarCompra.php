<?php

namespace App\Support\Compras;

use App\Exceptions\AccionRechazada;
use App\Exceptions\CompraDuplicada;
use App\Models\Compra;
use App\Models\Moneda;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Alta y edición del borrador de una compra (docs/compras-gastos.md). Recibe los datos ya validados por CompraRequest (fechas en ISO,
 * importes enteros, comprobante según las casillas del tipo). Nace en PENDIENTE ("Borrador") en guaraníes; con un tipo que discrimina
 * IVA guarda el desglose y el IVA (IvaCompra), y si no, solo el total. Un comprobante (proveedor + tipo + número) una sola vez entre
 * las compras no anuladas: si ya está, lanza CompraDuplicada con la existente (las ANULADAS no cuentan; sin número, no se controla).
 * El índice único parcial de la base respalda el control si dos cargas coinciden.
 */
final class RegistrarCompra
{
    public static function registrar(User $usuario, array $datos): Compra
    {
        return self::sinChoques($datos, fn () => DB::transaction(function () use ($usuario, $datos) {
            self::verificarDuplicado($datos);

            return Compra::create([
                ...$datos,
                'moneda_id' => Moneda::guarani()->id,
                'registrada_por' => $usuario->id,
            ]);
        }));
    }

    public static function actualizar(Compra $compra, array $datos): Compra
    {
        return self::sinChoques($datos, fn () => DB::transaction(function () use ($compra, $datos) {
            $actual = Compra::query()->whereKey($compra->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->esBorrador()) {
                throw new AccionRechazada('Solo se edita una compra en borrador.');
            }
            self::verificarDuplicado($datos, $actual);
            $actual->update($datos);

            return $actual;
        }), $compra);
    }

    /** La compra no anulada que ya tiene ese comprobante (otra que $excepto), o null. */
    public static function existente(array $datos, ?Compra $excepto = null): ?Compra
    {
        if (($datos['numero_comprobante'] ?? null) === null) {
            return null;
        }

        return Compra::query()->whereNull('anulada_en')
            ->where('proveedor_id', $datos['proveedor_id'])
            ->where('tipo_comprobante_id', $datos['tipo_comprobante_id'])
            ->where('numero_comprobante', $datos['numero_comprobante'])
            ->when($excepto, fn ($consulta) => $consulta->whereKeyNot($excepto->getKey()))
            ->first();
    }

    private static function verificarDuplicado(array $datos, ?Compra $excepto = null): void
    {
        if ($existente = self::existente($datos, $excepto)) {
            throw new CompraDuplicada($existente);
        }
    }

    /**
     * Si la base rechaza por el índice único (dos cargas a la vez), se informa el duplicado como arriba. Va fuera de la transacción:
     * en PostgreSQL una transacción con error no admite más consultas.
     */
    private static function sinChoques(array $datos, \Closure $guardar, ?Compra $excepto = null): Compra
    {
        try {
            return $guardar();
        } catch (UniqueConstraintViolationException $e) {
            $existente = self::existente($datos, $excepto);
            throw $existente ? new CompraDuplicada($existente) : $e;
        }
    }
}
