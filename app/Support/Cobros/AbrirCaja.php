<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\Moneda;
use App\Models\MovimientoCaja;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Abre la caja del cajero (docs/cobros-caja.md) con su monto inicial en efectivo: queda ABIERTA, del día de hoy, y el monto inicial
 * (si no es cero) es su primer movimiento, en Efectivo. Una sola abierta por cajero: si ya tiene una, se rechaza (también lo
 * frena `cajas_una_abierta_por_cajero`). Queda en Auditoría como CREAR ("Apertura de caja"). El permiso (CREAR sobre CAJAS) lo
 * controla la ruta.
 */
final class AbrirCaja
{
    public const YA_ABIERTA = 'Ya tiene una caja abierta: ciérrela antes de abrir otra.';

    public const SIN_EFECTIVO = 'Falta el medio de pago Efectivo en el catálogo: avise al Administrador.';

    public static function ejecutar(User $cajero, int $montoInicial): Caja
    {
        if ($montoInicial < 0) {
            throw new AccionRechazada('El monto inicial no puede ser negativo.');
        }
        $efectivo = MedioPago::efectivo();
        if ($montoInicial > 0 && $efectivo === null) {
            throw new AccionRechazada(self::SIN_EFECTIVO);
        }

        try {
            return DB::transaction(function () use ($cajero, $montoInicial, $efectivo) {
                // Bloquea al usuario: dos aperturas simultáneas del mismo cajero se ordenan (y la segunda se rechaza).
                DB::table('users')->where('id', $cajero->id)->lockForUpdate()->first();
                if (Caja::query()->abiertaDe($cajero)->exists()) {
                    throw new AccionRechazada(self::YA_ABIERTA);
                }

                return Auditoria::conDetalle('Apertura de caja', function () use ($cajero, $montoInicial, $efectivo) {
                    $ahora = now();
                    $caja = new Caja([
                        'usuario_id' => $cajero->id,
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
