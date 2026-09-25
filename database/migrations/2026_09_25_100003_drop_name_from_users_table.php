<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 3 de ligar users a personas: el nombre sale de la Persona (User::name es un accessor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('')->after('persona_id');
        });

        // Se reconstruye desde la persona ("Nombres Apellidos", como era antes).
        foreach (DB::table('users')->join('personas', 'personas.id', '=', 'users.persona_id')
            ->select('users.id', 'personas.nombres', 'personas.apellidos', 'personas.razon_social')->get() as $fila) {
            DB::table('users')->where('id', $fila->id)->update([
                'name' => trim("{$fila->nombres} {$fila->apellidos}") ?: (string) $fila->razon_social,
            ]);
        }
    }
};
