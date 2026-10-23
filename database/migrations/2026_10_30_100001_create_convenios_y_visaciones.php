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
 * Fase 4a económica (docs/convenios-seguros.md): convenios con las aseguradoras (responsables de pago) y visaciones. Aditiva:
 *
 * - `convenios` (responsable de pago, nombre, vigencia, VIGENTE / HISTORICO) y `planes_convenio` (solo un nombre, ACTIVO / INACTIVO).
 * - `tarifas_convenio`: versionadas como las tarifas (una abierta por convenio y procedimiento; en PostgreSQL sin solapamiento).
 * - `coberturas_paciente`: la afiliación de la paciente (plan, número de asegurado, titular o beneficiario, vigencia). Una sola
 *   abierta por paciente (`coberturas_una_vigente`) y, en PostgreSQL, los rangos de una paciente no se solapan.
 * - `visaciones` y `lineas_visacion`: la autorización de la aseguradora para UN turno o UNA consulta, con el número (único por
 *   responsable de pago entre las no anuladas, `visaciones_numero_unico`) y una línea por procedimiento.
 * - `prestaciones`: el snapshot del pagador y de la parte de cada uno (vacío en las existentes: siguen siendo particulares y no
 *   se recalculan), la marca "pendiente de liquidar", el aviso, la firma recibida y la aplicación tardía de una visación.
 * - `repartos_prestacion`: el único por prestación pasa a ser entre los no anulados (`repartos_prestacion_uno_vigente`): al aplicar
 *   una visación que cambia el total, el reparto anterior se anula y se calcula otro.
 *
 * Módulos CONVENIOS, COBERTURAS y VISACIONES con sus permisos (`PerfilesPredefinidos::PERMISOS_CONVENIOS`). El hito CONVENIOS: desde
 * cuándo las prestaciones buscan visación. Crea los estados que falten, no lee filas durante `migrate --pretend`, no carga
 * convenios ni tarifas y, en una instalación nueva, deja los módulos al seeder.
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::ACTIVO, Estado::INACTIVO, Estado::VIGENTE, Estado::HISTORICO, Estado::ANULADO];

    public function up(): void
    {
        // Con DB::table, y no con Estado::idDe, para que `migrate --pretend`, que no devuelve filas, no falle.
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('convenios', function (Blueprint $table) {
            $table->id();
            // La aseguradora (o empresa, profesional, clínica): un responsable de pago de Personas.
            $table->foreignId('responsable_pago_id')->constrained('responsables_pago');
            $table->string('nombre', 150);
            $table->date('vigente_desde');
            // Inclusive. Vacío = sin fecha de fin.
            $table->date('vigente_hasta')->nullable();
            // VIGENTE o HISTORICO (pasado a histórico con su último día).
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();

            $table->index('responsable_pago_id');
        });

        Schema::create('planes_convenio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convenio_id')->constrained('convenios');
            // Solo un nombre: sin reglas de cobertura ni copago (las decide la aseguradora en cada visación).
            $table->string('nombre', 100);
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();

            $table->unique(['convenio_id', 'nombre']);
        });

        Schema::create('tarifas_convenio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convenio_id')->constrained('convenios');
            $table->foreignId('procedimiento_id')->constrained('procedimientos');
            $table->decimal('monto', 15, 2);
            $table->foreignId('moneda_id')->constrained('monedas');
            $table->date('vigente_desde');
            // Inclusive. Vacío mientras está abierta; reemplazada el mismo día, hasta = desde - 1 (sin vigencia).
            $table->date('vigente_hasta')->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('reemplaza_a_id')->nullable()->constrained('tarifas_convenio');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();

            $table->index(['convenio_id', 'procedimiento_id']);
        });
        // Una sola abierta por convenio y procedimiento. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX tarifas_convenio_una_vigente ON tarifas_convenio (convenio_id, procedimiento_id) WHERE vigente_hasta IS NULL');

        Schema::create('coberturas_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes');
            $table->foreignId('plan_convenio_id')->constrained('planes_convenio');
            $table->string('numero_asegurado', 50);
            // TITULAR o BENEFICIARIO.
            $table->string('tipo_afiliado', 12);
            $table->date('vigente_desde');
            // Inclusive. Vacío = sin vencimiento conocido.
            $table->date('vigente_hasta')->nullable();
            // VIGENTE (la abierta) o HISTORICO (cerrada al cambiarla o al darla de baja, con `cerrada_en`).
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamp('cerrada_en')->nullable();
            $table->foreignId('cerrada_por')->nullable()->constrained('users');
            $table->foreignId('reemplaza_a_id')->nullable()->constrained('coberturas_paciente');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();

            $table->index('paciente_id');
        });
        // Una sola abierta por paciente. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX coberturas_una_vigente ON coberturas_paciente (paciente_id) WHERE cerrada_en IS NULL');

        Schema::create('visaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobertura_paciente_id')->constrained('coberturas_paciente');
            // Copia del responsable de la cobertura, para el número único por aseguradora.
            $table->foreignId('responsable_pago_id')->constrained('responsables_pago');
            // UN turno o UNA consulta (uno de los dos).
            $table->foreignId('turno_id')->nullable()->constrained('turnos');
            $table->foreignId('consulta_id')->nullable()->constrained('consultas');
            $table->string('numero', 50);
            $table->date('fecha');
            // VIGENTE o ANULADO (con motivo, quién y cuándo).
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamp('anulada_en')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();

            $table->index('turno_id');
            $table->index('consulta_id');
        });
        // El número, único por responsable de pago entre las no anuladas. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX visaciones_numero_unico ON visaciones (responsable_pago_id, numero) WHERE anulada_en IS NULL');

        Schema::create('lineas_visacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visacion_id')->constrained('visaciones');
            $table->foreignId('procedimiento_id')->constrained('procedimientos');
            // APROBADO o NO_CUBIERTO.
            $table->string('resultado', 12);
            // Si es APROBADO, lo que paga la paciente: NINGUNO, PORCENTAJE (0 a 100) o MONTO_FIJO (guaraníes).
            $table->string('copago', 12)->nullable();
            $table->decimal('porcentaje', 5, 2)->nullable();
            $table->decimal('monto', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['visacion_id', 'procedimiento_id']);
        });

        Schema::table('prestaciones', function (Blueprint $table) {
            // El snapshot del pagador (vacío en las anteriores a los convenios: particulares). PACIENTE o ASEGURADORA.
            $table->string('pagador', 12)->nullable();
            $table->foreignId('responsable_pago_id')->nullable()->constrained('responsables_pago');
            $table->foreignId('convenio_id')->nullable()->constrained('convenios');
            $table->foreignId('plan_convenio_id')->nullable()->constrained('planes_convenio');
            $table->foreignId('visacion_id')->nullable()->constrained('visaciones');
            $table->foreignId('linea_visacion_id')->nullable()->constrained('lineas_visacion');
            $table->string('numero_visacion', 50)->nullable();
            // La tarifa usada, si es la del convenio (la general sigue en tarifa_id).
            $table->foreignId('tarifa_convenio_id')->nullable()->constrained('tarifas_convenio');
            $table->decimal('monto_paciente', 15, 2)->nullable();
            $table->decimal('monto_aseguradora', 15, 2)->nullable();
            // PENDIENTE = pendiente de liquidar (lo usa la liquidación mensual, fase 4b).
            $table->string('liquidacion', 12)->nullable();
            // Por qué quedó particular teniendo cobertura (sin visación, vencida), o null.
            $table->string('aviso_cobertura', 300)->nullable();
            $table->timestamp('firma_recibida_en')->nullable();
            $table->foreignId('firma_recibida_por')->nullable()->constrained('users');
            // "Aplicar visación" después de finalizar: cuándo, quién y por qué.
            $table->timestamp('visacion_aplicada_en')->nullable();
            $table->foreignId('visacion_aplicada_por')->nullable()->constrained('users');
            $table->text('motivo_aplicacion')->nullable();

            $table->index('visacion_id');
        });
        self::rehacerIndicesParcialesEnSqlite();

        // El reparto: uno por prestación entre los no anulados (el anulado al aplicar una visación queda, con sus líneas).
        Schema::table('repartos_prestacion', function (Blueprint $table) {
            $table->dropUnique(['prestacion_id']);
            $table->index('prestacion_id');
        });
        DB::statement('CREATE UNIQUE INDEX repartos_prestacion_uno_vigente ON repartos_prestacion (prestacion_id) WHERE anulado_en IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('ALTER TABLE convenios ADD CONSTRAINT convenios_rango_valido CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde - 1)');
            DB::statement('ALTER TABLE tarifas_convenio ADD CONSTRAINT tarifas_convenio_monto_positivo CHECK (monto > 0)');
            DB::statement('ALTER TABLE tarifas_convenio ADD CONSTRAINT tarifas_convenio_rango_valido CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde - 1)');
            DB::statement("ALTER TABLE tarifas_convenio ADD CONSTRAINT tarifas_convenio_sin_solapamiento EXCLUDE USING gist (convenio_id WITH =, procedimiento_id WITH =, daterange(vigente_desde, vigente_hasta + 1, '[)') WITH &&)");
            DB::statement("ALTER TABLE coberturas_paciente ADD CONSTRAINT coberturas_paciente_tipo_valido CHECK (tipo_afiliado IN ('TITULAR', 'BENEFICIARIO'))");
            DB::statement('ALTER TABLE coberturas_paciente ADD CONSTRAINT coberturas_paciente_rango_valido CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde - 1)');
            DB::statement('ALTER TABLE coberturas_paciente ADD CONSTRAINT coberturas_paciente_cierre_completo CHECK ((cerrada_en IS NULL) = (cerrada_por IS NULL))');
            // Una sola vigente por paciente cada día: los rangos de una misma paciente no se solapan.
            DB::statement("ALTER TABLE coberturas_paciente ADD CONSTRAINT coberturas_paciente_sin_solapamiento EXCLUDE USING gist (paciente_id WITH =, daterange(vigente_desde, vigente_hasta + 1, '[)') WITH &&)");
            DB::statement('ALTER TABLE visaciones ADD CONSTRAINT visaciones_turno_o_consulta CHECK ((turno_id IS NULL) <> (consulta_id IS NULL))');
            DB::statement('ALTER TABLE visaciones ADD CONSTRAINT visaciones_anulacion_completa CHECK (
                (anulada_en IS NULL AND motivo_anulacion IS NULL AND anulada_por IS NULL)
                OR (anulada_en IS NOT NULL AND motivo_anulacion IS NOT NULL AND anulada_por IS NOT NULL))');
            DB::statement("ALTER TABLE lineas_visacion ADD CONSTRAINT lineas_visacion_valores_validos CHECK (
                (resultado = 'NO_CUBIERTO' AND copago IS NULL AND porcentaje IS NULL AND monto IS NULL)
                OR (resultado = 'APROBADO' AND copago = 'NINGUNO' AND porcentaje IS NULL AND monto IS NULL)
                OR (resultado = 'APROBADO' AND copago = 'PORCENTAJE' AND porcentaje >= 0 AND porcentaje <= 100 AND monto IS NULL)
                OR (resultado = 'APROBADO' AND copago = 'MONTO_FIJO' AND porcentaje IS NULL AND monto >= 0))");

            // La tarifa usada: la general o la del convenio (no las dos), con el monto y la moneda.
            DB::statement('ALTER TABLE prestaciones DROP CONSTRAINT prestaciones_monto_con_tarifa');
            DB::statement('ALTER TABLE prestaciones ADD CONSTRAINT prestaciones_monto_con_tarifa CHECK (
                (monto IS NULL) = (tarifa_id IS NULL AND tarifa_convenio_id IS NULL) AND (monto IS NULL) = (moneda_id IS NULL)
                AND NOT (tarifa_id IS NOT NULL AND tarifa_convenio_id IS NOT NULL))');
            // La parte de la paciente y la de la aseguradora suman exacto el total.
            DB::statement("ALTER TABLE prestaciones ADD CONSTRAINT prestaciones_partes_validas CHECK (
                (pagador IS NULL AND monto_paciente IS NULL AND monto_aseguradora IS NULL)
                OR (pagador IN ('PACIENTE', 'ASEGURADORA') AND monto IS NOT NULL AND monto_paciente >= 0 AND monto_aseguradora >= 0
                    AND monto_paciente + monto_aseguradora = monto))");
            // A cargo de la aseguradora: con su responsable, convenio, plan y visación. Si no, nada de la aseguradora.
            DB::statement("ALTER TABLE prestaciones ADD CONSTRAINT prestaciones_pagador_valido CHECK (
                (pagador = 'ASEGURADORA' AND responsable_pago_id IS NOT NULL AND convenio_id IS NOT NULL AND plan_convenio_id IS NOT NULL
                    AND visacion_id IS NOT NULL AND linea_visacion_id IS NOT NULL AND numero_visacion IS NOT NULL)
                OR ((pagador IS NULL OR pagador = 'PACIENTE') AND responsable_pago_id IS NULL AND convenio_id IS NULL AND plan_convenio_id IS NULL
                    AND tarifa_convenio_id IS NULL AND (monto_aseguradora IS NULL OR monto_aseguradora = 0) AND firma_recibida_en IS NULL))");
            DB::statement("ALTER TABLE prestaciones ADD CONSTRAINT prestaciones_liquidacion_valida CHECK (
                liquidacion IS NULL OR (liquidacion = 'PENDIENTE' AND pagador = 'ASEGURADORA' AND monto_aseguradora > 0))");
            DB::statement('ALTER TABLE prestaciones ADD CONSTRAINT prestaciones_firma_completa CHECK ((firma_recibida_en IS NULL) = (firma_recibida_por IS NULL))');
        }

        // Desde cuándo las prestaciones buscan visación: las anteriores nunca se recalculan.
        DB::table('hitos_sistema')->insert(['codigo' => 'CONVENIOS', 'fecha_hora' => now(), 'created_at' => now(), 'updated_at' => now()]);

        // Solo en una instalación existente (con módulos): en una nueva los crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'CONVENIOS'], ['nombre' => 'Convenios'])
                ->configurarEstados([Estado::VIGENTE, Estado::HISTORICO]);
            ModuloSistema::updateOrCreate(['codigo' => 'COBERTURAS'], ['nombre' => 'Coberturas de pacientes'])
                ->configurarEstados([Estado::VIGENTE, Estado::HISTORICO]);
            ModuloSistema::updateOrCreate(['codigo' => 'VISACIONES'], ['nombre' => 'Visaciones'])
                ->configurarEstados([Estado::VIGENTE, Estado::ANULADO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            foreach (PerfilesPredefinidos::PERMISOS_CONVENIOS as $modulo => $accionesPorPerfil) {
                if (ModuloSistema::where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, $accionesPorPerfil);
                }
            }
        }
    }

    /** Solo sin prestaciones de aseguradora ni repartos reemplazados. Los módulos, los permisos y los estados quedan. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['prestaciones_partes_validas', 'prestaciones_pagador_valido', 'prestaciones_liquidacion_valida', 'prestaciones_firma_completa', 'prestaciones_monto_con_tarifa'] as $restriccion) {
                DB::statement("ALTER TABLE prestaciones DROP CONSTRAINT IF EXISTS {$restriccion}");
            }
            DB::statement('ALTER TABLE prestaciones ADD CONSTRAINT prestaciones_monto_con_tarifa CHECK ((monto IS NULL) = (tarifa_id IS NULL) AND (monto IS NULL) = (moneda_id IS NULL))');
        }
        DB::statement('DROP INDEX IF EXISTS repartos_prestacion_uno_vigente');
        Schema::table('repartos_prestacion', function (Blueprint $table) {
            $table->dropIndex(['prestacion_id']);
            $table->unique('prestacion_id');
        });
        Schema::table('prestaciones', function (Blueprint $table) {
            $table->dropIndex(['visacion_id']);
            foreach (['responsable_pago_id', 'convenio_id', 'plan_convenio_id', 'visacion_id', 'linea_visacion_id', 'tarifa_convenio_id', 'firma_recibida_por', 'visacion_aplicada_por'] as $columna) {
                $table->dropConstrainedForeignId($columna);
            }
        });
        Schema::table('prestaciones', function (Blueprint $table) {
            $table->dropColumn(['pagador', 'numero_visacion', 'monto_paciente', 'monto_aseguradora', 'liquidacion', 'aviso_cobertura',
                'firma_recibida_en', 'visacion_aplicada_en', 'motivo_aplicacion']);
        });
        self::rehacerIndicesParcialesEnSqlite();
        Schema::dropIfExists('lineas_visacion');
        Schema::dropIfExists('visaciones');
        Schema::dropIfExists('coberturas_paciente');
        Schema::dropIfExists('tarifas_convenio');
        Schema::dropIfExists('planes_convenio');
        Schema::dropIfExists('convenios');
        DB::table('hitos_sistema')->where('codigo', 'CONVENIOS')->delete();
    }

    /**
     * Al agregar o quitar claves foráneas, SQLite rehace la tabla `prestaciones` y sus índices pierden el WHERE: los dos únicos
     * parciales (la base, una por consulta; la de cada informe) se vuelven a crear tal cual. En PostgreSQL no hace falta.
     */
    private static function rehacerIndicesParcialesEnSqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS prestaciones_base_unica');
        DB::statement('DROP INDEX IF EXISTS prestaciones_informe_unica');
        DB::statement('CREATE UNIQUE INDEX prestaciones_base_unica ON prestaciones (consulta_id) WHERE informe_estudio_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX prestaciones_informe_unica ON prestaciones (informe_estudio_id) WHERE informe_estudio_id IS NOT NULL');
    }
};
