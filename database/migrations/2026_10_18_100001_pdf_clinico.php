<?php

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PDF clínico (docs/pdf-clinico.md): módulo de permisos PDF_CLINICO (solo EXPORTAR). Sin tablas: el PDF se genera al
 * vuelo y no se guarda. Se suma a MEDICO, ENFERMERIA y SUPERVISION_MEDICA (el Administrador lo recibe solo); Recepción no.
 * Idempotente y sin leer filas durante `migrate --pretend`. En una instalación nueva lo crea ModuloSistemaSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Solo en una instalación existente (con módulos). Los estados ACTIVO e INACTIVO se crean si faltaran (catálogo vacío).
        if (DB::table('modulos_sistema')->exists()) {
            foreach ([Estado::ACTIVO, Estado::INACTIVO] as $codigo) {
                if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                    DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            ModuloSistema::updateOrCreate(['codigo' => 'PDF_CLINICO'], ['nombre' => 'PDF clínico (triaje y consulta)'])
                ->configurarEstados([Estado::ACTIVO, Estado::INACTIVO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'PDF_CLINICO')->exists()) {
            PerfilesPredefinidos::sumarModulo('PDF_CLINICO', [
                PerfilesPredefinidos::MEDICO => ['EXPORTAR'],
                PerfilesPredefinidos::ENFERMERIA => ['EXPORTAR'],
                PerfilesPredefinidos::SUPERVISION_MEDICA => ['EXPORTAR'],
            ]);
        }
    }

    public function down(): void
    {
        // Nada que deshacer: el módulo y sus permisos quedan (los perfiles pueden tenerlos asignados).
    }
};
