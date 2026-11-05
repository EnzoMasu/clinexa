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
 * Compras y gastos (docs/compras-gastos.md), 2 de 2: la compra o gasto con su comprobante en la misma fila (uno por compra), en
 * guaraníes enteros. PENDIENTE ("Borrador") -> CONFIRMADO -> ANULADO; el borrador también se descarta (ANULADO con motivo). Aditiva:
 *
 * - `compras`: proveedor, categoría de gasto, fecha, comprobante (tipo, número, timbrado, fecha, imagen), montos por tasa e IVA (solo
 *   con un tipo que discrimina IVA; si no, null los cinco), total, condición (CONTADO o CREDITO, con vencimiento y anticipo), estado,
 *   quién la cargó, la confirmó o la anuló (con motivo).
 * - Un comprobante (proveedor + tipo + número) una sola vez entre las compras no anuladas (`compras_comprobante_unico`).
 * - Los CHECK de PostgreSQL. Las fechas no futuras se validan en la aplicación (un CHECK no puede depender de "hoy").
 *
 * Los estados (PENDIENTE, CONFIRMADO y ANULADO ya existen; se aseguran igual), los tres módulos y sus permisos
 * (`PerfilesPredefinidos::PERMISOS_COMPRAS`). No lee filas durante `migrate --pretend` y, en una instalación nueva, deja los módulos
 * al seeder. No toca la caja (pendiente para una fase posterior).
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::PENDIENTE, Estado::CONFIRMADO, Estado::ANULADO];

    public function up(): void
    {
        // Con DB::table, y no con Estado::idDe, para que `migrate --pretend`, que no devuelve filas, no falle.
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
            $table->date('fecha');
            // El comprobante: qué exige lo dicen las casillas de su tipo.
            $table->foreignId('tipo_comprobante_id')->constrained('tipos_comprobante_compra');
            $table->string('numero_comprobante', 50)->nullable();
            $table->string('timbrado', 20)->nullable();
            $table->date('fecha_comprobante')->nullable();
            // Ruta en el disco local (privado), carpeta compras/. Opcional.
            $table->string('imagen_comprobante')->nullable();
            // Guaraní, fijo.
            $table->foreignId('moneda_id')->constrained('monedas');
            // Con IVA incluido, por tasa; null los cinco si el tipo no discrimina IVA.
            $table->bigInteger('monto_10')->nullable();
            $table->bigInteger('monto_5')->nullable();
            $table->bigInteger('monto_exento')->nullable();
            $table->bigInteger('iva_10')->nullable();
            $table->bigInteger('iva_5')->nullable();
            $table->bigInteger('total');
            // CONTADO o CREDITO (App\Enums\CondicionCompra).
            $table->string('condicion', 10);
            $table->date('vencimiento')->nullable();
            $table->bigInteger('anticipo')->default(0);
            $table->text('observaciones')->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('registrada_por')->constrained('users');
            $table->foreignId('confirmada_por')->nullable()->constrained('users');
            $table->timestamp('confirmada_en')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users');
            $table->timestamp('anulada_en')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();

            $table->index('fecha');
            $table->index(['proveedor_id', 'fecha']);
            $table->index(['categoria_gasto_id', 'fecha']);
            $table->index('estado_id');
        });
        // Un comprobante una sola vez entre las compras no anuladas; sin número, fuera del control. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX compras_comprobante_unico ON compras (proveedor_id, tipo_comprobante_id, numero_comprobante)
            WHERE numero_comprobante IS NOT NULL AND anulada_en IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE compras ADD CONSTRAINT compras_total_positivo CHECK (total > 0)');
            DB::statement('ALTER TABLE compras ADD CONSTRAINT compras_desglose_valido CHECK (
                (monto_10 IS NULL AND monto_5 IS NULL AND monto_exento IS NULL AND iva_10 IS NULL AND iva_5 IS NULL)
                OR (monto_10 IS NOT NULL AND monto_5 IS NOT NULL AND monto_exento IS NOT NULL AND iva_10 IS NOT NULL AND iva_5 IS NOT NULL
                    AND monto_10 >= 0 AND monto_5 >= 0 AND monto_exento >= 0 AND iva_10 >= 0 AND iva_5 >= 0
                    AND total = monto_10 + monto_5 + monto_exento))');
            DB::statement("ALTER TABLE compras ADD CONSTRAINT compras_condicion_valida CHECK (
                (condicion = 'CONTADO' AND vencimiento IS NULL AND anticipo = 0)
                OR (condicion = 'CREDITO' AND vencimiento IS NOT NULL AND vencimiento >= fecha AND anticipo >= 0 AND anticipo < total))");
            DB::statement('ALTER TABLE compras ADD CONSTRAINT compras_confirmacion_completa CHECK ((confirmada_en IS NULL) = (confirmada_por IS NULL)
                AND (confirmada_por IS NULL OR confirmada_por <> registrada_por))');
            DB::statement('ALTER TABLE compras ADD CONSTRAINT compras_anulacion_completa CHECK (
                (anulada_en IS NULL AND anulada_por IS NULL AND motivo_anulacion IS NULL)
                OR (anulada_en IS NOT NULL AND anulada_por IS NOT NULL AND motivo_anulacion IS NOT NULL
                    AND char_length(motivo_anulacion) BETWEEN 5 AND 300))');
        }

        // Solo en una instalación existente (con módulos): en una nueva los crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'COMPRAS'], ['nombre' => 'Compras y gastos'])
                ->configurarEstados([Estado::PENDIENTE, Estado::CONFIRMADO, Estado::ANULADO]);
            ModuloSistema::updateOrCreate(['codigo' => 'CONFIRMACION_COMPRAS'], ['nombre' => 'Confirmación de compras'])
                ->configurarEstados([Estado::PENDIENTE, Estado::CONFIRMADO]);
            ModuloSistema::updateOrCreate(['codigo' => 'TIPOS_COMPROBANTE_COMPRA'], ['nombre' => 'Tipos de comprobante de compra'])
                ->configurarEstados([Estado::ACTIVO, Estado::INACTIVO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            foreach (PerfilesPredefinidos::PERMISOS_COMPRAS as $modulo => $accionesPorPerfil) {
                if (ModuloSistema::where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, $accionesPorPerfil);
                }
            }
        }
    }

    /** Solo sin compras. Los módulos, los permisos y los estados quedan. */
    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
