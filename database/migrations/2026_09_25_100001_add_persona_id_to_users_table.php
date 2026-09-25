<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 1 de ligar users a personas: la columna nace nullable para poder completar los usuarios
 * existentes con `php artisan clinexa:migrar-usuarios-a-persona` antes de hacerla obligatoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('persona_id')->nullable()->after('id')
                ->constrained('personas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('persona_id');
        });
    }
};
