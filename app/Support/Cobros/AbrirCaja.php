<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\Moneda;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Abre la caja del cajero (docs/cobros-caja.md) con su monto inicial en efectivo: queda ABIERTA, del día de hoy, y el monto inicial
 * (si no es cero) es su primer movimiento, en Efectivo. Una sola abierta por cajero: si ya tiene una, se rechaza (también lo
 * frena `cajas_una_abierta_por_cajero`), y con una caja DEVUELTA por diferencias tampoco abre otra (con una pendiente de recepción
 * sí). La caja es de una sucursal: si hay una sola activa se asigna sola; si hay más, el cajero la elige. Queda en Auditoría como CREAR ("Apertura de caja"). El permiso (CREAR sobre CAJAS) lo
 * controla la ruta.
 */
final class AbrirCaja
{
    public const YA_ABIERTA = 'Ya tiene una caja abierta: ciérrela antes de abrir otra.';

    public const SIN_EFECTIVO = 'Falta el medio de pago Efectivo en el catálogo: avise al Administrador.';

    public const CON_DEVUELTA = 'Tiene una caja devuelta por diferencias: corríjala y reenvíela antes de abrir otra.';

    public const SIN_SUCURSAL = 'No hay una sucursal activa: avise al Administrador.';

    public const ELIJA_SUCURSAL = 'Elija la sucursal de la caja.';

    /** Las sucursales en las que se puede abrir una caja: las activas. */
    public static function sucursales(): Collection
    {
        return Sucursal::activos()->orderBy('nombre')->get();
    }

    /** La sucursal de la caja nueva: la única activa, o la elegida (que debe estar activa). */
    public static function sucursalDeLaCaja(?int $elegida): Sucursal
    {
        $activas = self::sucursales();
        if ($activas->isEmpty()) {
            throw new AccionRechazada(self::SIN_SUCURSAL);
        }
        if ($activas->count() === 1) {
            return $activas->first();
        }

        return $activas->firstWhere('id', $elegida) ?? throw new AccionRechazada(self::ELIJA_SUCURSAL);
    }

    public static function ejecutar(User $cajero, int $montoInicial, ?int $sucursalId = null): Caja
    {
        if ($montoInicial < 0) {
            throw new AccionRechazada('El monto inicial no puede ser negativo.');
        }
        $efectivo = MedioPago::efectivo();
        if ($montoInicial > 0 && $efectivo === null) {
            throw new AccionRechazada(self::SIN_EFECTIVO);
        }
        $sucursal = self::sucursalDeLaCaja($sucursalId);

        try {
            return DB::transaction(function () use ($cajero, $montoInicial, $efectivo, $sucursal) {
                // Bloquea al usuario: dos aperturas simultáneas del mismo cajero se ordenan (y la segunda se rechaza).
                DB::table('users')->where('id', $cajero->id)->lockForUpdate()->first();
                if (Caja::query()->abiertaDe($cajero)->exists()) {
                    throw new AccionRechazada(self::YA_ABIERTA);
                }
                if (Caja::query()->where('usuario_id', $cajero->id)->where('estado_id', Estado::idDe(Estado::DEVUELTO))->exists()) {
                    throw new AccionRechazada(self::CON_DEVUELTA);
                }

                return Auditoria::conDetalle('Apertura de caja', function () use ($cajero, $montoInicial, $efectivo, $sucursal) {
                    $ahora = now();
                    $caja = new Caja([
                        'usuario_id' => $cajero->id,
                        'sucursal_id' => $sucursal->id,
                        'fecha' => Fecha::hoy()->format('Y-m-d'),
                        'abierta_en' => $ahora,
                        'monto_inicial' => $montoInicial,
                        'moneda_id' => Moneda::guarani()->id,
                    ]);
                    $caja->estado_id = Estado::idDe(Estado::ABIERTO);
                    $caja->save();

                    if ($montoInicial > 0) {
                        MovimientoCaja::create([
                            'caja_id' => $caja->id, 'tipo' => MovimientoCaja::APERTURA, 'medio_pago_id' => $efectivo->id,
                            'monto' => $montoInicial, 'usuario_id' => $cajero->id, 'registrado_en' => $ahora,
                        ]);
                    }

                    return $caja;
                });
            });
        } catch (QueryException $e) {
            // La otra apertura ganó por el índice único parcial.
            if (str_contains($e->getMessage(), 'cajas_una_abierta_por_cajero') || str_contains($e->getMessage(), 'cajas.usuario_id')) {
                throw new AccionRechazada(self::YA_ABIERTA);
            }
            throw $e;
        }
    }
}
