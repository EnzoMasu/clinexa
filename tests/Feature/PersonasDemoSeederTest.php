<?php

use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\ResponsablePago;
use App\Models\TipoDocumento;
use Database\Seeders\DatosRealesClinicaSeeder;
use Database\Seeders\GeografiaSeeder;
use Database\Seeders\PersonasDemoSeeder;

test('carga las 5 personas de ejemplo y se puede volver a correr sin duplicar', function () {
    $this->seed([GeografiaSeeder::class, DatosRealesClinicaSeeder::class, PersonasDemoSeeder::class]);
    $this->seed(PersonasDemoSeeder::class);

    // Las de demo son las de CI (DatosRealesClinicaSeeder carga además personas reales con RUC).
    $demo = Persona::where('tipo_documento_id', TipoDocumento::where('codigo', 'CI')->value('id'));
    expect((clone $demo)->count())->toBe(5)
        ->and((clone $demo)->pluck('nro_documento')->sort()->values()->all())
        ->toBe(['2987654', '3456789', '4123456', '5234567', '5876543'])
        ->and((clone $demo)->where('email', 'not like', '%@example.com')->count())->toBe(0);

    expect(Persona::where('nro_documento', '5234567')->sole())
        ->nombre_completo->toBe('Duarte, Carmen Sofía')
        ->tipo_persona->toBe('FISICA')
        ->sexo->toBe('F')
        ->paisNacionalidad->nombre->toBe('Paraguay')
        ->estado->codigo->toBe('ACTIVO')
        ->and(Persona::where('nro_documento', '5234567')->sole()->fecha_nacimiento->format('Y-m-d'))->toBe('1990-03-15')
        ->and(Persona::where('nro_documento', '5234567')->sole()->tipoDocumento->codigo)->toBe('CI');
});

test('asigna los roles de ejemplo a las personas de demo, sin duplicar al volver a correr', function () {
    $this->seed([GeografiaSeeder::class, DatosRealesClinicaSeeder::class, PersonasDemoSeeder::class]);
    $this->seed(PersonasDemoSeeder::class);

    $nombres = fn (string $modelo) => $modelo::with('persona')->get()->map(fn ($rol) => $rol->persona->nombre_completo)->sort()->values()->all();

    expect($nombres(Paciente::class))->toBe(['Duarte, Carmen Sofía', 'Ramírez, Ana Belén'])
        ->and($nombres(Proveedor::class))->toBe(['Benítez, Rosa Alicia', 'González, Marta Elena', 'Masuzzo Zorrilla, Luigi Armando'])
        ->and($nombres(ResponsablePago::class))->toBe(['Insfrán, Laura Beatriz', 'Zorrilla de Masuzzo, Clara Daniela']);

    // Fichas autogeneradas, distintas; datos ficticios marcados como tales.
    expect(Paciente::pluck('nro_ficha')->unique())->toHaveCount(2)
        ->and(Proveedor::whereHas('persona', fn ($q) => $q->where('nro_documento', '3456789'))->sole()->condiciones_comerciales)->toContain('ficticio')
        ->and(Proveedor::whereNotNull('datos_bancarios')->exists())->toBeFalse()
        // Benítez y Luigi (antes propietarios de equipo) son proveedores de equipos médicos.
        ->and(Proveedor::whereHas('categorias', fn ($q) => $q->where('nombre', 'Equipos médicos'))->with('persona')->get()
            ->map(fn ($proveedor) => $proveedor->persona->apellidos)->sort()->values()->all())->toBe(['Benítez', 'Masuzzo Zorrilla'])
        ->and(ResponsablePago::whereNotNull('limite_credito')->exists())->toBeFalse()
        ->and(Paciente::all()->every->estaActivo())->toBeTrue();
});

test('falla con un mensaje claro si no existe el tipo de documento CI', function () {
    $this->seed(PersonasDemoSeeder::class);
})->throws(RuntimeException::class, 'Falta el tipo de documento CI');
