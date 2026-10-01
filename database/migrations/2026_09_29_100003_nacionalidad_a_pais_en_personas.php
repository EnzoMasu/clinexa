<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personas.nacionalidad (texto libre) pasa a personas.pais_nacionalidad_id (FK a paises). Es
 * independiente de ciudad_id: una persona puede vivir en Concepción y ser de nacionalidad brasileña.
 *
 * Backfill: "Paraguaya", "Paraguayo" (cualquier mayúscula/espacios) o el nombre exacto de un país
 * cargado -> ese país. Se verifica 1 a 1 antes de borrar la columna vieja. Si algún valor no
 * corresponde a ningún país, la migración se detiene y lista esos valores SIN borrar nada, para
 * decidir a mano qué hacer con ellos (así el texto no se pierde).
 */
return new class extends Migration
{
    /** Gentilicios conocidos => nombre del país en paises. */
    private const GENTILICIOS = [
        'paraguaya' => 'Paraguay',
        'paraguayo' => 'Paraguay',
    ];

    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->foreignId('pais_nacionalidad_id')->nullable()->after('sexo')->constrained('paises');
        });

        // Cada valor distinto de nacionalidad -> id de país (o null si no se reconoce).
        $paises = DB::table('paises')->pluck('id', 'nombre')->mapWithKeys(fn ($id, $nombre) => [self::normalizar($nombre) => $id]);
        $mapa = DB::table('personas')->whereNotNull('nacionalidad')->distinct()->pluck('nacionalidad')
            ->mapWithKeys(function (string $valor) use ($paises) {
                $clave = self::normalizar($valor);
                $pais = self::GENTILICIOS[$clave] ?? null;

                return [$valor => $paises[$pais !== null ? self::normalizar($pais) : $clave] ?? null];
            });

        // Vacíos ("", "  ") no son una nacionalidad: quedan en null sin detener la migración.
        $sinPais = $mapa->filter(fn ($id, $valor) => $id === null && trim($valor) !== '');
        if ($sinPais->isNotEmpty()) {
            $detalle = $sinPais->keys()->map(fn (string $valor) => sprintf('"%s" (%d persona/s)',
                $valor, DB::table('personas')->where('nacionalidad', $valor)->count()))->join(', ');

            throw new RuntimeException("Nacionalidades que no corresponden a ningún país: {$detalle}. No se modificó nada: corríjalas a mano (o cargue el país) y vuelva a migrar.");
        }

        foreach ($mapa->filter() as $valor => $paisId) {
            DB::table('personas')->where('nacionalidad', $valor)->update(['pais_nacionalidad_id' => $paisId]);
        }

        // Verificación 1 a 1 antes de borrar: cada persona con nacionalidad tiene el país que le corresponde.
        $mal = DB::table('personas')->whereNotNull('nacionalidad')->get(['id', 'nacionalidad', 'pais_nacionalidad_id'])
            ->reject(fn ($persona) => self::id($persona->pais_nacionalidad_id) === self::id($mapa[$persona->nacionalidad] ?? null))
            ->pluck('id');
        if ($mal->isNotEmpty()) {
            throw new RuntimeException('El backfill de nacionalidad no coincide en las personas '.$mal->join(', ').'. No se borró la columna.');
        }

        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('nacionalidad');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->string('nacionalidad', 50)->nullable()->after('sexo');
        });

        // Paraguay vuelve como "Paraguaya" (el valor que se usaba); el resto, con el nombre del país.
        foreach (DB::table('paises')->whereIn('id', DB::table('personas')->select('pais_nacionalidad_id'))->pluck('nombre', 'id') as $id => $nombre) {
            DB::table('personas')->where('pais_nacionalidad_id', $id)
                ->update(['nacionalidad' => $nombre === 'Paraguay' ? 'Paraguaya' : mb_substr($nombre, 0, 50)]);
        }

        Schema::table('personas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pais_nacionalidad_id');
        });
    }

    /** Los drivers devuelven los ids como int o string según la base: se comparan como int. */
    private static function id(mixed $valor): ?int
    {
        return $valor === null ? null : (int) $valor;
    }

    private static function normalizar(string $texto): string
    {
        return mb_strtolower(trim($texto));
    }
};
