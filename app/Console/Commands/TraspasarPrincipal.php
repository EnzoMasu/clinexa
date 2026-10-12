<?php

namespace App\Console\Commands;

use App\Exceptions\AccionRechazada;
use App\Models\Persona;
use App\Models\User;
use App\Support\AdministradorPrincipal;
use Illuminate\Console\Command;

/**
 * La única vía para mover la marca de administrador principal (users.administrador_principal): desde la
 * consola, con confirmación explícita (hay que escribir el correo del nuevo principal). Ver docs/perfiles.md.
 */
class TraspasarPrincipal extends Command
{
    protected $signature = 'clinexa:traspasar-principal
        {email : Correo del usuario que pasa a ser el administrador principal}';

    protected $description = 'Traspasa la marca de administrador principal a otro usuario con el perfil Administrador (pide confirmación)';

    public function handle(): int
    {
        $email = Persona::emailDeUsuario($this->argument('email'));
        $nuevo = User::with('persona')->where('email', $email)->first();
        if (! $nuevo) {
            $this->error("No hay ningún usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $actual = AdministradorPrincipal::actual();
        $this->line('Administrador principal actual: '.($actual ? "usuario #{$actual->id} ({$actual->email})" : 'ninguno').'.');
        $this->line("Nuevo administrador principal: usuario #{$nuevo->id} ({$nuevo->email}), {$nuevo->persona?->nombre_completo}.");
        $this->warn('El administrador principal no se puede modificar desde la aplicación; solo con este comando.');

        $confirmacion = $this->ask('Para confirmar, escriba el correo del nuevo administrador principal');
        if ($confirmacion === null || Persona::emailDeUsuario($confirmacion) !== $email) {
            $this->error('El correo no coincide: no se traspasó nada.');

            return self::FAILURE;
        }

        try {
            $anterior = AdministradorPrincipal::traspasar($nuevo);
        } catch (AccionRechazada $e) {
            $this->error($e->getMessage().' No se traspasó nada.');

            return self::FAILURE;
        }

        $this->info("Listo: el usuario #{$nuevo->id} es el administrador principal".($anterior ? " (antes, el usuario #{$anterior->id})." : '.'));

        return self::SUCCESS;
    }
}
