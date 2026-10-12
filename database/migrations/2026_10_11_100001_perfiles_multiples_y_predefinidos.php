<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varios perfiles por usuario (usuario_perfil; los permisos efectivos son la unión de los de sus perfiles
 * activos) y perfiles predefinidos con código estable (perfiles_acceso.codigo y .predefinido).
 *
 * Backfill: cada usuario recibe en usuario_perfil el perfil que tenía en users.perfil_acceso_id. Esa columna
 * queda SIN USO (nada la lee ni la escribe) y se elimina en una migración posterior, cuando la pivote
 * esté verificada en producción. El Administrador recibe su código acá (se lo identifica por código desde
 * ahora); los demás predefinidos los crea o adopta PerfilesPredefinidosSeeder. Ver docs/perfiles.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfiles_acceso', function (Blueprint $table) {
            $table->string('codigo', 40)->nullable()->unique()->after('id');
            $table->boolean('predefinido')->default(false)->after('descripcion');
        });

        Schema::create('usuario_perfil', function (Blueprint $table) {
            $table->foreignId('usuario_id')->constrained('users');
            $table->foreignId('perfil_acceso_id')->constrained('perfiles_acceso');
            $table->timestamps();

            $table->primary(['usuario_id', 'perfil_acceso_id']);
            $table->index('perfil_acceso_id');
        });

        $ahora = now();
        DB::table('users')->whereNotNull('perfil_acceso_id')->orderBy('id')->each(function (object $usuario) use ($ahora) {
            DB::table('usuario_perfil')->insert([
                'usuario_id' => $usuario->id, 'perfil_acceso_id' => $usuario->perfil_acceso_id,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        });

        DB::table('perfiles_acceso')->where('nombre', 'Administrador')->whereNull('codigo')
            ->update(['codigo' => 'ADMINISTRADOR', 'predefinido' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_perfil');

        Schema::table('perfiles_acceso', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn(['codigo', 'predefinido']);
        });
    }
};
