<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reemplaza la columna enum "estado" de cada tabla por estado_id (FK a estados).
 *
 * Tabla por tabla: agrega estado_id nullable → copia (estado 'X' → id del estado de código 'X')
 * → VERIFICA que la copia sea 1 a 1 (misma cantidad de filas por valor, ninguna sin estado_id)
 * → recién entonces hace estado_id obligatorio y borra la columna vieja. Si una verificación
 * falla se lanza una excepción; en PostgreSQL la migración corre en una transacción y no queda
 * nada a medias.
 *
 * perfiles_acceso no tenía estado: recibe estado_id con todos sus registros en ACTIVO.
 */
return new class extends Migration
{
    /** Tabla => valores que admitía su enum (para reconstruirlo en down()). */
    private const TABLAS = [
        'modulos_sistema' => ['ACTIVO', 'INACTIVO'],
        'personas' => ['ACTIVO', 'INACTIVO'],
        'users' => ['ACTIVO', 'BLOQUEADO', 'INACTIVO'],
        'especialidades' => ['ACTIVO', 'INACTIVO'],
        'sucursales' => ['ACTIVO', 'INACTIVO'],
        'tipos_documento' => ['ACTIVO', 'INACTIVO'],
        'catalogo_cie10' => ['ACTIVO', 'INACTIVO'],
        'medios_pago' => ['ACTIVO', 'INACTIVO'],
        'categorias_gasto' => ['ACTIVO', 'INACTIVO'],
        'procedimientos' => ['ACTIVO', 'INACTIVO'],
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLAS) as $tabla) {
            $this->convertir($tabla);
        }

        // perfiles_acceso: columna nueva, todos ACTIVO.
        Schema::table('perfiles_acceso', function (Blueprint $table) {
            $table->foreignId('estado_id')->nullable()->constrained('estados');
        });
        DB::table('perfiles_acceso')->update(['estado_id' => $this->idEstado('ACTIVO')]);
        $this->verificarSinNulos('perfiles_acceso');
        Schema::table('perfiles_acceso', function (Blueprint $table) {
            $table->unsignedBigInteger('estado_id')->nullable(false)->change();
        });
    }

    private function convertir(string $tabla): void
    {
        $antes = $this->conteoPorValor($tabla, "{$tabla}.estado");

        $desconocidos = array_diff(array_keys($antes), DB::table('estados')->pluck('codigo')->all());
        if ($desconocidos) {
            throw new RuntimeException("{$tabla}: valores de estado sin equivalente en estados: ".implode(', ', $desconocidos));
        }

        Schema::table($tabla, function (Blueprint $table) {
            $table->foreignId('estado_id')->nullable()->constrained('estados');
        });

        DB::table($tabla)->update([
            'estado_id' => DB::raw("(select estados.id from estados where estados.codigo = {$tabla}.estado)"),
        ]);

        // Verificación 1 a 1 antes de tocar la columna vieja.
        $this->verificarSinNulos($tabla);
        $despues = $this->conteoPorValor($tabla, 'estados.codigo', unirEstados: true);
        if ($antes != $despues) {
            throw new RuntimeException("{$tabla}: el backfill no coincide. Antes ".json_encode($antes).', después '.json_encode($despues));
        }

        Schema::table($tabla, function (Blueprint $table) {
            $table->unsignedBigInteger('estado_id')->nullable(false)->change();
        });
        Schema::table($tabla, function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }

    /**
     * @return array<string, int> valor => cantidad de filas
     */
    private function conteoPorValor(string $tabla, string $columna, bool $unirEstados = false): array
    {
        $query = DB::table($tabla);
        if ($unirEstados) {
            $query->join('estados', 'estados.id', '=', "{$tabla}.estado_id");
        }

        $conteo = $query->selectRaw("{$columna} as valor, count(*) as n")->groupBy($columna)->pluck('n', 'valor')
            ->map(fn ($n) => (int) $n)->all();
        ksort($conteo);

        return $conteo;
    }

    private function verificarSinNulos(string $tabla): void
    {
        $nulos = DB::table($tabla)->whereNull('estado_id')->count();
        if ($nulos > 0) {
            throw new RuntimeException("{$tabla}: {$nulos} fila(s) quedaron sin estado_id.");
        }
    }

    private function idEstado(string $codigo): int
    {
        return DB::table('estados')->where('codigo', $codigo)->value('id')
            ?? throw new RuntimeException("Falta el estado {$codigo}.");
    }

    public function down(): void
    {
        Schema::table('perfiles_acceso', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estado_id');
        });

        foreach (self::TABLAS as $tabla => $valores) {
            Schema::table($tabla, function (Blueprint $table) use ($valores) {
                $table->enum('estado', $valores)->default('ACTIVO');
            });
            DB::table($tabla)->update([
                'estado' => DB::raw("(select estados.codigo from estados where estados.id = {$tabla}.estado_id)"),
            ]);
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropConstrainedForeignId('estado_id');
            });
        }
    }
};
