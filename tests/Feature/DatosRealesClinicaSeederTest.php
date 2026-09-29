<?php

use App\Models\ModuloSistema;
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
