<?php

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\MotivosAnulacionFacturaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5a económica (docs/facturacion.md): la clínica como contribuyente emisor, el timbrado, la factura individual CONTADO
 * (simulada), el IVA de cada procedimiento y la anulación de la factura (directa en el plazo o con solicitud). Aditiva:
 *
 * - `clinicas`: los datos fiscales de la clínica, una sola fila por instalación (`clinicas_una_sola`). `sucursales.clinica_id`.
 * - `timbrados`: número, vigencia, establecimiento, punto de expedición y la numeración. Uno vigente por sucursal, establecimiento y
 *   punto (`timbrados_uno_vigente`).
 * - `facturas` y `detalles_factura`: la factura con el snapshot del emisor y del cliente, sus totales por tasa y una línea por
 *   prestación. Una prestación se factura una sola vez (`detalles_factura_una_por_prestacion`, entre los no liberados).
 * - `motivos_anulacion_factura` (catálogo, con los tres de referencia) y `solicitudes_anulacion_factura` (una pendiente por factura:
 *   `solicitudes_anulacion_una_pendiente`).
 * - `procedimientos.tasa_iva` (10 por defecto, 5 o 0 = exenta) y `cobros.factura_id` (vacío en los cobros existentes).
 * - El hito FACTURACION: los cobros anteriores no tienen factura y nunca se facturan.
 *
 * El estado que falta (COBRADO; EMITIDO y ANULADO ya existían), los cinco módulos y sus permisos (`PerfilesPredefinidos::PERMISOS_FACTURACION`). No lee
 * filas durante `migrate --pretend`, no factura ni cambia cobros, cajas ni prestaciones existentes y, en una instalación nueva, deja
 * los módulos al seeder. Los tres motivos de referencia los carga siempre (si faltan).
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::EMITIDO, Estado::COBRADO, Estado::ANULADO, Estado::VIGENTE, Estado::HISTORICO, Estado::PENDIENTE,
        Estado::APROBADO, Estado::RECHAZADO, Estado::ACTIVO, Estado::INACTIVO];

    public function up(): void
    {
        // Con DB::table, y no con Estado::idDe, para que `migrate --pretend`, que no devuelve filas, no falle.
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('clinicas', function (Blueprint $table) {
            $table->id();
            // Siempre 1: una sola clínica por instalación.
            $table->unsignedTinyInteger('instalacion')->default(1);
            // UNIPERSONAL (persona física con RUC) o SOCIEDAD.
            $table->string('tipo_contribuyente', 12);
            $table->string('razon_social', 200);
            $table->string('nombre_fantasia', 200)->nullable();
            $table->string('ruc', 12)->unique();
            $table->string('actividad_economica', 200);
            $table->string('direccion_fiscal', 200);
            $table->foreignId('ciudad_id')->nullable()->constrained('ciudades');
            $table->string('telefono', 20)->nullable();
            $table->string('correo', 150)->nullable();
            // Ruta del logotipo en el disco local (vacío: el de config/clinexa.php).
            $table->string('logotipo', 200)->nullable();
            $table->timestamps();

            $table->unique('instalacion', 'clinicas_una_sola');
        });

        Schema::table('sucursales', function (Blueprint $table) {
            $table->foreignId('clinica_id')->nullable()->constrained('clinicas');
        });

        Schema::create('timbrados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinica_id')->constrained('clinicas');
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->string('numero', 8);
            $table->date('vigente_desde');
            $table->date('vigente_hasta');
            $table->string('establecimiento', 3);
            $table->string('punto_expedicion', 3);
            $table->unsignedInteger('numero_inicial');
            $table->unsignedInteger('numero_final');
            // El último número usado (numero_inicial - 1 mientras no se emitió ninguno).
            $table->unsignedInteger('numero_actual');
            // VIGENTE o HISTORICO (con quién y cuándo). Nunca se borra.
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('cargado_por')->constrained('users');
            $table->timestamp('historico_en')->nullable();
            $table->foreignId('historico_por')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('sucursal_id');
        });
        // Uno vigente por sucursal, establecimiento y punto de expedición. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX timbrados_uno_vigente ON timbrados (sucursal_id, establecimiento, punto_expedicion) WHERE historico_en IS NULL');

        Schema::create('motivos_anulacion_factura', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            // Los de referencia (MotivosAnulacionFacturaSeeder): no se edita desde la pantalla.
            $table->string('codigo', 30)->nullable()->unique();
            // "Otro (detallar)": el detalle es obligatorio.
            $table->boolean('requiere_detalle')->default(false);
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('facturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timbrado_id')->constrained('timbrados');
            $table->foreignId('sucursal_id')->constrained('sucursales');
            // El snapshot del timbrado y la numeración: 001-001-0000001.
            $table->string('numero_timbrado', 8);
            $table->date('timbrado_desde');
            $table->date('timbrado_hasta');
            $table->string('establecimiento', 3);
            $table->string('punto_expedicion', 3);
            $table->unsignedInteger('numero');
            $table->string('numero_completo', 15);
            $table->timestamp('emitida_en');
            $table->string('condicion', 10);
            // El snapshot del emisor: no cambia aunque cambien los datos de la clínica o de la sucursal.
            $table->string('emisor_razon_social', 200);
            $table->string('emisor_nombre_fantasia', 200)->nullable();
            $table->string('emisor_ruc', 12);
            $table->string('emisor_actividad', 200);
            $table->string('emisor_direccion', 200);
            $table->string('emisor_telefono', 20)->nullable();
            // El cliente: la paciente, con su nombre y documento principal copiados.
            $table->foreignId('paciente_id')->constrained('pacientes');
            $table->string('cliente_nombre', 200);
            $table->string('cliente_documento', 40);
            $table->foreignId('moneda_id')->constrained('monedas');
            // Totales por tasa, en guaraníes enteros: la suma exacta de las líneas.
            $table->decimal('gravado_10', 15, 2);
            $table->decimal('iva_10', 15, 2);
            $table->decimal('gravado_5', 15, 2);
            $table->decimal('iva_5', 15, 2);
            $table->decimal('exento', 15, 2);
            $table->decimal('total', 15, 2);
            // EMITIDO (pendiente de cobro), COBRADO o ANULADO.
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('usuario_id')->constrained('users');
            $table->foreignId('caja_id')->constrained('cajas');
            // Emitida en simulación (sin validez fiscal) y por qué emisor (EmisorDocumentos).
            $table->boolean('simulada');
            $table->string('emisor_documentos', 20);
            // La anulada a la que reemplaza (opcional).
            $table->foreignId('reemplaza_a_id')->nullable()->constrained('facturas');
            $table->timestamp('anulada_en')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users');
            $table->foreignId('motivo_anulacion_id')->nullable()->constrained('motivos_anulacion_factura');
            $table->text('detalle_anulacion')->nullable();
            $table->timestamps();

            $table->unique(['timbrado_id', 'numero'], 'facturas_numero_unico');
            $table->unique('reemplaza_a_id', 'facturas_reemplaza_una_vez');
            $table->index('paciente_id');
            $table->index('emitida_en');
        });

        Schema::create('detalles_factura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas');
            $table->foreignId('prestacion_id')->constrained('prestaciones');
            $table->string('descripcion', 150);
            $table->unsignedSmallInteger('cantidad');
            // Precio con IVA incluido; monto = cantidad × precio = gravado + IVA.
            $table->decimal('precio_unitario', 15, 2);
            $table->decimal('monto', 15, 2);
            // La tasa del procedimiento al emitir (snapshot): 10, 5 o 0 (exenta).
            $table->unsignedTinyInteger('tasa_iva');
            $table->decimal('gravado', 15, 2);
            $table->decimal('iva', 15, 2);
            // Liberada al anular la factura: la prestación vuelve a quedar libre para facturarse.
            $table->timestamp('liberada_en')->nullable();
            $table->timestamps();

            $table->index('factura_id');
            $table->index('prestacion_id');
        });
        // Una prestación se factura una sola vez (entre las facturas no anuladas). Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX detalles_factura_una_por_prestacion ON detalles_factura (prestacion_id) WHERE liberada_en IS NULL');

        Schema::create('solicitudes_anulacion_factura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamp('solicitada_en');
            $table->foreignId('motivo_anulacion_id')->constrained('motivos_anulacion_factura');
            $table->text('detalle')->nullable();
            // PENDIENTE, APROBADO o RECHAZADO (con quién, cuándo y la nota).
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('revisado_por')->nullable()->constrained('users');
            $table->timestamp('revisado_en')->nullable();
            $table->text('nota_revision')->nullable();
            $table->timestamps();

            $table->index('factura_id');
        });
        // Una sola pendiente por factura. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX solicitudes_anulacion_una_pendiente ON solicitudes_anulacion_factura (factura_id) WHERE revisado_en IS NULL');

        Schema::table('procedimientos', function (Blueprint $table) {
            // Precios con IVA incluido: 10 (por defecto), 5 o 0 (exenta). Se copia a la línea de la factura.
            $table->unsignedTinyInteger('tasa_iva')->default(10);
        });

        Schema::table('cobros', function (Blueprint $table) {
            // La factura contra la que se registró (vacío en los cobros anteriores al hito FACTURACION).
            $table->foreignId('factura_id')->nullable()->unique()->constrained('facturas');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clinicas ADD CONSTRAINT clinicas_instalacion_unica CHECK (instalacion = 1)');
            DB::statement("ALTER TABLE clinicas ADD CONSTRAINT clinicas_tipo_valido CHECK (tipo_contribuyente IN ('UNIPERSONAL', 'SOCIEDAD'))");
            DB::statement("ALTER TABLE clinicas ADD CONSTRAINT clinicas_ruc_formato CHECK (ruc ~ '^[0-9]{6,8}-[0-9]$')");
            DB::statement("ALTER TABLE timbrados ADD CONSTRAINT timbrados_numeracion_valida CHECK (
                numero ~ '^[0-9]{8}$' AND establecimiento ~ '^[0-9]{3}$' AND punto_expedicion ~ '^[0-9]{3}$'
                AND numero_inicial >= 1 AND numero_final >= numero_inicial AND numero_final <= 9999999
                AND numero_actual >= numero_inicial - 1 AND numero_actual <= numero_final)");
            DB::statement('ALTER TABLE timbrados ADD CONSTRAINT timbrados_vigencia_valida CHECK (vigente_hasta >= vigente_desde)');
            DB::statement('ALTER TABLE timbrados ADD CONSTRAINT timbrados_historico_completo CHECK ((historico_en IS NULL) = (historico_por IS NULL))');
            DB::statement("ALTER TABLE facturas ADD CONSTRAINT facturas_numeracion_valida CHECK (
                numero >= 1 AND numero <= 9999999 AND establecimiento ~ '^[0-9]{3}$' AND punto_expedicion ~ '^[0-9]{3}$'
                AND numero_completo = establecimiento || '-' || punto_expedicion || '-' || lpad(numero::text, 7, '0'))");
            DB::statement("ALTER TABLE facturas ADD CONSTRAINT facturas_condicion_valida CHECK (condicion = 'CONTADO')");
            DB::statement('ALTER TABLE facturas ADD CONSTRAINT facturas_totales_validos CHECK (
                gravado_10 >= 0 AND iva_10 >= 0 AND gravado_5 >= 0 AND iva_5 >= 0 AND exento >= 0 AND total > 0
                AND gravado_10 = trunc(gravado_10) AND iva_10 = trunc(iva_10) AND gravado_5 = trunc(gravado_5) AND iva_5 = trunc(iva_5)
                AND exento = trunc(exento) AND total = gravado_10 + iva_10 + gravado_5 + iva_5 + exento)');
            DB::statement('ALTER TABLE facturas ADD CONSTRAINT facturas_anulacion_completa CHECK (
                (anulada_en IS NULL AND anulada_por IS NULL AND motivo_anulacion_id IS NULL)
                OR (anulada_en IS NOT NULL AND anulada_por IS NOT NULL AND motivo_anulacion_id IS NOT NULL))');
            DB::statement('ALTER TABLE facturas ADD CONSTRAINT facturas_no_se_reemplaza_a_si_misma CHECK (reemplaza_a_id IS NULL OR reemplaza_a_id <> id)');
            DB::statement('ALTER TABLE detalles_factura ADD CONSTRAINT detalles_factura_montos_validos CHECK (
                cantidad >= 1 AND precio_unitario > 0 AND monto = cantidad * precio_unitario AND monto = trunc(monto)
                AND tasa_iva IN (0, 5, 10) AND gravado >= 0 AND iva >= 0 AND monto = gravado + iva
                AND iva = CASE tasa_iva WHEN 10 THEN round(monto / 11) WHEN 5 THEN round(monto / 21) ELSE 0 END)');
            DB::statement('ALTER TABLE procedimientos ADD CONSTRAINT procedimientos_tasa_iva_valida CHECK (tasa_iva IN (0, 5, 10))');
            DB::statement('ALTER TABLE solicitudes_anulacion_factura ADD CONSTRAINT solicitudes_anulacion_revision_completa CHECK (
                (revisado_en IS NULL AND revisado_por IS NULL) OR (revisado_en IS NOT NULL AND revisado_por IS NOT NULL AND revisado_por <> usuario_id))');
        }

        // Desde cuándo los cobros llevan factura: los anteriores nunca se facturan.
        DB::table('hitos_sistema')->insert(['codigo' => 'FACTURACION', 'fecha_hora' => now(), 'created_at' => now(), 'updated_at' => now()]);

        // Solo en una instalación existente (con módulos): en una nueva los crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'FACTURAS'], ['nombre' => 'Facturas'])
                ->configurarEstados([Estado::EMITIDO, Estado::COBRADO, Estado::ANULADO]);
            ModuloSistema::updateOrCreate(['codigo' => 'ANULACIONES_FACTURA'], ['nombre' => 'Aprobación de anulaciones de factura'])
                ->configurarEstados([Estado::PENDIENTE, Estado::APROBADO, Estado::RECHAZADO]);
            ModuloSistema::updateOrCreate(['codigo' => 'TIMBRADOS'], ['nombre' => 'Timbrados'])
                ->configurarEstados([Estado::VIGENTE, Estado::HISTORICO]);
            ModuloSistema::updateOrCreate(['codigo' => 'DATOS_FISCALES'], ['nombre' => 'Datos fiscales (clínica y tasas de IVA)'])
                ->configurarEstados([Estado::ACTIVO, Estado::INACTIVO]);
            ModuloSistema::updateOrCreate(['codigo' => 'MOTIVOS_ANULACION'], ['nombre' => 'Motivos de anulación de factura'])
                ->configurarEstados([Estado::ACTIVO, Estado::INACTIVO]);
        }
        // Los tres motivos de referencia (con DB::table: no falla en `--pretend`).
        (new MotivosAnulacionFacturaSeeder)->run();

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            foreach (PerfilesPredefinidos::PERMISOS_FACTURACION as $modulo => $accionesPorPerfil) {
                if (ModuloSistema::where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, $accionesPorPerfil);
                }
            }
        }
    }

    /** Solo sin facturas. Los módulos, los permisos y los estados quedan. */
    public function down(): void
    {
        Schema::table('cobros', function (Blueprint $table) {
            $table->dropUnique(['factura_id']);
        });
        Schema::table('cobros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factura_id');
        });
        Schema::table('procedimientos', function (Blueprint $table) {
            $table->dropColumn('tasa_iva');
        });
        Schema::dropIfExists('solicitudes_anulacion_factura');
        Schema::dropIfExists('detalles_factura');
        Schema::dropIfExists('facturas');
        Schema::dropIfExists('motivos_anulacion_factura');
        Schema::dropIfExists('timbrados');
        Schema::table('sucursales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clinica_id');
        });
        Schema::dropIfExists('clinicas');
        DB::table('hitos_sistema')->where('codigo', 'FACTURACION')->delete();
    }
};
