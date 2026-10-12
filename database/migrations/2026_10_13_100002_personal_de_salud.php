<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Estado;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rol "Personal de salud" (personal_salud) y su catálogo de cargos (cargos_salud), con sus módulos
 * PERSONAL_SALUD y CARGOS_SALUD. Solo el personal de salud (o un profesional activo) carga el triaje
 * (ConsultaPolicy). SIN backfill: nadie se convierte en personal de salud solo.
 *
 * Los módulos se crean acá y se suman a los perfiles predefinidos UNA sola vez (PerfilesPredefinidos::sumarModulo,
 * docs/perfiles.md), solo si la instalación ya tiene perfiles; el Administrador los recibe completos. Los cargos
 * base se siembran solo si la tabla está vacía.
 */
return new class extends Migration
{
    private const CARGOS = ['Médico/a', 'Licenciado/a en enfermería', 'Licenciado/a en obstetricia', 'Enfermero/a', 'Auxiliar de enfermería'];

    public function up(): void
    {
        Schema::create('cargos_salud', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('personal_salud', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas');
            $table->foreignId('cargo_salud_id')->constrained('cargos_salud');
            $table->string('registro_profesional', 50)->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        foreach (['CARGOS_SALUD' => 'Cargos de salud', 'PERSONAL_SALUD' => 'Personal de salud'] as $codigo => $nombre) {
            ModuloSistema::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre])->configurarEstados([Estado::ACTIVO, Estado::INACTIVO]);
        }

        if (DB::table('cargos_salud')->doesntExist()) {
            $activo = Estado::idDe(Estado::ACTIVO);
            DB::table('cargos_salud')->insert(array_map(fn (string $nombre) => [
                'nombre' => $nombre, 'estado_id' => $activo, 'created_at' => now(), 'updated_at' => now(),
            ], self::CARGOS));
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            PerfilesPredefinidos::sumarModulo('PERSONAL_SALUD', [
                PerfilesPredefinidos::SUPERVISION_MEDICA => ['VER', 'CREAR', 'EDITAR'],
                PerfilesPredefinidos::GERENCIA => ['VER'],
            ]);
            PerfilesPredefinidos::sumarModulo('CARGOS_SALUD', [
                PerfilesPredefinidos::SUPERVISION_MEDICA => ['VER'],
                PerfilesPredefinidos::GERENCIA => ['VER'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_salud');
        Schema::dropIfExists('cargos_salud');
        // Los módulos y sus permisos quedan (desactivarlos o quitarlos es una decisión aparte).
    }
};
