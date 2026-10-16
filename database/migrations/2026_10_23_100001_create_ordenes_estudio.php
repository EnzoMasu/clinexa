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
 * Orden de estudio (docs/ordenes-estudio.md) y su catálogo de estudios. Aditiva: dos tablas y dos módulos nuevos.
 *
 * - `estudios`: catálogo (solo el nombre). Nace VACÍO; no se borra, se desactiva. Módulo ESTUDIOS: SUPERVISION_MEDICA
 *   (VER, CREAR, EDITAR, DESACTIVAR) y MEDICO (VER: lo usa, no lo administra).
 * - `ordenes_estudio`: documento inmutable, como el reposo: nace EMITIDO (número OE-0000001, fecha, snapshot con los estudios
 *   y huella sha256) y solo puede pasar a ANULADO con su motivo. Módulo ORDENES_ESTUDIO: MEDICO (VER, CREAR, EDITAR) y
 *   SUPERVISION_MEDICA (VER).
 *
 * El Administrador recibe los dos módulos solo. Idempotente en el catálogo y sin leer filas durante `migrate --pretend`. En
 * una instalación nueva los módulos los crea ModuloSistemaSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estudios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->unique();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('ordenes_estudio', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->foreignId('consulta_id')->constrained('consultas');
            $table->date('fecha');
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamp('emitida_en');
            $table->timestamp('anulada_en')->nullable();
            $table->text('motivo_anulacion')->nullable();
            // Lo que se imprime, tal cual (con los estudios: el nombre queda copiado), y su huella sha256.
            $table->jsonb('snapshot');
            $table->string('huella', 64);
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();

            $table->index('consulta_id');
        });

        // Solo en una instalación existente (con módulos): en una nueva los crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            // Los estados, si faltaran (catálogo sin sus datos). Se leen con DB::table (y no con Estado::idDe) para que
            // `migrate --pretend`, que no devuelve filas, no falle.
            foreach ([Estado::ACTIVO, Estado::INACTIVO, Estado::EMITIDO, Estado::ANULADO] as $codigo) {
                if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                    DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            ModuloSistema::updateOrCreate(['codigo' => 'ESTUDIOS'], ['nombre' => 'Estudios'])
                ->configurarEstados([Estado::ACTIVO, Estado::INACTIVO]);
            ModuloSistema::updateOrCreate(['codigo' => 'ORDENES_ESTUDIO'], ['nombre' => 'Órdenes de estudio'])
                ->configurarEstados([Estado::EMITIDO, Estado::ANULADO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            if (ModuloSistema::where('codigo', 'ESTUDIOS')->exists()) {
                PerfilesPredefinidos::sumarModulo('ESTUDIOS', [
                    PerfilesPredefinidos::SUPERVISION_MEDICA => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'],
                    PerfilesPredefinidos::MEDICO => ['VER'],
                ]);
            }
            if (ModuloSistema::where('codigo', 'ORDENES_ESTUDIO')->exists()) {
                PerfilesPredefinidos::sumarModulo('ORDENES_ESTUDIO', [
                    PerfilesPredefinidos::MEDICO => ['VER', 'CREAR', 'EDITAR'],
                    PerfilesPredefinidos::SUPERVISION_MEDICA => ['VER'],
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_estudio');
        Schema::dropIfExists('estudios');
        // Los módulos y sus permisos quedan (los perfiles los pueden tener asignados).
    }
};
