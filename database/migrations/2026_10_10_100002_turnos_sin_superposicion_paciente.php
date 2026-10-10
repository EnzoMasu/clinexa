<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tercera restricción de no superposición de turnos, por paciente (las otras dos, por profesional y por
 * consultorio, están en 2026_10_03_100004_turnos_sin_superposicion): la paciente no puede tener dos turnos
 * ACTIVOS cuyos intervalos se superpongan, con cualquier profesional.
 *
 * - Activos para la paciente: todos menos CANCELADO y AUSENTE (Turno::NO_ACTIVOS_DE_LA_PACIENTE); ATENDIDO
 *   cuenta. Va como condición WHERE (restricción parcial) con los ids de esos estados, como las otras dos
 *   (PostgreSQL no permite subconsultas ahí).
 * - Usa la columna generada rango (tsrange semiabierto): dos turnos contiguos no se superponen.
 * - La aplicación lo valida antes (TurnoController, Turno::superpuestoDeLaPaciente); esto la respalda ante
 *   dos altas casi simultáneas o una escritura que se saltee la aplicación. Su violación (23P01) se muestra
 *   con el mismo aviso.
 *
 * Solo en PostgreSQL; en SQLite (la suite) la cubre la aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $ids = DB::table('estados')->whereIn('codigo', ['CANCELADO', 'AUSENTE'])->pluck('id');
        if ($ids->count() !== 2) {
            throw new RuntimeException('Faltan los estados CANCELADO y AUSENTE en la tabla estados.');
        }

        DB::statement(sprintf(
            'ALTER TABLE turnos ADD CONSTRAINT turnos_sin_superposicion_paciente EXCLUDE USING gist (paciente_id WITH =, rango WITH &&) WHERE (estado_id NOT IN (%s))',
            $ids->map(fn ($id) => (int) $id)->implode(', '),
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE turnos DROP CONSTRAINT IF EXISTS turnos_sin_superposicion_paciente');
    }
};
