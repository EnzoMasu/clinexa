<?php

use App\Models\ModuloSistema;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Proveedor;
use App\Models\ResponsablePago;
use App\Models\TipoDocumento;
use Database\Seeders\DatosRealesClinicaSeeder;
use Database\Seeders\ModuloSistemaSeeder;

test('crea los tipos de documento y los habilita para personas, con CI predeterminado', function () {
    $this->seed([ModuloSistemaSeeder::class, DatosRealesClinicaSeeder::class]);

    expect(TipoDocumento::pluck('codigo')->sort()->values()->all())->toBe(['CI', 'DNI', 'PASAPORTE', 'RUC']);

    $personas = ModuloSistema::where('codigo', 'PERSONAS')->sole()->tiposDocumento;
    expect($personas->pluck('codigo')->sort()->values()->all())->toBe(['CI', 'DNI', 'PASAPORTE', 'RUC'])
        ->and($personas->where('pivot.es_predeterminado', true)->pluck('codigo')->all())->toBe(['CI'])
        ->and(TipoDocumento::predeterminadoPara('PERSONAS'))->toBe(TipoDocumento::where('codigo', 'CI')->value('id'));
});

test('renombra el pasaporte cargado como PAS a PASAPORTE, conservando el registro', function () {
    $pas = TipoDocumento::create(['codigo' => 'PAS', 'nombre' => 'Pasaporte']);

    $this->seed([ModuloSistemaSeeder::class, DatosRealesClinicaSeeder::class]);

    expect($pas->fresh()->codigo)->toBe('PASAPORTE')
        ->and(TipoDocumento::where('codigo', 'PAS')->exists())->toBeFalse()
        ->and(TipoDocumento::where('codigo', 'PASAPORTE')->count())->toBe(1);
});

test('se puede volver a correr sin duplicar tipos ni habilitaciones', function () {
    $this->seed([ModuloSistemaSeeder::class, DatosRealesClinicaSeeder::class]);
    $this->seed(DatosRealesClinicaSeeder::class);

    expect(TipoDocumento::count())->toBe(4)
        ->and(DB::table('tipo_documento_modulo')->count())->toBe(4);
});

test('carga las personas reales con sus roles', function () {
    $this->seed([ModuloSistemaSeeder::class, DatosRealesClinicaSeeder::class]);
    $ruc = TipoDocumento::where('codigo', 'RUC')->value('id');

    $luigi = Persona::where('tipo_documento_id', $ruc)->where('nro_documento', '4441089-1')->sole();
    expect($luigi)
        ->tipo_persona->toBe('FISICA')
        ->nombre_completo->toBe('Masuzzo Zorrilla, Luigi Armando')
        ->email->toBe('')
        ->telefono->toBe('0975282556')
        ->direccion->toBe('Iturbe e/ Pte. Franco y Mcal Estigarribia, Concepción - Paraguay')
        ->fecha_nacimiento->toBeNull()
        ->estado->codigo->toBe('ACTIVO');

    $profesional = Profesional::where('persona_id', $luigi->id)->sole();
    expect($profesional->matricula)->toBe('15523')
        ->and($profesional->especialidades->pluck('nombre')->all())->toBe(['Ginecología y Obstetricia'])
        ->and($profesional->especialidades->first()->pivot->nro_matricula_especialidad)->toBeNull()
        ->and($profesional->especialidades->first()->pivot->fecha_desde)->not->toBeNull()
        // Era PropietarioEquipo: ahora proveedor de "Equipos médicos", sin datos bancarios.
        ->and(Proveedor::where('persona_id', $luigi->id)->sole()->datos_bancarios)->toBeNull()
        ->and(Proveedor::where('persona_id', $luigi->id)->sole()->categorias->pluck('nombre')->all())->toBe(['Equipos médicos']);

    $clara = Persona::where('tipo_documento_id', $ruc)->where('nro_documento', '421964-3')->sole();
    expect($clara->nombre_completo)->toBe('Zorrilla de Masuzzo, Clara Daniela')
        ->and(ResponsablePago::where('persona_id', $clara->id)->sole()->limite_credito)->toBeNull();
});

test('volver a correrlo no duplica personas ni roles y no pisa lo completado después', function () {
    $this->seed([ModuloSistemaSeeder::class, DatosRealesClinicaSeeder::class]);

    // Datos que se completan después desde la pantalla.
    $luigi = Persona::where('nro_documento', '4441089-1')->sole();
    $luigi->update(['email' => 'luigi@example.com', 'fecha_nacimiento' => '1980-01-01']);
    Proveedor::where('persona_id', $luigi->id)->sole()->update(['datos_bancarios' => 'Banco X - cta 123']);

    $this->seed(DatosRealesClinicaSeeder::class);

    expect(Persona::whereIn('nro_documento', ['4441089-1', '421964-3'])->count())->toBe(2)
        ->and(Profesional::count())->toBe(1)
        ->and(Proveedor::count())->toBe(1)
        ->and(DB::table('proveedor_categoria')->count())->toBe(1)
        ->and(ResponsablePago::count())->toBe(1)
        ->and(DB::table('profesional_especialidad')->count())->toBe(1)
        ->and($luigi->fresh()->email)->toBe('luigi@example.com')
        ->and(Proveedor::sole()->datos_bancarios)->toBe('Banco X - cta 123');
});

test('la habilitación de tipos es solo aditiva: no quita lo habilitado a mano ni cambia el predeterminado', function () {
    $this->seed([ModuloSistemaSeeder::class, DatosRealesClinicaSeeder::class]);
    $personas = ModuloSistema::where('codigo', 'PERSONAS')->sole();

    // Desde la pantalla: se habilita "Diplomatic" y se lo marca como predeterminado.
    $diplomatic = TipoDocumento::create(['codigo' => 'Diplomatic', 'nombre' => 'Pasaporte diplomático']);
    DB::table('tipo_documento_modulo')->where('modulo_sistema_id', $personas->id)->update(['es_predeterminado' => false]);
    $personas->tiposDocumento()->attach($diplomatic->id, ['es_predeterminado' => true]);

    $this->seed(DatosRealesClinicaSeeder::class);

    $habilitados = $personas->tiposDocumento()->get();
    expect($habilitados->pluck('codigo')->sort()->values()->all())->toBe(['CI', 'DNI', 'Diplomatic', 'PASAPORTE', 'RUC'])
        ->and($habilitados->where('pivot.es_predeterminado', true)->pluck('codigo')->all())->toBe(['Diplomatic'])
        ->and(TipoDocumento::predeterminadoPara('PERSONAS'))->toBe($diplomatic->id);
});

test('fija CI como predeterminado solo si Personas no tenía ninguno', function () {
    $this->seed([ModuloSistemaSeeder::class, DatosRealesClinicaSeeder::class]);
    $personas = ModuloSistema::where('codigo', 'PERSONAS')->sole();
    DB::table('tipo_documento_modulo')->where('modulo_sistema_id', $personas->id)->update(['es_predeterminado' => false]);

    $this->seed(DatosRealesClinicaSeeder::class);

    expect(TipoDocumento::predeterminadoPara('PERSONAS'))->toBe(TipoDocumento::where('codigo', 'CI')->value('id'))
        ->and($personas->tiposDocumento()->wherePivot('es_predeterminado', true)->count())->toBe(1);
});
