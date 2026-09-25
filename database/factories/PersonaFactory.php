<?php

namespace Database\Factories;

use App\Models\Persona;
use App\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Personas físicas ficticias para los tests.
 *
 * @extends Factory<Persona>
 */
class PersonaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tipo_persona' => 'FISICA',
            'tipo_documento_id' => fn () => TipoDocumento::firstOrCreate(
                ['codigo' => 'CI'],
                ['nombre' => 'Cédula de identidad', 'aplica_a' => 'FISICA'],
            )->id,
            'nro_documento' => (string) fake()->unique()->numberBetween(1000000, 8999999),
            'apellidos' => fake()->lastName(),
            'nombres' => fake()->firstName(),
            'fecha_nacimiento' => fake()->date(max: '-18 years'),
            'email' => fake()->unique()->safeEmail(),
            'telefono' => '0981 000 000',
            'direccion' => 'Dirección de prueba',
            'estado' => 'ACTIVO',
        ];
    }

    public function inactiva(): static
    {
        return $this->state(['estado' => 'INACTIVO']);
    }
}
