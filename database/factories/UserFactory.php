<?php

namespace Database\Factories;

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Usuario con el perfil Administrador (las 5 acciones en todos los módulos).
     */
    public function administrador(): static
    {
        return $this->state(function () {
            (new ModuloSistemaSeeder)->run();

            return ['perfil_acceso_id' => PerfilAcceso::asegurarAdministrador()->id];
        });
    }

    /**
     * Usuario con un perfil que solo tiene los permisos indicados, como ['PERSONAS' => ['VER']].
     */
    public function conPermisos(array $permisos): static
    {
        return $this->state(function () use ($permisos) {
            (new ModuloSistemaSeeder)->run();

            $perfil = PerfilAcceso::create(['nombre' => 'Perfil de prueba '.Str::random(6)]);

            foreach ($permisos as $codigo => $acciones) {
                $modulo = ModuloSistema::where('codigo', $codigo)->sole();
                foreach ($acciones as $accion) {
                    $perfil->permisos()->attach(Permiso::firstOrCreate(['modulo_sistema_id' => $modulo->id, 'accion' => $accion]));
                }
            }

            return ['perfil_acceso_id' => $perfil->id];
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
