<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\CategoriaGasto;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\SalidaCaja;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registra una salida de dinero de la caja (docs/cobros-caja.md): un retiro, gasto o pago menor, siempre en efectivo, desde la caja
 * ABIERTA de hoy de su cajero (una pendiente de recepción, devuelta o recibida no admite salidas). Sin aprobación previa ni tope. El
 * efectivo esperado tiene que alcanzar: la caja nunca queda con efectivo negativo. El egreso (MovimientoCaja::SALIDA, en negativo)
 * baja lo esperado en efectivo del cierre. Independiente de Compras por ahora (`compra_id` vacío).
 *
 * Queda en Auditoría como CREAR de la salida (quién, cuándo, monto, categoría, beneficiario, comprobante y concepto). El permiso (CREAR
 * sobre SALIDAS_CAJA) lo controla la ruta.
 */
final class RegistrarSalida
{
    public const CONCEPTO_MINIMO = 5;

    public const CONCEPTO_MAXIMO = 300;

    public const BENEFICIARIO_MAXIMO = 150;

    public const COMPROBANTE_MAXIMO = 60;

    public const SIN_CAJA = 'Abra su caja antes de registrar una salida.';

    public const NO_ABIERTA = 'Esta caja no está abierta: solo la caja abierta de hoy admite salidas.';

    public const SIN_EFECTIVO = 'Falta el medio de pago Efectivo en el catálogo: avise al Administrador.';

    public const SIN_CATEGORIA = 'Elija una categoría de gasto activa.';

    /** Las categorías que se ofrecen en el formulario: las de gasto activas (sin pedir permiso de su catálogo, como los demás formularios). */
    public static function categorias(): Collection
    {
        return CategoriaGasto::activos()->orderBy('nombre')->get();
    }

    /** La caja, bloqueada, si es la abierta de hoy del cajero; si no, se rechaza. */
    public static function cajaDeHoy(User $cajero, Caja $caja): Caja
    {
        $actual = Caja::query()->whereKey($caja->getKey())->lockForUpdate()->firstOrFail();
        if ($actual->usuario_id !== $cajero->id) {
            throw new AccionRechazada('Solo el cajero de la caja registra salidas en ella.');
        }
        if (! $actual->abierta() || $actual->cerrada_en !== null) {
            throw new AccionRechazada(self::NO_ABIERTA);
        }
        if ($actual->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d')) {
            throw new AccionRechazada('Su caja está abierta desde el '.Fecha::mostrar($actual->fecha).': ciérrela y abra la de hoy para registrar la salida.');
        }

        return $actual;
    }

    /** @param  array{categoria_gasto_id: int, beneficiario: string, comprobante: ?string, concepto: string, monto: int}  $datos */
    public static function ejecutar(User $cajero, Caja $caja, array $datos): SalidaCaja
    {
        $monto = (int) $datos['monto'];
        $beneficiario = trim((string) $datos['beneficiario']);
        $concepto = trim((string) $datos['concepto']);
        $comprobante = trim((string) ($datos['comprobante'] ?? ''));
        if ($monto <= 0) {
            throw new AccionRechazada('El monto de la salida tiene que ser mayor que cero.');
        }
        if ($beneficiario === '') {
            throw new AccionRechazada('Indique el beneficiario de la salida.');
        }
        if (mb_strlen($concepto) < self::CONCEPTO_MINIMO) {
            throw new AccionRechazada('Indique el concepto de la salida (al menos '.self::CONCEPTO_MINIMO.' caracteres).');
        }
        $categoria = CategoriaGasto::activos()->whereKey((int) $datos['categoria_gasto_id'])->first() ?? throw new AccionRechazada(self::SIN_CATEGORIA);
        $efectivo = MedioPago::efectivo() ?? throw new AccionRechazada(self::SIN_EFECTIVO);

        return DB::transaction(function () use ($cajero, $caja, $monto, $beneficiario, $concepto, $comprobante, $categoria, $efectivo) {
            $actual = self::cajaDeHoy($cajero, $caja);
            $disponible = $actual->esperadoPorMedio()[$efectivo->id] ?? 0;
            if ($disponible < $monto) {
                $moneda = $actual->moneda;
                throw new AccionRechazada('No alcanza el efectivo de la caja para esta salida de '.$moneda->formatear($monto)
                    .' (hay '.$moneda->formatear($disponible).'): la caja no puede quedar con efectivo negativo.');
            }

            return Auditoria::conDetalle("Salida de caja: {$categoria->nombre}, a {$beneficiario}", function () use ($cajero, $actual, $monto, $beneficiario, $concepto, $comprobante, $categoria, $efectivo) {
                $ahora = now();
                $salida = new SalidaCaja([
                    'caja_id' => $actual->id, 'categoria_gasto_id' => $categoria->id,
                    'beneficiario' => mb_substr($beneficiario, 0, self::BENEFICIARIO_MAXIMO),
                    'comprobante' => $comprobante === '' ? null : mb_substr($comprobante, 0, self::COMPROBANTE_MAXIMO),
                    'concepto' => mb_substr($concepto, 0, self::CONCEPTO_MAXIMO), 'monto' => $monto, 'medio_pago_id' => $efectivo->id,
                    'usuario_id' => $cajero->id, 'registrada_en' => $ahora,
                ]);
                $salida->estado_id = Estado::idDe(Estado::VIGENTE);
                $salida->save();

                MovimientoCaja::create([
                    'caja_id' => $actual->id, 'tipo' => MovimientoCaja::SALIDA, 'medio_pago_id' => $efectivo->id, 'monto' => -1 * $monto,
                    'salida_caja_id' => $salida->id, 'usuario_id' => $cajero->id, 'registrado_en' => $ahora,
                ]);

                return $salida;
            });
        });
    }
}
