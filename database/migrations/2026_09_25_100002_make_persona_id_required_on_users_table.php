<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 2 de ligar users a personas: cada usuario es exactamente una persona (NOT NULL + UNIQUE).
 * La FK pasa a RESTRICT: una columna obligatoria no puede quedar en null si se borrara la persona
 * (y por regla de negocio las personas nunca se borran, se desactivan).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sinPersona = DB::table('users')->whereNull('persona_id')->count();
        if ($sinPersona > 0) {
            throw new RuntimeException("Hay {$sinPersona} usuario(s) sin persona_id. Correr primero: php artisan clinexa:migrar-usuarios-a-persona");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['persona_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')->nullable(false)->change();
            $table->unique('persona_id');
            $table->foreign('persona_id')->references('id')->on('personas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['persona_id']);
            $table->dropUnique(['persona_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')->nullable()->change();
            $table->foreign('persona_id')->references('id')->on('personas')->nullOnDelete();
        });
    }
};
