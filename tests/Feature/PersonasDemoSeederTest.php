<?php

use App\Models\Persona;
use Database\Seeders\DatosRealesClinicaSeeder;
use Database\Seeders\PersonasDemoSeeder;

test('carga las 5 personas de ejemplo y se puede volver a correr sin duplicar', function () {
    $this->seed([DatosRealesClinicaSeeder::class, PersonasDemoSeeder::class]);
    $this->seed(PersonasDemoSeeder::class);

    expect(Persona::count())->toBe(5)
        ->and(Persona::pluck('nro_documento')->sort()->values()->all())
            ->toBe(['2987654', '3456789', '4123456', '5234567', '5876543'])
        ->and(Persona::where('email', 'not like', '%@example.com')->count())->toBe(0);

    expect(Persona::where('nro_documento', '5234567')->sole())
        ->nombre_completo->toBe('Duarte, Carmen Sofía')
        ->tipo_persona->toBe('FISICA')
        ->sexo->toBe('F')
        ->nacionalidad->toBe('Paraguaya')
        ->estado->toBe('ACTIVO')
        ->and(Persona::where('nro_documento', '5234567')->sole()->fecha_nacimiento->format('Y-m-d'))->toBe('1990-03-15')
        ->and(Persona::where('nro_documento', '5234567')->sole()->tipoDocumento->codigo)->toBe('CI');
});

test('falla con un mensaje claro si no existe el tipo de documento CI', function () {
    $this->seed(PersonasDemoSeeder::class);
})->throws(RuntimeException::class, 'Falta el tipo de documento CI');
