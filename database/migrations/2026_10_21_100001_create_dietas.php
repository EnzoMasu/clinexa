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
 * Catálogo de dietas (docs/indicaciones-generales.md): nombre y texto. Nace VACÍO (lo carga un médico o nutricionista); no se
 * borra, se desactiva. El texto se COPIA a la indicación general (sin enlace: la indicación no guarda de qué dieta salió).
 * Módulo DIETAS: SUPERVISION_MEDICA (VER, CREAR, EDITAR, DESACTIVAR); el Administrador lo recibe solo. Idempotente en el
 * catálogo y sin leer filas durante `migrate --pretend`. En una instalación nueva el módulo lo crea ModuloSistemaSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dietas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            // Hasta 500 caracteres, como el texto de una indicación general: así siempre entra al copiarla.
            $table->text('texto');
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        // Solo en una instalación existente (con módulos). ACTIVO e INACTIVO se crean si faltaran (catálogo sin sus datos).
        if (DB::table('modulos_sistema')->exists()) {
            foreach ([Estado::ACTIVO, Estado::INACTIVO] as $codigo) {
                if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                    DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            ModuloSistema::updateOrCreate(['codigo' => 'DIETAS'], ['nombre' => 'Dietas'])
                ->configurarEstados([Estado::ACTIVO, Estado::INACTIVO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'DIETAS')->exists()) {
            PerfilesPredefinidos::sumarModulo('DIETAS', [
                PerfilesPredefinidos::SUPERVISION_MEDICA => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dietas');
        // El módulo y sus permisos quedan (los perfiles los pueden tener asignados).
    }
};
