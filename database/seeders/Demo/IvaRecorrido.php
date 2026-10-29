<?php

namespace Database\Seeders\Demo;

use App\Models\Procedimiento;
use App\Support\Auditoria;

/**
 * Recorrido manual (docs/recorrido-manual.md): tasas de IVA distintas por procedimiento para probar la factura con varias tasas
 * (como TasaIvaController, a nombre de Gerencia). Las demás quedan en 10 %. Idempotente: solo cambia lo que difiere.
 */
class IvaRecorrido extends EtapaDemo
{
    /** codigo => tasa. Ecografía Transvaginal y Obstétrica + Doppler al 5 %, Pap + Colposcopía exenta. */
    public const TASAS = ['ECO-TV' => 5, 'ECO-OBST-DOP' => 5, 'PAP-COLPO' => 0];

    public function run(): void
    {
        $this->como($this->correo('gerencia'), function () {
            foreach (self::TASAS as $codigo => $tasa) {
                $procedimiento = Procedimiento::where('codigo', $codigo)->firstOrFail();
                if ($procedimiento->tasa_iva !== $tasa) {
                    Auditoria::conDetalle('Cambio de la tasa de IVA', fn () => $procedimiento->update(['tasa_iva' => $tasa]));
                    $this->contar('tasas de IVA');
                }
            }
        });
    }
}
