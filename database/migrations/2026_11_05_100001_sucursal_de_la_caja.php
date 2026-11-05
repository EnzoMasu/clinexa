<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La sucursal de cada caja (docs/cobros-caja.md, arqueo). Aditiva: `cajas.sucursal_id`. Las cajas existentes toman la única sucursal
 * que hay; si hay cajas y no hay exactamente una sucursal, se detiene sin cambiar nada (hay que decidir a mano). No toca cobros ni
 * importes. En PostgreSQL queda NOT NULL; en SQLite lo exige el modelo (y se
 * restaura el índice único parcial, que SQLite pierde al reconstruir la tabla). No lee filas durante `migrate --pretend`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hayCajas = DB::table('cajas')->exists();
        if ($hayCajas) {
            $sucursales = DB::table('sucursales')->pluck('id');
            if ($sucursales->count() !== 1) {
                throw new RuntimeException("Hay cajas y {$sucursales->count()} sucursales: no se puede asignar la sucursal de las cajas existentes sola.");
            }
        }

        $this->reconstruyendoEnSqlite(fn () => Schema::table('cajas', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales');
        }));

        if ($hayCajas) {
            DB::table('cajas')->whereNull('sucursal_id')->update(['sucursal_id' => $sucursales->first()]);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cajas ALTER COLUMN sucursal_id SET NOT NULL');
        }
    }

    public function down(): void
    {
        $this->reconstruyendoEnSqlite(fn () => Schema::table('cajas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sucursal_id');
        }));
    }

    /**
     * SQLite reconstruye la tabla al agregar o quitar una clave foránea: sin desactivar las claves foráneas no puede soltar la tabla
     * vieja (la referencian cobros y movimientos), y pierde el WHERE del índice único parcial (quedaría una sola caja por cajero en
     * toda su historia), que se vuelve a crear igual que en `create_cobros_y_caja`. En PostgreSQL solo corre el cambio.
     */
    private function reconstruyendoEnSqlite(Closure $cambio): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $cambio();

            return;
        }
        // Dentro de una transacción el PRAGMA foreign_keys no cambia: se difieren los controles hasta el final.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        // La reconstrucción lo copiaría sin el WHERE (y fallaría con dos cajas del mismo cajero): se suelta antes y se crea después.
        DB::statement('DROP INDEX IF EXISTS cajas_una_abierta_por_cajero');
        Schema::withoutForeignKeyConstraints($cambio);
        DB::statement('CREATE UNIQUE INDEX cajas_una_abierta_por_cajero ON cajas (usuario_id) WHERE cerrada_en IS NULL');
    }
};
