<?php

namespace Database\Factories;

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\User;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            // Cada usuario es una persona: se crea con el mismo email (salvo que el test pase persona_id).
            'persona_id' => fn (array $atributos) => Persona::factory()->create(['email' => $atributos['email']])->id,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Usuario cuya persona tiene los datos indicados, como ['apellidos' => 'Ruiz', 'nombres' => 'Liz'].
     */
    public function conPersona(array $datos): static
    {
        return $this->state([
            'persona_id' => fn (array $atributos) => Persona::factory()->create([...$datos, 'email' => $atributos['email']])->id,
        ]);
    }

    /**
     * Todo usuario creado con la fábrica tiene al menos un perfil activo (sin él no puede entrar): por
     * defecto, un perfil de prueba sin permisos. Los estados de abajo lo reemplazan (sync).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $usuario) {
            $usuario->perfiles()->attach(PerfilAcceso::firstOrCreate(['nombre' => 'Perfil de prueba sin permisos'])->id);
        });
    }

    /**
     * Usuario con el perfil Administrador (las 5 acciones en todos los módulos).
     */
    public function administrador(): static
    {
        return $this->conPerfiles(function () {
            (new ModuloSistemaSeeder)->run();

            return [PerfilAcceso::asegurarAdministrador()];
        });
    }

    /**
     * Usuario con un perfil que solo tiene los permisos indicados, como ['PERSONAS' => ['VER']].
     */
    public function conPermisos(array $permisos): static
    {
        return $this->conPerfiles(fn () => [self::perfilConPermisos($permisos)]);
    }

    /** Un perfil de prueba nuevo con solo esos permisos, como ['PERSONAS' => ['VER']]. */
    public static function perfilConPermisos(array $permisos, ?string $nombre = null): PerfilAcceso
    {
        (new ModuloSistemaSeeder)->run();

        $perfil = PerfilAcceso::create(['nombre' => $nombre ?? 'Perfil de prueba '.Str::random(6)]);

        foreach ($permisos as $codigo => $acciones) {
            $modulo = ModuloSistema::where('codigo', $codigo)->sole();
            foreach ($acciones as $accion) {
                $perfil->permisos()->attach(Permiso::firstOrCreate(['modulo_sistema_id' => $modulo->id, 'accion' => $accion]));
            }
        }

        return $perfil;
    }

    /**
     * Usuario con exactamente estos perfiles (en lugar del de prueba sin permisos). Recibe los perfiles, o
     * una función que los devuelve (se llama al crear).
     *
     * @param  array<PerfilAcceso>|\Closure(): array<PerfilAcceso>  $perfiles
     */
    public function conPerfiles(array|\Closure $perfiles): static
    {
        return $this->afterCreating(function (User $usuario) use ($perfiles) {
            $lista = $perfiles instanceof \Closure ? $perfiles() : $perfiles;
            $usuario->perfiles()->sync(collect($lista)->map(fn (PerfilAcceso $perfil) => $perfil->id)->all());
            $usuario->olvidarPermisos();
        });
    }

    /** Usuario sin ningún perfil (no puede entrar). */
    public function sinPerfiles(): static
    {
        return $this->afterCreating(function (User $usuario) {
            $usuario->perfiles()->detach();
            $usuario->olvidarPermisos();
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
