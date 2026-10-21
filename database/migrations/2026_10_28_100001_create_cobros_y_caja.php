<?php

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3a económica (docs/cobros-caja.md). Aditiva: el código de los medios de pago, siete tablas, tres estados y tres módulos.
 *
 * - `medios_pago.codigo`: identifica los de referencia (EFECTIVO, TRANSFERENCIA, TARJETA); el monto inicial de la caja va en
 *   EFECTIVO. En una instalación existente carga (o adopta por el nombre) los tres de referencia; en una nueva lo hace el seeder.
 * - `cajas`: una por cajero abierta a la vez (`cajas_una_abierta_por_cajero`: abierta = sin `cerrada_en`). `cierres_caja` (cada
 *   cierre declarado, con su revisión) y `cierre_caja_medios` (esperado, contado y diferencia por medio).
 * - `cobros` (siempre completos), `cobro_prestaciones` (a lo sumo un cobro vigente por prestación:
 *   `cobro_prestaciones_un_cobro_vigente`, vigente = sin `revertido_en`, que usará la reversión de la fase 3b) y `lineas_cobro`.
 * - `movimientos_caja`: cada ingreso a la caja por medio (APERTURA y COBRO), con signo, para sumar después egresos y reversiones.
 *
 * Módulos CAJAS (VER, CREAR = abrir, EDITAR = cerrar), CIERRES_CAJA (VER = todas las cajas, EDITAR = aprobar o devolver) y
 * COBROS (VER, CREAR = cobrar). No crea cobros: las prestaciones existentes quedan sin cobrar. Idempotente en el catálogo, sin
 * leer filas durante `migrate --pretend` y, en una instalación nueva, deja módulos y medios a los seeders.
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::ACTIVO, Estado::INACTIVO, Estado::PENDIENTE, Estado::VIGENTE, Estado::APROBADO, Estado::ABIERTO, Estado::CERRADO, Estado::DEVUELTO];

    public function up(): void
    {
        // Los estados, si faltaran (ABIERTO, CERRADO y DEVUELTO son nuevos). Con DB::table, y no con Estado::idDe, para que
        // `migrate --pretend`, que no devuelve filas, no falle.
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::table('medios_pago', function (Blueprint $table) {
            $table->string('codigo', 20)->nullable()->unique();
        });

        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            // El cajero: el usuario que la abrió. Solo él cobra y la cierra.
            $table->foreignId('usuario_id')->constrained('users');
            // El día de la apertura (hora local): solo ese día recibe cobros.
            $table->date('fecha');
            $table->timestamp('abierta_en');
            // En efectivo, guaraníes enteros (0 o más).
            $table->decimal('monto_inicial', 15, 2);
            $table->foreignId('moneda_id')->constrained('monedas');
            $table->foreignId('estado_id')->constrained('estados');
            // El último cierre declarado. Vacío = ABIERTA (una devuelta lo conserva: no está abierta).
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();

            $table->index(['usuario_id', 'fecha']);
            $table->index('estado_id');
        });
        // Una sola caja abierta por cajero. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX cajas_una_abierta_por_cajero ON cajas (usuario_id) WHERE cerrada_en IS NULL');

        Schema::create('cierres_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamp('cerrado_en');
            // Obligatorio si algún medio tiene diferencia.
            $table->text('motivo_diferencia')->nullable();
            // PENDIENTE (de aprobación), APROBADO o DEVUELTO.
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('revisado_por')->nullable()->constrained('users');
            $table->timestamp('revisado_en')->nullable();
            $table->text('nota_revision')->nullable();
            $table->timestamps();

            $table->index('caja_id');
            $table->index('estado_id');
        });

        Schema::create('cierre_caja_medios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cierre_caja_id')->constrained('cierres_caja');
            $table->foreignId('medio_pago_id')->constrained('medios_pago');
            $table->decimal('esperado', 15, 2);
            $table->decimal('contado', 15, 2);
            // contado - esperado.
            $table->decimal('diferencia', 15, 2);
            $table->timestamps();

            $table->unique(['cierre_caja_id', 'medio_pago_id']);
        });

        Schema::create('cobros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas');
            // La responsable de pago en esta fase es siempre la paciente.
            $table->foreignId('paciente_id')->constrained('pacientes');
            $table->timestamp('cobrado_en');
            // La suma exacta de las prestaciones cubiertas, y la de las líneas.
            $table->decimal('total', 15, 2);
            $table->foreignId('moneda_id')->constrained('monedas');
            $table->foreignId('usuario_id')->constrained('users');
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();

            $table->index('caja_id');
            $table->index(['paciente_id', 'cobrado_en']);
        });

        Schema::create('cobro_prestaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_id')->constrained('cobros');
            $table->foreignId('prestacion_id')->constrained('prestaciones');
            $table->decimal('monto', 15, 2);
            // La reversión del cobro (fase 3b). Mientras esté vacío, el cobro de la prestación está vigente.
            $table->timestamp('revertido_en')->nullable();
            $table->timestamps();

            $table->unique(['cobro_id', 'prestacion_id']);
        });
        // A lo sumo un cobro vigente por prestación. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX cobro_prestaciones_un_cobro_vigente ON cobro_prestaciones (prestacion_id) WHERE revertido_en IS NULL');

        Schema::create('lineas_cobro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_id')->constrained('cobros');
            $table->foreignId('medio_pago_id')->constrained('medios_pago');
            $table->decimal('monto', 15, 2);
            // Opcional: número de transferencia o comprobante del POS.
            $table->string('referencia', 100)->nullable();
            $table->timestamps();

            $table->index('cobro_id');
        });

        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas');
            // APERTURA (el monto inicial, en efectivo) y COBRO (una línea de cobro). Después: egresos y reversiones.
            $table->string('tipo', 20);
            $table->foreignId('medio_pago_id')->constrained('medios_pago');
            // Con signo: los ingresos en positivo y los egresos (de fases futuras) en negativo. Lo esperado = la suma por medio.
            $table->decimal('monto', 15, 2);
            $table->foreignId('cobro_id')->nullable()->constrained('cobros');
            $table->foreignId('linea_cobro_id')->nullable()->unique()->constrained('lineas_cobro');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamp('registrado_en');
            $table->timestamps();

            $table->index(['caja_id', 'medio_pago_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cajas ADD CONSTRAINT cajas_monto_inicial_valido CHECK (monto_inicial >= 0)');
            DB::statement('ALTER TABLE cierre_caja_medios ADD CONSTRAINT cierre_caja_medios_valores_validos CHECK (esperado >= 0 AND contado >= 0 AND diferencia = contado - esperado)');
            DB::statement('ALTER TABLE cobros ADD CONSTRAINT cobros_total_positivo CHECK (total > 0)');
            DB::statement('ALTER TABLE cobro_prestaciones ADD CONSTRAINT cobro_prestaciones_monto_positivo CHECK (monto > 0)');
            DB::statement('ALTER TABLE lineas_cobro ADD CONSTRAINT lineas_cobro_monto_positivo CHECK (monto > 0)');
            DB::statement("ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_tipo_valido CHECK (
                (tipo = 'APERTURA' AND monto > 0 AND cobro_id IS NULL AND linea_cobro_id IS NULL)
                OR (tipo = 'COBRO' AND monto > 0 AND cobro_id IS NOT NULL AND linea_cobro_id IS NOT NULL))");
        }

        // Solo en una instalación existente (con módulos): en una nueva los crean ModuloSistemaSeeder y MediosPagoSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            (new MediosPagoSeeder)->run();

            ModuloSistema::updateOrCreate(['codigo' => 'CAJAS'], ['nombre' => 'Cajas'])
                ->configurarEstados([Estado::ABIERTO, Estado::CERRADO, Estado::APROBADO, Estado::DEVUELTO]);
            ModuloSistema::updateOrCreate(['codigo' => 'CIERRES_CAJA'], ['nombre' => 'Aprobación de cierres de caja'])
                ->configurarEstados([Estado::PENDIENTE, Estado::APROBADO, Estado::DEVUELTO]);
            ModuloSistema::updateOrCreate(['codigo' => 'COBROS'], ['nombre' => 'Cobros'])
                ->configurarEstados([Estado::VIGENTE]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            foreach (PerfilesPredefinidos::PERMISOS_COBROS_Y_CAJA as $modulo => $accionesPorPerfil) {
                if (ModuloSistema::where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, $accionesPorPerfil);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
        Schema::dropIfExists('lineas_cobro');
        Schema::dropIfExists('cobro_prestaciones');
        Schema::dropIfExists('cobros');
        Schema::dropIfExists('cierre_caja_medios');
        Schema::dropIfExists('cierres_caja');
        Schema::dropIfExists('cajas');
        Schema::table('medios_pago', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });
        // Los módulos, sus permisos, los estados y los medios de pago quedan (los perfiles y los cobros los pueden usar).
    }
};
