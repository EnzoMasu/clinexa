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
 * Fase 1 económica (docs/tarifas-prestaciones.md). Aditiva: tres tablas, un estado y dos módulos nuevos.
 *
 * - `monedas`: catálogo simple con Guaraní (PYG) como dato de referencia (no es un precio). Solo se usa PYG.
 * - `tarifas`: versionadas por procedimiento y profesional (vacío = tarifa general). Nunca se editan: cambiar el monto
 *   cierra la VIGENTE (pasa a HISTORICO con su "hasta") y abre otra. Una sola abierta (VIGENTE, sin "hasta") por
 *   procedimiento y profesional, y en PostgreSQL los rangos de fechas no se solapan (EXCLUDE). Nace VACÍA.
 * - `prestaciones`: el servicio prestado, una por consulta (única), creada al finalizarla. Con su monto fijado al tarifarla.
 *   Estados PENDIENTE (pendiente de tarifar), TARIFADO (nuevo) y ANULADO.
 *
 * Módulos TARIFAS (VER, CREAR, EDITAR) y PRESTACIONES (VER, EDITAR = tarifar, DESACTIVAR = anular): Gerencia todo;
 * Recepción, Caja y Facturación y convenios solo VER; el Administrador los recibe solo. Médico y Enfermería, nada.
 *
 * No genera prestaciones de consultas ya cerradas: guarda en `hitos_sistema` (PRESTACIONES) el momento en que corre; las
 * consultas finalizadas antes no aparecen en "Consultas sin prestación". Idempotente en el catálogo, sin leer filas durante `migrate --pretend`
 * y, en una instalación nueva, deja los módulos a ModuloSistemaSeeder.
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::ACTIVO, Estado::VIGENTE, Estado::HISTORICO, Estado::PENDIENTE, Estado::TARIFADO, Estado::ANULADO];

    public function up(): void
    {
        // Los estados, si faltaran (TARIFADO es nuevo; los demás, por un catálogo sin sus datos). Con DB::table, y no con
        // Estado::idDe, para que `migrate --pretend`, que no devuelve filas, no falle.
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $activo = DB::table('estados')->where('codigo', Estado::ACTIVO)->value('id');

        Schema::create('monedas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 3)->unique(); // ISO 4217
            $table->string('nombre', 50);
            $table->string('simbolo', 5);
            $table->unsignedTinyInteger('decimales');
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });
        if ($activo !== null) {
            DB::table('monedas')->insert(['codigo' => 'PYG', 'nombre' => 'Guaraní', 'simbolo' => '₲', 'decimales' => 0,
                'estado_id' => $activo, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('tarifas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedimiento_id')->constrained('procedimientos');
            // Vacío: la tarifa general del procedimiento.
            $table->foreignId('profesional_id')->nullable()->constrained('profesionales');
            $table->decimal('monto', 15, 2);
            $table->foreignId('moneda_id')->constrained('monedas');
            $table->date('vigente_desde');
            // Inclusive. Vacío mientras está VIGENTE. Una reemplazada el mismo día que empezaba queda con hasta = desde - 1
            // (sin ningún día de vigencia).
            $table->date('vigente_hasta')->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            // La tarifa a la que reemplazó (la cadena de versiones) y quién la cargó.
            $table->foreignId('reemplaza_a_id')->nullable()->constrained('tarifas');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();

            $table->index(['procedimiento_id', 'profesional_id']);
        });
        // Una sola abierta por procedimiento y profesional (la general cuenta como profesional 0). Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX tarifas_una_vigente ON tarifas (procedimiento_id, COALESCE(profesional_id, 0)) WHERE vigente_hasta IS NULL');

        Schema::create('prestaciones', function (Blueprint $table) {
            $table->id();
            // Una por consulta: la adenda y el desbloqueo no crean otra.
            $table->foreignId('consulta_id')->unique()->constrained('consultas');
            $table->foreignId('turno_id')->nullable()->constrained('turnos');
            $table->foreignId('paciente_id')->constrained('pacientes');
            $table->foreignId('profesional_id')->constrained('profesionales');
            // Vacío solo si no hay turno con procedimiento y tampoco existe el procedimiento Consulta: queda pendiente.
            $table->foreignId('procedimiento_id')->nullable()->constrained('procedimientos');
            $table->date('fecha_servicio');
            // Fijados al tarifar; después no cambian aunque cambie la tarifa.
            $table->decimal('monto', 15, 2)->nullable();
            $table->foreignId('moneda_id')->nullable()->constrained('monedas');
            $table->foreignId('tarifa_id')->nullable()->constrained('tarifas');
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamp('tarifada_en')->nullable();
            $table->foreignId('tarifada_por')->nullable()->constrained('users');
            $table->timestamp('anulada_en')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['fecha_servicio', 'estado_id']);
            $table->index('profesional_id');
        });

        // Desde cuándo se generan prestaciones: lo finalizado antes nunca tiene (ni se genera retroactivamente).
        Schema::create('hitos_sistema', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->timestamp('fecha_hora');
            $table->timestamps();
        });
        DB::table('hitos_sistema')->insert(['codigo' => 'PRESTACIONES', 'fecha_hora' => now(), 'created_at' => now(), 'updated_at' => now()]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('ALTER TABLE tarifas ADD CONSTRAINT tarifas_monto_positivo CHECK (monto > 0)');
            DB::statement('ALTER TABLE tarifas ADD CONSTRAINT tarifas_rango_valido CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde - 1)');
            // Los rangos [desde, hasta] de un mismo procedimiento y profesional no se solapan (uno reemplazado el mismo día
            // queda vacío y no choca con nada).
            DB::statement("ALTER TABLE tarifas ADD CONSTRAINT tarifas_sin_solapamiento EXCLUDE USING gist (procedimiento_id WITH =, (COALESCE(profesional_id, 0)) WITH =, daterange(vigente_desde, vigente_hasta + 1, '[)') WITH &&)");
            DB::statement('ALTER TABLE prestaciones ADD CONSTRAINT prestaciones_monto_positivo CHECK (monto IS NULL OR monto > 0)');
            DB::statement('ALTER TABLE prestaciones ADD CONSTRAINT prestaciones_monto_con_tarifa CHECK ((monto IS NULL) = (tarifa_id IS NULL) AND (monto IS NULL) = (moneda_id IS NULL))');
        }

        // Solo en una instalación existente (con módulos): en una nueva los crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'TARIFAS'], ['nombre' => 'Tarifas'])
                ->configurarEstados([Estado::VIGENTE, Estado::HISTORICO]);
            ModuloSistema::updateOrCreate(['codigo' => 'PRESTACIONES'], ['nombre' => 'Prestaciones'])
                ->configurarEstados([Estado::PENDIENTE, Estado::TARIFADO, Estado::ANULADO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            $ver = ['VER'];
            foreach (['TARIFAS' => ['VER', 'CREAR', 'EDITAR'], 'PRESTACIONES' => ['VER', 'EDITAR', 'DESACTIVAR']] as $modulo => $todo) {
                if (ModuloSistema::where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, [
                        PerfilesPredefinidos::GERENCIA => $todo,
                        PerfilesPredefinidos::RECEPCION => $ver,
                        PerfilesPredefinidos::CAJA => $ver,
                        PerfilesPredefinidos::FACTURACION => $ver,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hitos_sistema');
        Schema::dropIfExists('prestaciones');
        Schema::dropIfExists('tarifas');
        Schema::dropIfExists('monedas');
        // Los módulos, sus permisos y el estado TARIFADO quedan (los perfiles los pueden tener asignados).
    }
};
