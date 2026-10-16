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
 * Certificado de reposo médico (docs/reposo-medico.md). Documento inmutable, aparte de la consulta: nace EMITIDO (número
 * RM-0000001, fecha, snapshot y huella sha256) y solo puede pasar a ANULADO con su motivo. Nada se borra. Aditiva: una
 * tabla y un módulo nuevos. Módulo REPOSO_MEDICO: MEDICO (VER, CREAR, EDITAR) y SUPERVISION_MEDICA (VER); el Administrador
 * lo recibe solo. Idempotente en el catálogo y sin leer filas durante `migrate --pretend`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // EMITIDO y ANULADO existen en la base real (recetas, constancias); si faltaran (catálogo sin sus datos), se crean.
        // Se leen con DB::table y no con Estado::idDe, para que `migrate --pretend` (que no devuelve filas) no falle.
        foreach ([Estado::EMITIDO, Estado::ANULADO] as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('reposos_medicos', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->foreignId('consulta_id')->constrained('consultas');
            // Prórroga: el certificado anterior (emitido, del mismo paciente) que este continúa.
            $table->foreignId('prorroga_de_id')->nullable()->constrained('reposos_medicos');
            $table->date('fecha');
            $table->unsignedInteger('dias');
            $table->date('desde');
            $table->date('hasta');
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamp('emitida_en');
            $table->timestamp('anulada_en')->nullable();
            $table->text('motivo_anulacion')->nullable();
            // Lo que se imprime, tal cual, y su huella sha256 (64 caracteres hexadecimales).
            $table->jsonb('snapshot');
            $table->string('huella', 64);
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();

            $table->index('consulta_id');
        });

        // Solo en una instalación existente (con módulos): en una nueva lo crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'REPOSO_MEDICO'], ['nombre' => 'Certificados de reposo médico'])
                ->configurarEstados([Estado::EMITIDO, Estado::ANULADO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'REPOSO_MEDICO')->exists()) {
            PerfilesPredefinidos::sumarModulo('REPOSO_MEDICO', [
                PerfilesPredefinidos::MEDICO => ['VER', 'CREAR', 'EDITAR'],
                PerfilesPredefinidos::SUPERVISION_MEDICA => ['VER'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reposos_medicos');
        // El módulo y sus permisos quedan (los perfiles los pueden tener asignados).
    }
};
