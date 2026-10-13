<?php

use App\Models\Estado;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entre pacientes y urgencias (docs/agenda-urgencias.md):
 *
 * - turnos.tipo (NORMAL, ENTRE_PACIENTES, URGENCIA; enum App\Enums\TipoTurno), default NORMAL: todos los
 *   turnos existentes quedan NORMAL (se verifica).
 * - hora_inicio, hora_fin y consultorio_id pasan a NULL: NORMAL los exige; los otros dos tipos no tienen horas.
 * - llegada_en: cuándo llegó la paciente (hoy solo lo completa la urgencia, al registrarla).
 * - rango: en PostgreSQL, tsrange(NULL, NULL) es un rango ILIMITADO "(,)", no NULL, y se superpondría con
 *   todos. Por eso rango pasa a ser NULL cuando falta alguna hora: con rango NULL, "&&" da NULL y las tres
 *   EXCLUDE no se aplican. Una columna generada no se redefine en todas las versiones: se quitan las tres
 *   EXCLUDE, se rehace rango y se vuelven a crear con la misma definición.
 * - CHECK turnos_tipo_valido y turnos_horas_segun_tipo.
 * - Índice único parcial turnos_una_urgencia_abierta: una sola URGENCIA abierta por paciente, profesional y día.
 * - cupos_entre_pacientes: cuántos "entre pacientes" atiende el profesional ese día (0 a 30).
 *
 * En SQLite (la suite) no hay rango, EXCLUDE ni CHECK: lo cubre la aplicación.
 */
return new class extends Migration
{
    private const RANGO_ANTERIOR = "tsrange(fecha + hora_inicio, fecha + hora_fin, '[)')";

    private const RANGO_NUEVO = "CASE WHEN hora_inicio IS NULL OR hora_fin IS NULL THEN NULL ELSE tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') END";

    public function up(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->string('tipo', 20)->default('NORMAL')->after('profesional_id');
            $table->timestamp('llegada_en')->nullable()->after('observaciones');
            $table->index(['profesional_id', 'fecha', 'tipo']);
        });

        $otros = DB::table('turnos')->where('tipo', '<>', 'NORMAL')->count();
        if ($otros !== 0) {
            throw new RuntimeException("Backfill de turnos.tipo: {$otros} turnos no quedaron NORMAL.");
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->rehacerRango(self::RANGO_NUEVO, function () {
                foreach (['hora_inicio', 'hora_fin', 'consultorio_id'] as $columna) {
                    DB::statement("ALTER TABLE turnos ALTER COLUMN {$columna} DROP NOT NULL");
                }
            });
            DB::statement("ALTER TABLE turnos ADD CONSTRAINT turnos_tipo_valido CHECK (tipo IN ('NORMAL', 'ENTRE_PACIENTES', 'URGENCIA'))");
            DB::statement("ALTER TABLE turnos ADD CONSTRAINT turnos_horas_segun_tipo CHECK (
                (tipo = 'NORMAL' AND hora_inicio IS NOT NULL AND hora_fin IS NOT NULL AND consultorio_id IS NOT NULL)
                OR (tipo <> 'NORMAL' AND hora_inicio IS NULL AND hora_fin IS NULL))");
        } else {
            Schema::table('turnos', function (Blueprint $table) {
                $table->time('hora_inicio')->nullable()->change();
                $table->time('hora_fin')->nullable()->change();
                $table->unsignedBigInteger('consultorio_id')->nullable()->change();
            });
        }

        // Una sola URGENCIA abierta (PENDIENTE, CONFIRMADO, SALTADO o EN_CONSULTA) por paciente, profesional y día:
        // índice único parcial (PostgreSQL y SQLite), así ni un doble envío ni dos altas simultáneas la duplican.
        $abiertos = collect([Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO, Estado::EN_CONSULTA])
            ->map(fn (string $codigo) => (int) (DB::table('estados')->where('codigo', $codigo)->value('id')
                ?? throw new RuntimeException("Falta el estado {$codigo} en la tabla estados.")))
            ->implode(', ');
        DB::statement("CREATE UNIQUE INDEX turnos_una_urgencia_abierta ON turnos (paciente_id, profesional_id, fecha) WHERE tipo = 'URGENCIA' AND estado_id IN ({$abiertos})");

        Schema::create('cupos_entre_pacientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profesional_id')->constrained('profesionales');
            $table->date('fecha');
            $table->unsignedSmallInteger('cantidad');
            $table->foreignId('creado_por')->constrained('users');
            $table->timestamps();

            $table->unique(['profesional_id', 'fecha']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cupos_entre_pacientes ADD CONSTRAINT cupos_entre_pacientes_cantidad_valida CHECK (cantidad BETWEEN 0 AND 30)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cupos_entre_pacientes');
        DB::statement('DROP INDEX IF EXISTS turnos_una_urgencia_abierta');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE turnos DROP CONSTRAINT IF EXISTS turnos_horas_segun_tipo');
            DB::statement('ALTER TABLE turnos DROP CONSTRAINT IF EXISTS turnos_tipo_valido');
            // Falla (a propósito) si hay turnos sin horas: no se pierden datos.
            $this->rehacerRango(self::RANGO_ANTERIOR, function () {
                foreach (['hora_inicio', 'hora_fin', 'consultorio_id'] as $columna) {
                    DB::statement("ALTER TABLE turnos ALTER COLUMN {$columna} SET NOT NULL");
                }
            });
        }

        Schema::table('turnos', function (Blueprint $table) {
            $table->dropIndex(['profesional_id', 'fecha', 'tipo']);
            $table->dropColumn(['tipo', 'llegada_en']);
        });
    }

    /**
     * Quita las tres EXCLUDE y rango, corre $entre, vuelve a crear rango con esa expresión y las tres EXCLUDE
     * con su definición de siempre (2026_10_03_100004 y 2026_10_10_100002).
     */
    private function rehacerRango(string $expresion, \Closure $entre): void
    {
        $id = fn (string $codigo) => (int) (DB::table('estados')->where('codigo', $codigo)->value('id')
            ?? throw new RuntimeException("Falta el estado {$codigo} en la tabla estados."));
        [$cancelado, $ausente] = [$id(Estado::CANCELADO), $id(Estado::AUSENTE)];

        foreach (['profesional', 'consultorio', 'paciente'] as $nombre) {
            DB::statement("ALTER TABLE turnos DROP CONSTRAINT turnos_sin_superposicion_{$nombre}");
        }
        DB::statement('ALTER TABLE turnos DROP COLUMN rango');

        $entre();

        DB::statement("ALTER TABLE turnos ADD COLUMN rango tsrange GENERATED ALWAYS AS ({$expresion}) STORED");
        foreach (['profesional_id', 'consultorio_id'] as $columna) {
            DB::statement(sprintf(
                'ALTER TABLE turnos ADD CONSTRAINT turnos_sin_superposicion_%s EXCLUDE USING gist (%s WITH =, rango WITH &&) WHERE (estado_id <> %d)',
                str_replace('_id', '', $columna), $columna, $cancelado,
            ));
        }
        DB::statement(sprintf(
            'ALTER TABLE turnos ADD CONSTRAINT turnos_sin_superposicion_paciente EXCLUDE USING gist (paciente_id WITH =, rango WITH &&) WHERE (estado_id NOT IN (%d, %d))',
            $cancelado, $ausente,
        ));
    }
};
