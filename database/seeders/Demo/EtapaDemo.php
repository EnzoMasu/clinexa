<?php

namespace Database\Seeders\Demo;

use App\Models\Paciente;
use App\Models\Profesional;
use App\Models\User;
use App\Support\ContextoAuditoria;
use App\Support\Fecha;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Base de las etapas de datos de DESARROLLO (php artisan clinexa:datos-demo). Cada etapa es idempotente:
 * identifica cada registro por una clave natural estable y solo crea lo que falta; nunca modifica ni borra
 * lo que no sea demo.
 *
 * Los datos demo se reconocen a simple vista: correos @prueba.test, documentos 9000xxx, nombres inventados.
 */
abstract class EtapaDemo extends Seeder
{
    public const DOMINIO = 'prueba.test';

    /** Primer CI demo; las personas demo van correlativas desde acá. */
    public const CI_INICIAL = 9000001;

    /** Marca visible en los turnos demo (también es su clave para no repetirlos). */
    public const MARCA_TURNO = 'Turno de prueba (demo)';

    /** Cuántos registros creó la etapa, por nombre (para el informe del comando). */
    public array $creados = [];

    protected function contar(string $que, int $cantidad = 1): void
    {
        $this->creados[$que] = ($this->creados[$que] ?? 0) + $cantidad;
    }

    protected function aviso(string $mensaje): void
    {
        if ($this->command instanceof Command) {
            $this->command->warn($mensaje);
        }
    }

    /**
     * Corre $accion como el usuario demo de ese correo, con la auditoría encendida: así los cambios quedan en
     * el registro de auditoría con su autor, como si los hiciera esa persona desde el panel. Fuera del panel
     * (consola) la auditoría no registra nada. Al terminar deja todo como estaba.
     */
    protected function como(string $email, \Closure $accion): mixed
    {
        $usuario = User::where('email', $email)->first();
        if (! $usuario) {
            return $accion();
        }

        $contexto = app(ContextoAuditoria::class);
        $anterior = [$contexto->pedidoWeb, Auth::user()];
        $contexto->pedidoWeb = true;
        Auth::setUser($usuario);

        try {
            return $accion();
        } finally {
            $contexto->pedidoWeb = $anterior[0];
            $anterior[1] ? Auth::setUser($anterior[1]) : Auth::forgetUser();
        }
    }

    protected function correo(string $usuario): string
    {
        return $usuario.'@'.self::DOMINIO;
    }

    protected function usuario(string $usuario): User
    {
        return User::where('email', $this->correo($usuario))->first()
            ?? throw new RuntimeException("Falta el usuario {$usuario}: correr antes UsuariosDemo.");
    }

    protected function profesional(string $usuario): Profesional
    {
        return Profesional::whereHas('persona', fn ($query) => $query->where('email', $this->correo($usuario)))->first()
            ?? throw new RuntimeException("Falta el profesional {$usuario}: correr antes UsuariosDemo.");
    }

    /** @return Collection<int, Paciente> las pacientes demo, en el orden de su CI */
    protected function pacientesDemo()
    {
        $pacientes = Paciente::whereHas('persona', fn ($query) => $query->where('email', 'like', 'paciente%@'.self::DOMINIO))->orderBy('id')->get();

        return $pacientes->count() === count(PacientesDemo::PACIENTES) ? $pacientes : throw new RuntimeException('Faltan las pacientes demo: correr antes PacientesDemo.');
    }

    /** Desde cuándo valen disponibilidades, tarifas, acuerdos y convenios demo: hace dos meses, para poder simular días pasados. */
    public static function inicioVigencias(): Carbon
    {
        return Fecha::hoy()->subMonths(2)->startOfMonth();
    }
}
