<?php

namespace Database\Seeders;

use App\Models\CatalogoCIE10;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Catálogo CIE-10 (12.436 códigos finales) desde database/seeders/data/cie10.csv.
 *
 * Origen: https://github.com/verasativa/CIE-10 (cie-10.csv), curado así:
 * - Solo códigos finales: subcategorías (J069) y categorías sin subcategorías (F99).
 *   Quedan afuera capítulos, bloques y categorías que tienen subcategorías.
 * - Código con punto: J069 → J06.9.
 * - capitulo = nombre del capítulo (code_0); "D50-D89" abreviado para que entre en 100 caracteres.
 * - Espacios de más recortados. Las descripciones sin tildes de la fuente se dejaron como están.
 *
 * Idempotente: inserta o actualiza por codigo (upsert), así se puede volver a correr si el
 * archivo cambia. No borra códigos que ya no estén en el archivo.
 */
class CatalogoCie10Seeder extends Seeder
{
    private const ARCHIVO = __DIR__.'/data/cie10.csv';

    public function run(): void
    {
        $archivo = fopen(self::ARCHIVO, 'r') ?: throw new RuntimeException('No se pudo abrir '.self::ARCHIVO);

        $cabecera = fgetcsv($archivo, escape: '');
        if ($cabecera !== ['codigo', 'descripcion', 'capitulo']) {
            throw new RuntimeException('Cabecera inesperada en '.self::ARCHIVO.': '.implode(',', (array) $cabecera));
        }

        $tanda = [];
        while (($fila = fgetcsv($archivo, escape: '')) !== false) {
            $tanda[] = array_combine($cabecera, $fila);

            if (count($tanda) === 1000) {
                CatalogoCIE10::upsert($tanda, ['codigo'], ['descripcion', 'capitulo']);
                $tanda = [];
            }
        }
        fclose($archivo);

        if ($tanda) {
            CatalogoCIE10::upsert($tanda, ['codigo'], ['descripcion', 'capitulo']);
        }
    }
}
