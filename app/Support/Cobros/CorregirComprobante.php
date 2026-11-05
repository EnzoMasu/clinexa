<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Cobro;
use App\Models\Estado;
use App\Models\LineaCobro;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * Corrige el número de comprobante de una línea de cobro (docs/cobros-caja.md). Solo el comprobante: el medio y el monto no se
 * cambian (moverían plata entre medios de una caja). Reglas: el cobro sigue vigente, su caja no está aprobada, el medio no es
 * efectivo, y si el medio exige comprobante no puede quedar vacío; el motivo es obligatorio (5 a 300). No tiene que ser único.
 * Queda en Auditoría como EDITAR de la línea (quién, cuándo, valor anterior y nuevo) con el motivo. El permiso (EDITAR sobre COBROS)
 * lo controla la ruta.
 */
final class CorregirComprobante
{
    public static function ejecutar(User $usuario, LineaCobro $linea, ?string $comprobante, string $motivo): LineaCobro
    {
        $comprobante = trim((string) $comprobante);
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < RevertirCobro::MOTIVO_MINIMO || mb_strlen($motivo) > RevertirCobro::MOTIVO_MAXIMO) {
            throw new AccionRechazada('Indique el motivo de la corrección ('.RevertirCobro::MOTIVO_MINIMO.' a '.RevertirCobro::MOTIVO_MAXIMO.' caracteres).');
        }
        if (mb_strlen($comprobante) > Cobrar::REFERENCIA_MAXIMA) {
            throw new AccionRechazada('El comprobante no puede tener más de '.Cobrar::REFERENCIA_MAXIMA.' caracteres.');
        }

        return DB::transaction(function () use ($linea, $comprobante, $motivo) {
            $cobro = Cobro::query()->with('caja.estado')->lockForUpdate()->findOrFail($linea->cobro_id);
            $actual = LineaCobro::query()->with('medioPago')->lockForUpdate()->findOrFail($linea->id);
            if ($cobro->revertido()) {
                throw new AccionRechazada('El cobro está revertido: su comprobante ya no se corrige.');
            }
            if ($cobro->caja->estado->codigo === Estado::APROBADO) {
                throw new AccionRechazada('La caja de este cobro ya está aprobada: su comprobante ya no se corrige.');
            }
            $medio = $actual->medioPago;
            if ($medio->esEfectivo()) {
                throw new AccionRechazada('El efectivo no lleva comprobante.');
            }
            if ($comprobante === '' && $medio->exigeComprobante()) {
                throw new AccionRechazada($medio->mensajeComprobanteFaltante());
            }
            if ($comprobante === (string) $actual->referencia) {
                throw new AccionRechazada('El comprobante no cambió.');
            }

            Auditoria::conDetalle("Corrección del comprobante del cobro {$cobro->id}. Motivo: {$motivo}", fn () => $actual->corregirComprobante($comprobante === '' ? null : $comprobante));

            return $actual;
        });
    }
}
