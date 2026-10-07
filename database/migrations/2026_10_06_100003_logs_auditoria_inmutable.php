<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El log de auditoría es de solo agregar también en la base: un trigger rechaza UPDATE y DELETE
 * (por fila) y TRUNCATE (por sentencia) sobre logs_auditoria, venga de donde venga (la aplicación
 * ya lo impide en App\Models\LogAuditoria; esto cubre consultas directas).
 *
 * Solo en PostgreSQL, como la restricción de los turnos: SQLite, que usa la suite normal, no tiene
 * triggers de TRUNCATE. Su test va en tests/Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION logs_auditoria_inmutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'El log de auditoría es de solo agregar: no se puede % sobre logs_auditoria.', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER logs_auditoria_sin_cambios
                BEFORE UPDATE OR DELETE ON logs_auditoria
                FOR EACH ROW EXECUTE FUNCTION logs_auditoria_inmutable();

            CREATE TRIGGER logs_auditoria_sin_truncate
                BEFORE TRUNCATE ON logs_auditoria
                FOR EACH STATEMENT EXECUTE FUNCTION logs_auditoria_inmutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS logs_auditoria_sin_truncate ON logs_auditoria;
            DROP TRIGGER IF EXISTS logs_auditoria_sin_cambios ON logs_auditoria;
            DROP FUNCTION IF EXISTS logs_auditoria_inmutable();
            SQL);
    }
};
