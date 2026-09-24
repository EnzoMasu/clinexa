<?php

namespace App\Console\Commands;

use App\Models\PerfilAcceso;
use App\Models\User;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Como no hay registro público, el primer usuario se crea desde consola. Sigue el mismo
 * flujo que el panel: contraseña aleatoria + email para que el usuario defina la suya.
 */
class CrearAdmin extends Command
{
    protected $signature = 'clinexa:crear-admin {email} {--nombre=Administrador : Nombre del usuario}';

    protected $description = 'Crea un usuario con el perfil Administrador (todos los permisos) y le envía el link para definir su contraseña';

    public function handle(): int
    {
        $email = Str::lower($this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("\"{$email}\" no es un email válido.");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Ya existe un usuario con el email {$email}.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($email) {
            $this->callSilently('db:seed', ['--class' => ModuloSistemaSeeder::class, '--force' => true]);
            $perfil = PerfilAcceso::asegurarAdministrador();

            User::create([
                'name' => $this->option('nombre'),
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

        $this->info("Administrador {$email} creado con el perfil Administrador.");
        $this->line(config('mail.default') === 'log'
            ? 'MAIL_MAILER=log: el link para definir la contraseña está al final de storage/logs/laravel.log'
            : "Se envió el link para definir la contraseña a {$email}.");

        return self::SUCCESS;
    }
}
