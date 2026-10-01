<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paso 1 de la fusión de PropietarioEquipo en Proveedor: SOLO COPIA, no borra nada (eso lo hace la
 * migración siguiente, después de verificar de nuevo).
 *
 * Por cada propietario de equipo:
 * - si la persona ya es proveedor: se le copian los datos bancarios (si no tenía) y se le asigna la
 *   categoría "Equipos médicos" (sin duplicar). Su estado no cambia.
 * - si no lo es: se crea el proveedor con los datos bancarios y el estado del propietario, sin
 *   condiciones comerciales, con la categoría "Equipos médicos".
 *
 * Si un proveedor ya tiene datos bancarios DISTINTOS de los del propietario, la migración se detiene
 * sin cambiar nada y lista esas personas (para decidir a mano: no se pierde ningún dato).
 * Al final verifica uno por uno. Idempotente: correrla de nuevo no duplica.
 */
return new class extends Migration
{
    public const CATEGORIA = 'Equipos médicos';

    public function up(): void
    {
        $propietarios = DB::table('propietarios_equipo')->get();
        if ($propietarios->isEmpty()) {
            return;
        }

        $proveedores = DB::table('proveedores')->whereIn('persona_id', $propietarios->pluck('persona_id'))->get()->keyBy('persona_id');

        $conflictos = $propietarios->filter(function ($propietario) use ($proveedores) {
            $proveedor = $proveedores[$propietario->persona_id] ?? null;

            return $proveedor && filled($proveedor->datos_bancarios) && filled($propietario->datos_bancarios)
                && $proveedor->datos_bancarios !== $propietario->datos_bancarios;
        });
        if ($conflictos->isNotEmpty()) {
            $detalle = $conflictos->map(fn ($propietario) => 'persona '.$propietario->persona_id.' (proveedor: "'
                .$proveedores[$propietario->persona_id]->datos_bancarios.'", propietario: "'.$propietario->datos_bancarios.'")')->join('; ');

            throw new RuntimeException("Datos bancarios distintos entre proveedor y propietario de equipo: {$detalle}. No se modificó nada.");
        }

        $categoriaId = $this->categoriaEquipos();

        foreach ($propietarios as $propietario) {
            $proveedor = $proveedores[$propietario->persona_id] ?? null;

            if ($proveedor) {
                if (blank($proveedor->datos_bancarios) && filled($propietario->datos_bancarios)) {
                    DB::table('proveedores')->where('id', $proveedor->id)->update(['datos_bancarios' => $propietario->datos_bancarios, 'updated_at' => now()]);
                }
                $proveedorId = $proveedor->id;
            } else {
                $proveedorId = DB::table('proveedores')->insertGetId([
                    'persona_id' => $propietario->persona_id,
                    'condiciones_comerciales' => null,
                    'datos_bancarios' => $propietario->datos_bancarios,
                    'estado_id' => $propietario->estado_id,
                    'created_at' => $propietario->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('proveedor_categoria')->insertOrIgnore(['proveedor_id' => $proveedorId, 'categoria_proveedor_id' => $categoriaId]);
        }

        $this->verificar($propietarios, $proveedores, $categoriaId);
    }

    /** No se deshace por código: para volver atrás, restaurar el respaldo previo. */
    public function down(): void {}

    /** id de "Equipos médicos" (se crea ACTIVA si todavía no existe). */
    private function categoriaEquipos(): int
    {
        $id = DB::table('categorias_proveedor')->where('nombre', self::CATEGORIA)->value('id');

        return $id ?? DB::table('categorias_proveedor')->insertGetId([
            'nombre' => self::CATEGORIA,
            'estado_id' => DB::table('estados')->where('codigo', 'ACTIVO')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Uno por uno: cada propietario tiene su proveedor, con sus datos bancarios y "Equipos médicos". */
    private function verificar($propietarios, $proveedoresPrevios, int $categoriaId): void
    {
        foreach ($propietarios as $propietario) {
            $proveedor = DB::table('proveedores')->where('persona_id', $propietario->persona_id)->first();
            $previo = $proveedoresPrevios[$propietario->persona_id] ?? null;

            $errores = array_filter([
                ! $proveedor ? 'no tiene proveedor' : null,
                $proveedor && filled($propietario->datos_bancarios) && $proveedor->datos_bancarios !== $propietario->datos_bancarios ? 'datos bancarios distintos' : null,
                $proveedor && $previo === null && (int) $proveedor->estado_id !== (int) $propietario->estado_id ? 'estado distinto' : null,
                $proveedor && $previo !== null && (int) $proveedor->estado_id !== (int) $previo->estado_id ? 'cambió el estado del proveedor' : null,
                $proveedor && ! DB::table('proveedor_categoria')->where(['proveedor_id' => $proveedor->id, 'categoria_proveedor_id' => $categoriaId])->exists() ? 'sin la categoría Equipos médicos' : null,
            ]);

            if ($errores) {
                throw new RuntimeException("La copia del propietario de equipo de la persona {$propietario->persona_id} no quedó bien: ".implode(', ', $errores).'.');
            }
        }
    }
};
