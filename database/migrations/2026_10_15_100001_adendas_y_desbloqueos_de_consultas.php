<?php

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correcciones de una consulta cerrada (docs/historia-clinica.md): adendas (notas que se agregan, inmutables),
 * desbloqueos (ventana de un solo uso, 24 h) y las versiones (consulta_versiones) con el estado anterior de cada
 * corrección. Aditiva: solo tablas y módulo nuevos. El módulo CORRECCIONES_HC (sensible) se suma a SUPERVISION_MEDICA
 * (VER, CREAR, EDITAR) y, por su seeder, al Administrador; nadie más lo recibe. Los estados VIGENTE, REALIZADO,
 * VENCIDO y CANCELADO son los del catálogo compartido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adendas_consulta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas');
            $table->string('motivo', 300);
            $table->text('texto');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();
            $table->index('consulta_id');
        });

        Schema::create('desbloqueos_consulta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas');
            $table->string('motivo', 500);
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamp('vence_en');
            $table->timestamp('usado_en')->nullable();
            $table->foreignId('cerrado_por_id')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['consulta_id', 'estado_id']);
        });

        Schema::create('consulta_versiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas');
            $table->unsignedInteger('numero');
            $table->jsonb('snapshot');
            $table->foreignId('desbloqueo_id')->constrained('desbloqueos_consulta');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();
            $table->unique(['consulta_id', 'numero']);
        });

        // Una sola ventana VIGENTE por consulta a la vez (también contra dos pedidos simultáneos).
        $vigente = DB::table('estados')->where('codigo', Estado::VIGENTE)->value('id');
        DB::statement("CREATE UNIQUE INDEX desbloqueos_consulta_una_vigente ON desbloqueos_consulta (consulta_id) WHERE estado_id = {$vigente}");

        // Solo en una instalación existente (con módulos): en una nueva lo crea ModuloSistemaSeeder, como a todos.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'CORRECCIONES_HC'], ['nombre' => 'Correcciones de la historia clínica', 'es_sensible' => true])
                ->configurarEstados([Estado::VIGENTE, Estado::REALIZADO, Estado::VENCIDO, Estado::CANCELADO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'CORRECCIONES_HC')->exists()) {
            PerfilesPredefinidos::sumarModulo('CORRECCIONES_HC', [
                PerfilesPredefinidos::SUPERVISION_MEDICA => ['VER', 'CREAR', 'EDITAR'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consulta_versiones');
        Schema::dropIfExists('desbloqueos_consulta');
        Schema::dropIfExists('adendas_consulta');
        // El módulo y sus permisos quedan (los perfiles los pueden tener asignados).
    }
};
