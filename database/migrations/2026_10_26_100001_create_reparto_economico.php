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
 * Fase 2 económica (docs/reparto-economico.md). Aditiva: seis tablas, un estado y cuatro módulos nuevos.
 *
 * - `tipos_equipo` (catálogo simple) y `equipos` (nombre, tipo, dueño = un proveedor, estado) con `equipo_procedimiento`: los
 *   procedimientos que lo usan (en esta fase, a lo sumo un equipo ACTIVO por procedimiento; lo controla la aplicación).
 * - `acuerdos_reparto`: el porcentaje del profesional (general o por procedimiento) y el del dueño de un equipo, versionados
 *   como las tarifas: una sola VIGENTE (abierta) por clave y, en PostgreSQL, rangos sin solaparse (EXCLUDE). Nace VACÍA.
 * - `repartos_prestacion` (uno por prestación: PENDIENTE con su motivo, REPARTIDO o ANULADO) y `lineas_reparto` (una por
 *   beneficiario: CLINICA, PROFESIONAL, EQUIPO), inmutables.
 *
 * Módulos ACUERDOS_REPARTO (VER, CREAR, EDITAR = cambiar porcentaje y "Repartir"), REPARTOS (VER: el detalle del reparto),
 * EQUIPOS y TIPOS_EQUIPO: solo Gerencia (y el Administrador, solo). Nadie más.
 *
 * No reparte prestaciones ya existentes: guarda en `hitos_sistema` (REPARTOS) el momento en que corre; las prestaciones creadas
 * antes nunca se reparten ni aparecen como pendientes. Idempotente en el catálogo, sin leer filas durante `migrate --pretend`
 * y, en una instalación nueva, deja los módulos a ModuloSistemaSeeder.
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::ACTIVO, Estado::INACTIVO, Estado::VIGENTE, Estado::HISTORICO, Estado::PENDIENTE, Estado::REPARTIDO, Estado::ANULADO];

    public function up(): void
    {
        // Los estados, si faltaran (REPARTIDO es nuevo). Con DB::table, y no con Estado::idDe, para que `migrate --pretend`,
        // que no devuelve filas, no falle.
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('tipos_equipo', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->unique();
            $table->foreignId('tipo_equipo_id')->constrained('tipos_equipo');
            // El dueño: un proveedor (normalmente de la categoría "Equipos médicos").
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('equipo_procedimiento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->constrained('equipos');
            $table->foreignId('procedimiento_id')->constrained('procedimientos');
            $table->timestamps();

            $table->unique(['equipo_id', 'procedimiento_id']);
            $table->index('procedimiento_id');
        });

        Schema::create('acuerdos_reparto', function (Blueprint $table) {
            $table->id();
            // PROFESIONAL: profesional y procedimiento (vacío = general). EQUIPO: el porcentaje del dueño del equipo.
            $table->string('tipo', 20);
            $table->foreignId('profesional_id')->nullable()->constrained('profesionales');
            $table->foreignId('procedimiento_id')->nullable()->constrained('procedimientos');
            $table->foreignId('equipo_id')->nullable()->constrained('equipos');
            // Sobre el monto de la prestación tal cual: 0 a 100, hasta dos decimales.
            $table->decimal('porcentaje', 5, 2);
            $table->date('vigente_desde');
            // Inclusive. Vacío mientras está VIGENTE. Uno reemplazado el mismo día que empezaba queda con hasta = desde - 1.
            $table->date('vigente_hasta')->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('reemplaza_a_id')->nullable()->constrained('acuerdos_reparto');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();

            $table->index(['tipo', 'profesional_id', 'procedimiento_id']);
            $table->index(['tipo', 'equipo_id']);
        });
        // Uno solo abierto por clave (lo vacío cuenta como 0). Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX acuerdos_reparto_una_vigente ON acuerdos_reparto (tipo, COALESCE(profesional_id, 0), COALESCE(procedimiento_id, 0), COALESCE(equipo_id, 0)) WHERE vigente_hasta IS NULL');

        Schema::create('repartos_prestacion', function (Blueprint $table) {
            $table->id();
            // Uno por prestación: "Repartir" dos veces no duplica.
            $table->foreignId('prestacion_id')->unique()->constrained('prestaciones');
            $table->foreignId('estado_id')->constrained('estados');
            // Por qué quedó pendiente (falta un acuerdo, o los porcentajes superan 100).
            $table->text('motivo_pendiente')->nullable();
            // Fijados al repartir: el monto de la prestación y el equipo que usaba el procedimiento.
            $table->decimal('monto_base', 15, 2);
            $table->foreignId('moneda_id')->constrained('monedas');
            $table->foreignId('equipo_id')->nullable()->constrained('equipos');
            $table->timestamp('repartido_en')->nullable();
            $table->foreignId('repartido_por')->nullable()->constrained('users');
            $table->timestamp('anulado_en')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('estado_id');
        });

        Schema::create('lineas_reparto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reparto_prestacion_id')->constrained('repartos_prestacion');
            // CLINICA, PROFESIONAL o EQUIPO: una línea por beneficiario (el profesional dueño del equipo tiene dos).
            $table->string('beneficiario', 20);
            $table->foreignId('profesional_id')->nullable()->constrained('profesionales');
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
            $table->foreignId('equipo_id')->nullable()->constrained('equipos');
            $table->decimal('porcentaje', 5, 2);
            $table->decimal('monto', 15, 2);
            // Los acuerdos usados (la línea de la clínica, los dos que la definen).
            $table->foreignId('acuerdo_profesional_id')->nullable()->constrained('acuerdos_reparto');
            $table->foreignId('acuerdo_equipo_id')->nullable()->constrained('acuerdos_reparto');
            $table->timestamps();

            $table->unique(['reparto_prestacion_id', 'beneficiario']);
            $table->index('profesional_id');
            $table->index('proveedor_id');
        });

        // Desde cuándo se reparten prestaciones: las creadas antes nunca (ni retroactivamente).
        DB::table('hitos_sistema')->insert(['codigo' => 'REPARTOS', 'fecha_hora' => now(), 'created_at' => now(), 'updated_at' => now()]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('ALTER TABLE acuerdos_reparto ADD CONSTRAINT acuerdos_reparto_porcentaje_valido CHECK (porcentaje >= 0 AND porcentaje <= 100)');
            DB::statement('ALTER TABLE acuerdos_reparto ADD CONSTRAINT acuerdos_reparto_rango_valido CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde - 1)');
            DB::statement("ALTER TABLE acuerdos_reparto ADD CONSTRAINT acuerdos_reparto_clave_valida CHECK (
                (tipo = 'PROFESIONAL' AND profesional_id IS NOT NULL AND equipo_id IS NULL)
                OR (tipo = 'EQUIPO' AND equipo_id IS NOT NULL AND profesional_id IS NULL AND procedimiento_id IS NULL))");
            // Los rangos [desde, hasta] de una misma clave no se solapan (uno reemplazado el mismo día queda vacío).
            DB::statement("ALTER TABLE acuerdos_reparto ADD CONSTRAINT acuerdos_reparto_sin_solapamiento EXCLUDE USING gist (tipo WITH =, (COALESCE(profesional_id, 0)) WITH =, (COALESCE(procedimiento_id, 0)) WITH =, (COALESCE(equipo_id, 0)) WITH =, daterange(vigente_desde, vigente_hasta + 1, '[)') WITH &&)");
            DB::statement('ALTER TABLE repartos_prestacion ADD CONSTRAINT repartos_prestacion_monto_positivo CHECK (monto_base > 0)');
            DB::statement('ALTER TABLE lineas_reparto ADD CONSTRAINT lineas_reparto_valores_validos CHECK (porcentaje >= 0 AND porcentaje <= 100 AND monto >= 0)');
            DB::statement("ALTER TABLE lineas_reparto ADD CONSTRAINT lineas_reparto_beneficiario_valido CHECK (
                (beneficiario = 'CLINICA' AND profesional_id IS NULL AND proveedor_id IS NULL)
                OR (beneficiario = 'PROFESIONAL' AND profesional_id IS NOT NULL AND proveedor_id IS NULL AND acuerdo_profesional_id IS NOT NULL)
                OR (beneficiario = 'EQUIPO' AND proveedor_id IS NOT NULL AND equipo_id IS NOT NULL AND profesional_id IS NULL AND acuerdo_equipo_id IS NOT NULL))");
        }

        // Solo en una instalación existente (con módulos): en una nueva los crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'ACUERDOS_REPARTO'], ['nombre' => 'Acuerdos de reparto'])
                ->configurarEstados([Estado::VIGENTE, Estado::HISTORICO]);
            ModuloSistema::updateOrCreate(['codigo' => 'REPARTOS'], ['nombre' => 'Repartos de prestaciones'])
                ->configurarEstados([Estado::PENDIENTE, Estado::REPARTIDO, Estado::ANULADO]);
            ModuloSistema::updateOrCreate(['codigo' => 'EQUIPOS'], ['nombre' => 'Equipos'])
                ->configurarEstados([Estado::ACTIVO, Estado::INACTIVO]);
            ModuloSistema::updateOrCreate(['codigo' => 'TIPOS_EQUIPO'], ['nombre' => 'Tipos de equipo'])
                ->configurarEstados([Estado::ACTIVO, Estado::INACTIVO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            $todo = ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'];
            foreach (['ACUERDOS_REPARTO' => ['VER', 'CREAR', 'EDITAR'], 'REPARTOS' => ['VER'], 'EQUIPOS' => $todo, 'TIPOS_EQUIPO' => $todo] as $modulo => $acciones) {
                if (ModuloSistema::where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, [PerfilesPredefinidos::GERENCIA => $acciones]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('hitos_sistema')->where('codigo', 'REPARTOS')->delete();
        Schema::dropIfExists('lineas_reparto');
        Schema::dropIfExists('repartos_prestacion');
        Schema::dropIfExists('acuerdos_reparto');
        Schema::dropIfExists('equipo_procedimiento');
        Schema::dropIfExists('equipos');
        Schema::dropIfExists('tipos_equipo');
        // Los módulos, sus permisos y el estado REPARTIDO quedan (los perfiles los pueden tener asignados).
    }
};
