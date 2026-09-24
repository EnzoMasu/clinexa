<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('perfil_acceso_id')->nullable()->after('id')
                ->constrained('perfiles_acceso')->nullOnDelete();
            $table->enum('estado', ['ACTIVO', 'BLOQUEADO', 'INACTIVO'])->default('ACTIVO')->after('password');
            $table->timestamp('ultimo_acceso')->nullable()->after('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perfil_acceso_id');
            $table->dropColumn(['estado', 'ultimo_acceso']);
        });
    }
};
