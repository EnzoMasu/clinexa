<?php

namespace App\Console\Commands;

use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migración única: liga a una Persona cada usuario que todavía no tiene persona_id.
 *
 * ⚠ LOS DATOS QUE CREA ESTE COMANDO SON DE RELLENO, NO INFORMACIÓN REAL. ⚠
 * Existen solo para no perder los usuarios que se crearon antes de que users dependiera de
 * personas. El número de CI (rango 9.000.000+), el teléfono, la dirección y la fecha de
 * nacimiento son placeholders que un administrador tiene que corregir desde /admin/personas.
 * Solo el email (el mismo del usuario) y el nombre (separado a partir de users.name) son reales.
 *
 * Se puede correr más de una vez: solo procesa usuarios sin persona_id.
 */
class MigrarUsuariosAPersona extends Command
{
    protected $signature = 'clinexa:migrar-usuarios-a-persona';

    protected $description = 'Crea una Persona (con datos de relleno) para cada usuario sin persona_id y los vincula';

    /** Primer número de CI de relleno: fuera del rango de cédulas reales en uso. */
    private const DOCUMENTO_RELLENO_DESDE = 9000000;

    private const SIN_DATOS = 'Sin datos - migración';

    /** Versión corta para telefono, que admite 20 caracteres. */
    private const SIN_DATOS_CORTO = 'Sin datos - migr.';

    public function handle(): int
    {
        $ci = TipoDocumento::where('codigo', 'CI')->first();
        if (! $ci) {
            $this->error('Falta el tipo de documento CI: correr antes php artisan db:seed --class=DatosRealesClinicaSeeder');

            return self::FAILURE;
        }

        $usuarios = User::whereNull('persona_id')->orderBy('id')->get();
        if ($usuarios->isEmpty()) {
            $this->info('No hay usuarios sin persona: nada que migrar.');

            return self::SUCCESS;
        }

        $documento = self::DOCUMENTO_RELLENO_DESDE;
        $vinculados = 0;

        foreach ($usuarios as $usuario) {
            // personas.email admite 100 caracteres (users.email, 255): no se puede recortar un email.
            if (mb_strlen($usuario->email) > 100) {
                $this->error("  {$usuario->email}: el email supera los 100 caracteres de personas.email; se saltea (cargar su Persona a mano).");

                continue;
            }

            // Siguiente número de relleno libre para CI.
            while (Persona::where('tipo_documento_id', $ci->id)->where('nro_documento', (string) $documento)->exists()) {
                $documento++;
            }

            [$apellidos, $nombres] = self::separarNombre((string) $usuario->getRawOriginal('name'));

            DB::transaction(function () use ($usuario, $ci, $documento, $apellidos, $nombres) {
                $persona = Persona::create([
                    'tipo_persona' => 'FISICA',
                    'tipo_documento_id' => $ci->id,
                    'nro_documento' => (string) $documento,   // RELLENO
                    'apellidos' => $apellidos,
                    'nombres' => $nombres,
                    'fecha_nacimiento' => '1990-01-01',        // RELLENO
                    // sexo y estado_civil quedan vacíos (son opcionales): no se inventan datos personales.
                    'sexo' => null,
                    'estado_civil' => null,
                    'email' => $usuario->email,                // real: el mismo del usuario
                    'telefono' => self::SIN_DATOS_CORTO,       // RELLENO
                    'direccion' => self::SIN_DATOS,            // RELLENO
                    'estado' => 'ACTIVO',
                ]);

                $usuario->forceFill(['persona_id' => $persona->id])->save();
            });

            $this->line("  {$usuario->email} → persona CI {$documento}: {$apellidos}, {$nombres}");
            $documento++;
            $vinculados++;
        }

        $this->info("{$vinculados} usuario(s) vinculados. Corregir los datos de relleno (CI, teléfono, dirección, fecha de nacimiento) en /admin/personas.");

        return $vinculados === $usuarios->count() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Separa "Nombre Apellido" en [apellidos, nombres]. Solo cuando la división no es ambigua:
     * 2 palabras (1 + 1) o 4 palabras (2 + 2). Si no, todo va a nombres y apellidos queda "SIN DATO".
     *
     * @return array{0: string, 1: string}
     */
    public static function separarNombre(string $nombreCompleto): array
    {
        $palabras = preg_split('/\s+/', trim($nombreCompleto), -1, PREG_SPLIT_NO_EMPTY);

        $partes = match (count($palabras)) {
            2 => [$palabras[1], $palabras[0]],
            4 => ["{$palabras[2]} {$palabras[3]}", "{$palabras[0]} {$palabras[1]}"],
            0 => ['SIN DATO', 'SIN DATO'],
            default => ['SIN DATO', implode(' ', $palabras)],
        };

        // apellidos y nombres admiten 100 caracteres (users.name, 255).
        return array_map(fn (string $parte) => mb_substr($parte, 0, 100), $partes);
    }
}
