<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administrador principal: users.administrador_principal, con un índice único parcial (a lo sumo UN usuario
 * en true; PostgreSQL y SQLite lo admiten). La marca no se cambia desde la aplicación: la pone esta migración
 * (un solo uso) y después solo el comando clinexa:traspasar-principal. Ver docs/perfiles.md.
 *
 * Backfill: el usuario cuya persona tiene la cédula DOCUMENTO y el perfil Administrador. Si hay usuarios y no
 * es exactamente uno, la migración falla con un mensaje claro y no marca nada (en PostgreSQL se deshace todo,
 * también la columna). Una base sin usuarios (instalación nueva, tests) no marca a nadie: el primer principal
 * se asigna con el comando de traspaso.
 */
return new class extends Migration
{
    /** Cédula de la persona del administrador principal inicial. */
    public const DOCUMENTO = '2044223';

    private const INDICE = 'users_un_solo_administrador_principal';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('administrador_principal')->default(false)->after('estado_id');
        });

        $verdadero = DB::getDriverName() === 'pgsql' ? 'true' : '1';
        DB::statement('CREATE UNIQUE INDEX '.self::INDICE." ON users (administrador_principal) WHERE administrador_principal = {$verdadero}");

        $this->marcar(self::DOCUMENTO);
    }

    /**
     * Marca como principal al único usuario con el perfil Administrador cuya persona tiene esa cédula. Con
     * usuarios en la base y otra cantidad que uno, falla sin marcar nada.
     */
    public function marcar(string $documento): void
    {
        if (! DB::table('users')->exists()) {
            return;
        }

        $candidatos = DB::table('users')
            ->join('personas', 'personas.id', '=', 'users.persona_id')
            ->join('tipos_documento', 'tipos_documento.id', '=', 'personas.tipo_documento_id')
            ->where('tipos_documento.codigo', 'CI')
            ->where('personas.nro_documento', $documento)
            ->whereExists(fn ($query) => $query->from('usuario_perfil')
                ->join('perfiles_acceso', 'perfiles_acceso.id', '=', 'usuario_perfil.perfil_acceso_id')
                ->whereColumn('usuario_perfil.usuario_id', 'users.id')
                ->where('perfiles_acceso.codigo', 'ADMINISTRADOR'))
            ->pluck('users.id');

        if ($candidatos->count() !== 1) {
            throw new RuntimeException(sprintf(
                'Administrador principal: se esperaba exactamente un usuario con el perfil Administrador y la CI %s, y hay %d. No se marcó a nadie.',
                $documento, $candidatos->count()));
        }

        DB::table('users')->where('id', $candidatos->sole())->update(['administrador_principal' => true]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDICE);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('administrador_principal');
        });
    }
};
