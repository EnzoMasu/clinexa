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
 * Triaje: urgente y observación (consultas), el nivel de cada signo vital al guardarlo (examenes_fisicos.
 * signos_alertas) y los parámetros clínicos con tres niveles (parametros_clinicos, módulo PARAMETROS_CLINICOS).
 *
 * LOS VALORES CLÍNICOS NO SE INVENTAN: se siembran (solo si la tabla está vacía) ÚNICAMENTE los límites
 * "imposible" que ya validaba el formulario del examen físico (FormularioConsulta, hasta hoy), con los rangos
 * normal y muy alterado VACÍOS y en estado PENDIENTE. Para la presión, sin límites numéricos: rige lo que ya se
 * validaba (formato 120/80 y números positivos) y, como REGLA NUEVA, sistólica mayor que diastólica. Los demás valores los carga y
 * los aprueba un médico (docs/triaje.md).
 */
return new class extends Migration
{
    /** codigo => [nombre, unidad, campo, imposible_min, imposible_max] (los límites que ya validaba el formulario). */
    private const PARAMETROS = [
        'TEMPERATURA' => ['Temperatura', '°C', 'temperatura', 30, 45],
        'PRESION_SISTOLICA' => ['Presión sistólica', 'mmHg', 'presion_arterial', null, null],
        'PRESION_DIASTOLICA' => ['Presión diastólica', 'mmHg', 'presion_arterial', null, null],
        'FRECUENCIA_CARDIACA' => ['Frecuencia cardíaca', 'lpm', 'frecuencia_cardiaca', 20, 300],
        'FRECUENCIA_RESPIRATORIA' => ['Frecuencia respiratoria', 'rpm', 'frecuencia_respiratoria', 5, 80],
        'SATURACION_OXIGENO' => ['Saturación de oxígeno', '%', 'saturacion_oxigeno', 0, 100],
        // El formulario además exige peso mayor que 0 (esa regla sigue en FormularioConsulta).
        'PESO' => ['Peso', 'kg', 'peso', 0, 500],
        'TALLA' => ['Talla', 'cm', 'talla', 20, 250],
    ];

    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $table) {
            $table->boolean('triaje_urgente')->default(false)->after('preparada_en');
            $table->text('triaje_observacion')->nullable()->after('triaje_urgente');
            $table->foreignId('triaje_urgente_por')->nullable()->after('triaje_observacion')->constrained('users');
            $table->timestamp('triaje_urgente_en')->nullable()->after('triaje_urgente_por');
        });

        Schema::table('examenes_fisicos', function (Blueprint $table) {
            $table->jsonb('signos_alertas')->nullable();
        });

        if (! DB::table('estados')->where('codigo', Estado::APROBADO)->exists()) {
            DB::table('estados')->insert(['codigo' => Estado::APROBADO, 'nombre' => 'APROBADO', 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('parametros_clinicos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 100);
            $table->string('unidad', 20);
            $table->string('campo', 40);
            foreach (['imposible', 'muy_alterado', 'normal'] as $banda) {
                $table->decimal("{$banda}_min", 8, 2)->nullable();
                $table->decimal("{$banda}_max", 8, 2)->nullable();
            }
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestamp('aprobado_en')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Solo en una instalación existente (con módulos): en una nueva lo crea ModuloSistemaSeeder, como a todos.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'PARAMETROS_CLINICOS'], ['nombre' => 'Parámetros clínicos'])
                ->configurarEstados([Estado::PENDIENTE, Estado::APROBADO]);
        }

        if (DB::table('parametros_clinicos')->doesntExist()) {
            $pendiente = Estado::idDe(Estado::PENDIENTE);
            foreach (self::PARAMETROS as $codigo => [$nombre, $unidad, $campo, $minimo, $maximo]) {
                DB::table('parametros_clinicos')->insert([
                    'codigo' => $codigo, 'nombre' => $nombre, 'unidad' => $unidad, 'campo' => $campo,
                    'imposible_min' => $minimo, 'imposible_max' => $maximo, 'estado_id' => $pendiente,
                    'notas' => $minimo === null
                        ? 'Sin límites numéricos: rige el formato 120/80 y números positivos (lo que ya se validaba) y, como regla nueva, sistólica mayor que diastólica. Los rangos los define y aprueba un médico.'
                        : 'Límite imposible tomado de la validación que ya tenía el formulario. Los rangos normal y muy alterado los define y aprueba un médico.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            PerfilesPredefinidos::sumarModulo('PARAMETROS_CLINICOS', [
                PerfilesPredefinidos::SUPERVISION_MEDICA => ['VER', 'EDITAR'],
                PerfilesPredefinidos::MEDICO => ['VER'],
                PerfilesPredefinidos::GERENCIA => ['VER'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros_clinicos');
        Schema::table('examenes_fisicos', fn (Blueprint $table) => $table->dropColumn('signos_alertas'));
        Schema::table('consultas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('triaje_urgente_por');
            $table->dropColumn(['triaje_urgente', 'triaje_observacion', 'triaje_urgente_en']);
        });
        // El estado APROBADO y el módulo quedan (los pueden usar otros registros).
    }
};
