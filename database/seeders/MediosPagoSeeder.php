<?php

namespace Database\Seeders;

use App\Models\Estado;
use App\Models\MedioPago;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los medios de pago de referencia (docs/cobros-caja.md): Efectivo, Transferencia y Tarjeta (POS), con su código. Si ya hay uno
 * sin código con ese nombre (o uno de sus alias), lo adopta: le pone el código y no toca nada más. Nunca cambia el nombre ni el
 * estado de uno existente. Idempotente. Con DB::table, para que la migración que lo llama no falle en `migrate --pretend`.
 */
class MediosPagoSeeder extends Seeder
{
    /** codigo => [nombre, alias en minúsculas que se adoptan]. */
    public const REFERENCIA = [
        MedioPago::EFECTIVO => ['Efectivo', ['efectivo']],
        MedioPago::TRANSFERENCIA => ['Transferencia', ['transferencia', 'transferencia bancaria']],
        MedioPago::TARJETA => ['Tarjeta (POS)', ['tarjeta (pos)', 'tarjeta', 'pos']],
    ];

    public function run(): void
    {
        foreach (self::REFERENCIA as $codigo => [$nombre, $alias]) {
            if (DB::table('medios_pago')->where('codigo', $codigo)->exists()) {
                continue;
            }
            $existente = DB::table('medios_pago')->whereNull('codigo')
                ->whereIn(DB::raw('LOWER(nombre)'), $alias)->orderBy('id')->value('id');
            if ($existente !== null) {
                DB::table('medios_pago')->where('id', $existente)->update(['codigo' => $codigo, 'updated_at' => now()]);

                continue;
            }
            DB::table('medios_pago')->insert([
                'nombre' => $nombre,
                'codigo' => $codigo,
                'estado_id' => DB::table('estados')->where('codigo', Estado::ACTIVO)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
