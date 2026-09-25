<?php

namespace App\Console\Commands;

use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Como no hay registro público, el primer usuario se crea desde consola. Cada usuario es una
 * Persona: si ya existe una con ese CI se usa; si no, se crea con los datos que se pasen por
 * opciones o que el comando pregunte. Después sigue el mismo flujo que el panel: contraseña
 * aleatoria + email para que el usuario defina la suya.
 */
class CrearAdmin extends Command
{
    protected $signature = 'clinexa:crear-admin
        {email : Email del administrador (el de su persona)}
        {--documento= : Número de CI}
        {--apellidos=}
        {--nombres=}
        {--fecha-nacimiento= : AAAA-MM-DD}
        {--telefono=}
        {--direccion=}';

    protected $description = 'Crea un usuario con el perfil Administrador (todos los permisos), ligado a su Persona, y le envía el link para definir su contraseña';

    public function handle(): int
    {
        $email = Persona::emailDeUsuario($this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
            $this->error("\"{$email}\" no es un email válido (máximo 100 caracteres).");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Ya existe un usuario con el email {$email}.");

            return self::FAILURE;
        }

        // CI es un dato real del catálogo; en una base vacía todavía no existe.
        $ci = TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula de identidad', 'aplica_a' => 'FISICA']);

        $documento = trim((string) ($this->option('documento') ?? $this->ask('Número de CI')));
        $persona = Persona::where('tipo_documento_id', $ci->id)->where('nro_documento', $documento)->first();
        $datos = null; // datos de la persona nueva (solo si no existe una con ese CI)

        if ($persona) {
            if ($error = $this->errorPersonaExistente($persona, $email)) {
                $this->error($error);

                return self::FAILURE;
            }
            $this->line("Se usa la persona ya cargada con CI {$documento}: {$persona->nombre_completo}.");
        } else {
            $datos = $this->pedirDatosPersona($documento, $email);
            if ($datos === null) {
                return self::FAILURE;
            }
        }

        DB::transaction(function () use (&$persona, $datos, $ci, $email) {
            $this->callSilently('db:seed', ['--class' => ModuloSistemaSeeder::class, '--force' => true]);
            $perfil = PerfilAcceso::asegurarAdministrador();

            $persona ??= Persona::create([...$datos, 'tipo_persona' => 'FISICA', 'tipo_documento_id' => $ci->id, 'estado' => 'ACTIVO']);

            User::create([
                'persona_id' => $persona->id,
                'email' => $email,
                'password' => Str::password(32),
                'perfil_acceso_id' => $perfil->id,
            ]);
        });

        $status = User::where('email', $email)->sole()->enviarLinkContrasena();

        if ($status !== Password::RESET_LINK_SENT) {
            $this->warn('Usuario creado, pero no se pudo enviar el email: '.__($status));

            return self::FAILURE;
        }

        $this->info("Administrador {$persona->nombre_completo} <{$email}> creado con el perfil Administrador.");
        $this->line(config('mail.default') === 'log'
            ? 'MAIL_MAILER=log: el link para definir la contraseña está al final de storage/logs/laravel.log'
            : "Se envió el link para definir la contraseña a {$email}.");

        return self::SUCCESS;
    }

    private function errorPersonaExistente(Persona $persona, string $email): ?string
    {
        return match (true) {
            $persona->tipo_persona !== 'FISICA' => 'La persona con ese documento no es una persona física.',
            $persona->estado !== 'ACTIVO' => 'La persona con ese documento está inactiva.',
            $persona->usuario()->exists() => 'La persona con ese documento ya tiene un usuario.',
            Persona::emailDeUsuario($persona->email) !== $email => "La persona con ese documento tiene otro email ({$persona->email}). Use ese email o corríjalo en la persona.",
            default => null,
        };
    }

    /**
     * Datos de la persona nueva: de las opciones o, si faltan, preguntando. Null si no son válidos.
     */
    private function pedirDatosPersona(string $documento, string $email): ?array
    {
        $datos = [
            'nro_documento' => $documento,
            'apellidos' => $this->option('apellidos') ?? $this->ask('Apellidos'),
            'nombres' => $this->option('nombres') ?? $this->ask('Nombres'),
            'fecha_nacimiento' => $this->option('fecha-nacimiento') ?? $this->ask('Fecha de nacimiento (AAAA-MM-DD)'),
            'telefono' => $this->option('telefono') ?? $this->ask('Teléfono'),
            'direccion' => $this->option('direccion') ?? $this->ask('Dirección'),
            'email' => $email,
        ];

        // Las mismas reglas que el formulario de Personas para una persona física.
        $validador = Validator::make($datos, [
            'nro_documento' => ['required', 'string', 'max:20'],
            'apellidos' => ['required', 'string', 'max:100'],
            'nombres' => ['required', 'string', 'max:100'],
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'telefono' => ['required', 'string', 'max:20'],
            'direccion' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:100'],
        ], [], ['nro_documento' => 'número de CI', 'fecha_nacimiento' => 'fecha de nacimiento']);

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $error) {
                $this->error($error);
            }

            return null;
        }

        return array_map(fn ($valor) => is_string($valor) ? trim($valor) : $valor, $validador->validated());
    }
}
