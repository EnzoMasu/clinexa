<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\CierreCaja;
use App\Models\CierreCajaMedio;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El cierre de una caja por su cajero (docs/cobros-caja.md): declara lo contado en cada medio; el sistema calcula lo esperado
 * (Caja::esperadoPorMedio: el monto inicial solo en efectivo, más los cobros) y la diferencia (contado - esperado). Si algún medio
 * tiene diferencia, el motivo es obligatorio. La caja pasa a CERRADO (pendiente de aprobación) y ya no recibe cobros. Una caja
 * DEVUELTA se cierra igual, con otro cierre (el anterior se conserva). Lo contado en efectivo es cero o más; en los demás medios
 * puede ser negativo (lo esperado lo es cuando se devolvió por ese medio más de lo que entró). Queda en Auditoría: CREAR del cierre y de sus medios, EDITAR
 * de la caja. El permiso (EDITAR sobre CAJAS) lo controla la ruta.
 */
final class CerrarCaja
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public const EFECTIVO_NEGATIVO = 'Lo contado en efectivo no puede ser negativo.';

    public const FALTA_MOTIVO = 'Lo contado no coincide con lo esperado: indique el motivo de la diferencia (al menos 5 caracteres).';

    /**
     * Los medios que se declaran al cerrar: los que tienen movimientos en la caja y los activos (Efectivo primero).
     *
     * @return Collection<int, MedioPago>
     */
    public static function mediosADeclarar(Caja $caja): Collection
    {
        $conMovimientos = array_keys($caja->esperadoPorMedio());

        return MedioPago::query()->with('estado')->get()
            ->filter(fn (MedioPago $medio) => $medio->estaActivo() || in_array($medio->id, $conMovimientos, true))
            ->sortBy(fn (MedioPago $medio) => [$medio->esEfectivo() ? 0 : 1, $medio->nombre])->values();
    }

    /** @param  array<int, int>  $contado  medio de pago => lo contado, en guaraníes enteros */
    public static function ejecutar(User $cajero, Caja $caja, array $contado, ?string $motivo): CierreCaja
    {
        $motivo = trim((string) $motivo);

        return DB::transaction(function () use ($cajero, $caja, $contado, $motivo) {
            $actual = Caja::query()->whereKey($caja->getKey())->lockForUpdate()->firstOrFail();
            if ($actual->usuario_id !== $cajero->id) {
                throw new AccionRechazada('Solo el cajero de la caja puede cerrarla.');
            }
            if (! $actual->abierta() && ! $actual->devuelta()) {
                throw new AccionRechazada('Esta caja ya está cerrada.');
            }

            $esperado = $actual->esperadoPorMedio();
            $medios = self::mediosADeclarar($actual);
            $filas = [];
            foreach ($medios as $medio) {
                if (! array_key_exists($medio->id, $contado)) {
                    throw new AccionRechazada('Declare lo contado en todos los medios de pago (cero si no hay nada).');
                }
                if ($medio->esEfectivo() && (int) $contado[$medio->id] < 0) {
                    throw new AccionRechazada(self::EFECTIVO_NEGATIVO);
                }
                $filas[$medio->id] = ['esperado' => $esperado[$medio->id] ?? 0, 'contado' => (int) $contado[$medio->id], 'es_efectivo' => $medio->esEfectivo()];
            }
            if (array_diff(array_keys($contado), $medios->modelKeys()) !== []) {
                throw new AccionRechazada('Uno de los medios de pago declarados no corresponde a esta caja.');
            }
            $conDiferencia = collect($filas)->contains(fn (array $fila) => $fila['contado'] !== $fila['esperado']);
            if ($conDiferencia && mb_strlen($motivo) < self::MOTIVO_MINIMO) {
                throw new AccionRechazada(self::FALTA_MOTIVO);
            }

            return Auditoria::conDetalle("Cierre de la caja {$actual->id}", function () use ($cajero, $actual, $filas, $motivo) {
                $cierre = new CierreCaja([
                    'caja_id' => $actual->id, 'usuario_id' => $cajero->id, 'cerrado_en' => now(),
                    'motivo_diferencia' => $motivo === '' ? null : mb_substr($motivo, 0, self::MOTIVO_MAXIMO),
                ]);
                $cierre->estado_id = Estado::idDe(Estado::PENDIENTE);
                $cierre->save();
                foreach ($filas as $medioId => $fila) {
                    CierreCajaMedio::create([
                        'cierre_caja_id' => $cierre->id, 'medio_pago_id' => $medioId,
                        'esperado' => $fila['esperado'], 'contado' => $fila['contado'], 'diferencia' => $fila['contado'] - $fila['esperado'],
                        'es_efectivo' => $fila['es_efectivo'],
                    ]);
                }
                $actual->forceFill(['estado_id' => Estado::idDe(Estado::CERRADO), 'cerrada_en' => $cierre->cerrado_en])->save();
                $actual->unsetRelation('estado');

                return $cierre;
            });
        });
    }
}
