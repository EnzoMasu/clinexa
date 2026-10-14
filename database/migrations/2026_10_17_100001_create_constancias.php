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
 * Constancias de retiro y de estado del turno (docs/constancias.md). Dos tablas gemelas, una por tipo, porque cada
 * una tiene su módulo de permisos. Documentos inmutables: nacen EMITIDO (con número, fecha, snapshot y huella
 * sha256) y solo pueden pasar a ANULADO con su motivo. Nada se borra. Aditiva: solo tablas y módulos nuevos.
 * Una sola constancia EMITIDA por turno y tipo (índice único parcial); anulada, se puede emitir otra.
 * CONSTANCIAS_RETIRO se suma a RECEPCION; CONSTANCIAS_ESTADO a MEDICO y SUPERVISION_MEDICA (VER, CREAR, EDITAR).
 */
return new class extends Migration
{
    private const MODULOS = [
        'constancias_retiro' => ['CONSTANCIAS_RETIRO', 'Constancias de retiro'],
        'constancias_estado' => ['CONSTANCIAS_ESTADO', 'Constancias de estado del turno'],
    ];

    public function up(): void
    {
        // Los estados EMITIDO y ANULADO ya existen en la base real (los usan las recetas); si faltaran (catalogo nuevo), se crean.
        // Se leen con DB::table y no con Estado::idDe, para que `migrate --pretend` (que no devuelve filas) no falle.
        foreach ([Estado::EMITIDO, Estado::ANULADO] as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $emitido = DB::table('estados')->where('codigo', Estado::EMITIDO)->value('id');

        foreach (array_keys(self::MODULOS) as $tabla) {
            Schema::create($tabla, function (Blueprint $table) use ($tabla) {
                $table->id();
                $table->string('numero', 20)->unique();
                $table->foreignId('turno_id')->constrained('turnos');
                if ($tabla === 'constancias_retiro') {
                    $table->foreignId('salida_id')->constrained('salidas_sin_atencion');
                }
                $table->date('fecha');
                $table->foreignId('estado_id')->constrained('estados');
                $table->timestamp('emitida_en');
                $table->timestamp('anulada_en')->nullable();
                $table->text('motivo_anulacion')->nullable();
                // Lo que se imprimió, tal cual, y su huella sha256 (64 caracteres hexadecimales).
                $table->jsonb('snapshot');
                $table->string('huella', 64);
                $table->foreignId('usuario_id')->constrained('users');
                $table->timestamps();

                $table->index('turno_id');
            });

            DB::statement("CREATE UNIQUE INDEX {$tabla}_una_emitida ON {$tabla} (turno_id) WHERE estado_id = {$emitido}");
        }

        // Solo en una instalación existente (con módulos): en una nueva lo crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            foreach (self::MODULOS as [$codigo, $nombre]) {
                ModuloSistema::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre])
                    ->configurarEstados([Estado::EMITIDO, Estado::ANULADO]);
            }
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'CONSTANCIAS_RETIRO')->exists()) {
            PerfilesPredefinidos::sumarModulo('CONSTANCIAS_RETIRO', [
                PerfilesPredefinidos::RECEPCION => ['VER', 'CREAR', 'EDITAR'],
            ]);
            PerfilesPredefinidos::sumarModulo('CONSTANCIAS_ESTADO', [
                PerfilesPredefinidos::MEDICO => ['VER', 'CREAR', 'EDITAR'],
                PerfilesPredefinidos::SUPERVISION_MEDICA => ['VER', 'CREAR', 'EDITAR'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('constancias_estado');
        Schema::dropIfExists('constancias_retiro');
        // Los módulos y sus permisos quedan (los perfiles los pueden tener asignados).
    }
};
