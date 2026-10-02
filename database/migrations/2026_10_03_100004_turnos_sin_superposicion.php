<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La base impide que dos turnos se superpongan para el mismo profesional o para el mismo
 * consultorio, salvo que uno esté CANCELADO (su horario quedó libre).
 *
 * - rango: columna generada (STORED) tsrange [fecha + hora_inicio, fecha + hora_fin). Semiabierto:
 *   un turno de 08:00-08:30 y otro de 08:30-09:00 no se pisan.
 * - Dos EXCLUDE (uno por profesional, otro por consultorio): un único EXCLUDE con "profesional O
 *   consultorio" no existe; con dos, se rechaza si choca cualquiera de los dos.
 * - El "excepto CANCELADO" es la condición WHERE de cada EXCLUDE (restricción parcial). PostgreSQL
 *   no permite subconsultas ahí, así que lleva el id del estado CANCELADO (de la tabla estados,
 *   que se siembra por migración con ids fijos).
 * - btree_gist hace falta para combinar "=" sobre un bigint con "&&" sobre el rango en un índice GiST.
 * - Además, hora_fin > hora_inicio.
 *
 * Solo en PostgreSQL (la base real y la de los tests de tests/Postgres): SQLite, que usa el resto de
 * la suite, no tiene tsrange ni EXCLUDE. Ahí la no superposición la cubre la aplicación (la agenda
 * solo ofrece horarios libres y se recalcula al guardar).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $cancelado = DB::table('estados')->where('codigo', 'CANCELADO')->value('id')
            ?? throw new RuntimeException('Falta el estado CANCELADO en la tabla estados.');

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement('ALTER TABLE turnos ADD CONSTRAINT turnos_horario_valido CHECK (hora_fin > hora_inicio)');

        DB::statement("ALTER TABLE turnos ADD COLUMN rango tsrange GENERATED ALWAYS AS (tsrange(fecha + hora_inicio, fecha + hora_fin, '[)')) STORED");

        foreach (['profesional_id', 'consultorio_id'] as $columna) {
            DB::statement(sprintf(
                'ALTER TABLE turnos ADD CONSTRAINT turnos_sin_superposicion_%s EXCLUDE USING gist (%s WITH =, rango WITH &&) WHERE (estado_id <> %d)',
                str_replace('_id', '', $columna), $columna, (int) $cancelado,
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE turnos DROP CONSTRAINT IF EXISTS turnos_sin_superposicion_profesional');
        DB::statement('ALTER TABLE turnos DROP CONSTRAINT IF EXISTS turnos_sin_superposicion_consultorio');
        DB::statement('ALTER TABLE turnos DROP COLUMN IF EXISTS rango');
        DB::statement('ALTER TABLE turnos DROP CONSTRAINT IF EXISTS turnos_horario_valido');
    }
};
