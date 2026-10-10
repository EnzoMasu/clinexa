<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo de atención (parte 1): estados nuevos, ciclo de vida de la consulta y autoría de lo que se
 * carga en la preparación. Aditiva: no borra nada.
 *
 * - Estados nuevos del catálogo compartido (idempotente): EN_CONSULTA y SALTADO (turno);
 *   EN_PREPARACION, EN_CURSO y FINALIZADO (consulta). Qué módulo los usa lo define ModuloSistemaSeeder.
 * - consultas: estado_id, iniciada_en, preparada_en, finalizada_en; el motivo pasa a opcional (es
 *   obligatorio solo al finalizar). fecha_hora queda como el momento de creación; la fecha de la
 *   atención es iniciada_en.
 * - Autoría: quién creó y quién modificó cada bloque de anamnesis; quién cargó los signos vitales y
 *   quién los hallazgos del examen.
 *
 * Backfill, verificado uno por uno: toda consulta existente ya estaba terminada (se guardaba de una
 * vez), así que pasa a FINALIZADO con iniciada_en = finalizada_en = fecha_hora; la autoría es el usuario
 * del profesional de la consulta (users.persona_id = profesionales.persona_id), o NULL si no tiene.
 */
return new class extends Migration
{
    /** Código => nombre que se muestra. */
    private const ESTADOS = [
        'EN_CONSULTA' => 'EN CONSULTA',
        'SALTADO' => 'SALTADO',
        'EN_PREPARACION' => 'EN PREPARACIÓN',
        'EN_CURSO' => 'EN CURSO',
        'FINALIZADO' => 'FINALIZADO',
    ];

    public function up(): void
    {
        $ahora = now();
        foreach (self::ESTADOS as $codigo => $nombre) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $nombre, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }
        $finalizado = DB::table('estados')->where('codigo', 'FINALIZADO')->value('id');

        Schema::table('consultas', function (Blueprint $table) {
            $table->foreignId('estado_id')->nullable()->after('profesional_id')->constrained('estados');
            $table->timestamp('iniciada_en')->nullable()->after('fecha_hora');
            $table->timestamp('preparada_en')->nullable()->after('iniciada_en');
            $table->timestamp('finalizada_en')->nullable()->after('preparada_en');
            $table->text('motivo_consulta')->nullable()->change();
            $table->index(['profesional_id', 'estado_id']);
        });

        Schema::table('bloques_anamnesis', function (Blueprint $table) {
            $table->foreignId('usuario_id')->nullable()->constrained('users');
            $table->foreignId('modificado_por_id')->nullable()->constrained('users');
        });

        Schema::table('examenes_fisicos', function (Blueprint $table) {
            $table->foreignId('signos_usuario_id')->nullable()->constrained('users');
            $table->foreignId('hallazgos_usuario_id')->nullable()->constrained('users');
        });

        DB::table('consultas')->orderBy('id')->get(['id', 'fecha_hora', 'profesional_id'])->each(function (object $consulta) use ($finalizado) {
            DB::table('consultas')->where('id', $consulta->id)->update([
                'estado_id' => $finalizado,
                'iniciada_en' => $consulta->fecha_hora,
                'finalizada_en' => $consulta->fecha_hora,
            ]);

            // Autoría: el usuario del profesional de la consulta, si tiene.
            $usuario = DB::table('users')->join('profesionales', 'profesionales.persona_id', '=', 'users.persona_id')
                ->where('profesionales.id', $consulta->profesional_id)->value('users.id');
            DB::table('bloques_anamnesis')->where('consulta_id', $consulta->id)->update(['usuario_id' => $usuario]);
            $examen = DB::table('examenes_fisicos')->where('consulta_id', $consulta->id)->first();
            if ($examen) {
                DB::table('examenes_fisicos')->where('id', $examen->id)->update([
                    'signos_usuario_id' => $usuario,
                    'hallazgos_usuario_id' => $examen->hallazgos === null ? null : $usuario,
                ]);
            }

            $verificada = DB::table('consultas')->where('id', $consulta->id)->first();
            if ((int) $verificada->estado_id !== (int) $finalizado || $verificada->iniciada_en === null || $verificada->finalizada_en === null) {
                throw new RuntimeException("No se pudo completar la consulta {$consulta->id}.");
            }
        });

        if (DB::table('consultas')->whereNull('estado_id')->exists()) {
            throw new RuntimeException('Quedaron consultas sin estado.');
        }

        Schema::table('consultas', function (Blueprint $table) {
            $table->foreignId('estado_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('examenes_fisicos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signos_usuario_id');
            $table->dropConstrainedForeignId('hallazgos_usuario_id');
        });
        Schema::table('bloques_anamnesis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('usuario_id');
            $table->dropConstrainedForeignId('modificado_por_id');
        });
        Schema::table('consultas', function (Blueprint $table) {
            $table->dropIndex(['profesional_id', 'estado_id']);
            $table->dropConstrainedForeignId('estado_id');
            $table->dropColumn(['iniciada_en', 'preparada_en', 'finalizada_en']);
        });
        // El motivo vuelve a obligatorio solo si ninguna consulta lo tiene vacío; los estados del
        // catálogo se conservan (pueden estar en uso).
    }
};
