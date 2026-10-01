<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pacientes: fecha_alta (faltaba del diseño original; para los ya cargados, la fecha en que se
 * crearon) y número de ficha con formato "FP-0000003". Los números viejos solo con dígitos
 * ("000002") pasan al formato nuevo con el mismo número; los que no son solo dígitos (cargados a
 * mano, p. ej. de una ficha en papel) no se tocan. Si dos fichas terminaran con el mismo número
 * (p. ej. "2" y "000002", o "2" y un "FP-0000002" ya existente) la migración se detiene sin
 * cambiar nada y las lista.
 *
 * Especialidades del profesional: "activa" para deshabilitar una sin quitarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pacientes', function (Blueprint $table) {
            $table->date('fecha_alta')->nullable()->after('nro_ficha');
        });

        foreach (DB::table('pacientes')->get(['id', 'nro_ficha', 'created_at']) as $paciente) {
            DB::table('pacientes')->where('id', $paciente->id)
                ->update(['fecha_alta' => substr((string) ($paciente->created_at ?? now()), 0, 10)]);
        }

        Schema::table('pacientes', function (Blueprint $table) {
            $table->date('fecha_alta')->nullable(false)->change();
        });

        $this->fichasAlFormatoNuevo();

        Schema::table('profesional_especialidad', function (Blueprint $table) {
            $table->boolean('activa')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('profesional_especialidad', function (Blueprint $table) {
            $table->dropColumn('activa');
        });

        // Vuelta al formato viejo (6 dígitos) de las fichas FP-.
        foreach (DB::table('pacientes')->where('nro_ficha', 'like', 'FP-%')->get(['id', 'nro_ficha']) as $paciente) {
            $numero = substr($paciente->nro_ficha, 3);
            if (ctype_digit($numero)) {
                DB::table('pacientes')->where('id', $paciente->id)->update(['nro_ficha' => str_pad((string) (int) $numero, 6, '0', STR_PAD_LEFT)]);
            }
        }

        Schema::table('pacientes', function (Blueprint $table) {
            $table->dropColumn('fecha_alta');
        });
    }

    private function fichasAlFormatoNuevo(): void
    {
        $fichas = DB::table('pacientes')->pluck('nro_ficha', 'id');
        $viejas = $fichas->filter(fn (string $nro) => ctype_digit($nro));
        $nuevas = $viejas->map(fn (string $nro) => 'FP-'.str_pad((string) (int) $nro, 7, '0', STR_PAD_LEFT));

        // Colisiones: dos viejas con el mismo número, o una nueva igual a una ficha que se queda como está.
        $quedan = $fichas->diffKeys($viejas)->values();
        $repetidas = $nuevas->duplicates()->merge($nuevas->intersect($quedan))->unique();
        if ($repetidas->isNotEmpty()) {
            $detalle = $repetidas->map(fn (string $nueva) => $nueva.' <- '.$fichas->filter(
                fn ($nro, $id) => ($nuevas[$id] ?? $nro) === $nueva)->join(', '))->join('; ');

            throw new RuntimeException("El paso de las fichas al formato FP- haría números repetidos: {$detalle}. No se modificó nada.");
        }

        foreach ($nuevas as $id => $nueva) {
            DB::table('pacientes')->where('id', $id)->update(['nro_ficha' => $nueva]);
        }

        // Verificación 1 a 1.
        foreach ($nuevas as $id => $nueva) {
            if (DB::table('pacientes')->where('id', $id)->value('nro_ficha') !== $nueva) {
                throw new RuntimeException("La ficha del paciente {$id} no quedó como {$nueva}.");
            }
        }
    }
};
