<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: una historia clínica para cada paciente que todavía no tiene, con fecha de apertura = su
 * fecha de alta. De ahí en adelante la crea el modelo Paciente al darse de alta. Se verifica uno por
 * uno: si a algún paciente le quedara sin historia, la migración falla y se deshace.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();

        DB::table('pacientes')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('historias_clinicas')->whereColumn('historias_clinicas.paciente_id', 'pacientes.id'))
            ->orderBy('id')
            ->get(['id', 'fecha_alta'])
            ->each(function (object $paciente) use ($ahora) {
                DB::table('historias_clinicas')->insert([
                    'paciente_id' => $paciente->id,
                    'fecha_apertura' => $paciente->fecha_alta,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

                $creada = DB::table('historias_clinicas')->where('paciente_id', $paciente->id)->value('fecha_apertura');
                if ($creada === null || substr((string) $creada, 0, 10) !== substr((string) $paciente->fecha_alta, 0, 10)) {
                    throw new RuntimeException("No se pudo crear la historia clínica del paciente {$paciente->id}.");
                }
            });

        $sinHistoria = DB::table('pacientes')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('historias_clinicas')->whereColumn('historias_clinicas.paciente_id', 'pacientes.id'))
            ->count();
        if ($sinHistoria > 0) {
            throw new RuntimeException("Quedaron {$sinHistoria} pacientes sin historia clínica.");
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás de datos: al deshacer la migración anterior se borra la tabla entera.
    }
};
