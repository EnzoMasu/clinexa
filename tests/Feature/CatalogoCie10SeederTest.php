<?php

use App\Models\CatalogoCIE10;
use Database\Seeders\CatalogoCie10Seeder;

test('importa los 12.436 códigos finales con punto y capítulo', function () {
    $this->seed(CatalogoCie10Seeder::class);

    expect(CatalogoCIE10::count())->toBe(12436)
        ->and(CatalogoCIE10::find('O14.1'))
            ->descripcion->toBe('Preeclampsia severa')
            ->capitulo->toBe('Embarazo, parto y puerperio')
        ->and(CatalogoCIE10::find('F99')->capitulo)->toBe('Trastornos mentales y del comportamiento')
        ->and(CatalogoCIE10::find('D50.0')->capitulo)->toBe('Enfermedades de la sangre, órganos hematopoyéticos y trastornos de la inmunidad')
        // Sin espacios de más (K38.1 los tenía en la fuente).
        ->and(CatalogoCIE10::find('K38.1')->descripcion)->toBe('Concreciones apendiculares')
        // Capítulos, bloques y categorías con subcategorías no se importan.
        ->and(CatalogoCIE10::find('O14'))->toBeNull()
        ->and(CatalogoCIE10::where('codigo', 'like', '%-%')->count())->toBe(0);
});

test('se puede volver a correr sin duplicar y actualiza lo que cambió', function () {
    $this->seed(CatalogoCie10Seeder::class);
    CatalogoCIE10::find('N76.0')->update(['descripcion' => 'editado a mano']);

    $this->seed(CatalogoCie10Seeder::class);

    expect(CatalogoCIE10::count())->toBe(12436)
        ->and(CatalogoCIE10::find('N76.0')->descripcion)->toBe('Vaginitis aguda');
});
