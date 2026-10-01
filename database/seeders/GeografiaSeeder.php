<?php

namespace Database\Seeders;

use App\Models\Ciudad;
use App\Models\Departamento;
use App\Models\Pais;
use Illuminate\Database\Seeder;

/**
 * Los 249 países de ISO 3166-1 (database/data/paises.php), para elegir la nacionalidad de una
 * persona. Solo Paraguay tiene departamentos y ciudades cargados.
 *
 * Paraguay: sus 17 departamentos más Asunción (Distrito Capital, que no pertenece a ningún
 * departamento) y sus 263 distritos, que son las ciudades (database/data/distritos-paraguay.php;
 * fuente: INE, Cartografía Censal 2022).
 *
 * Idempotente y solo aditivo: busca por nombre (dentro de su país/departamento), agrega lo que
 * falte y no modifica ni quita lo existente.
 */
class GeografiaSeeder extends Seeder
{
    public const DEPARTAMENTOS = [
        'Concepción', 'San Pedro', 'Cordillera', 'Guairá', 'Caaguazú', 'Caazapá', 'Itapúa', 'Misiones',
        'Paraguarí', 'Alto Paraná', 'Central', 'Ñeembucú', 'Amambay', 'Canindeyú', 'Presidente Hayes',
        'Boquerón', 'Alto Paraguay',
    ];

    public const DISTRITO_CAPITAL = 'Asunción (Distrito Capital)';

    public function run(): void
    {
        foreach (require database_path('data/paises.php') as $nombre) {
            Pais::firstOrCreate(['nombre' => $nombre]);
        }

        $paraguay = Pais::where('nombre', 'Paraguay')->sole();

        foreach ([...self::DEPARTAMENTOS, self::DISTRITO_CAPITAL] as $nombre) {
            Departamento::firstOrCreate(['pais_id' => $paraguay->id, 'nombre' => $nombre]);
        }

        foreach (require database_path('data/distritos-paraguay.php') as $departamento => $ciudades) {
            $departamentoId = Departamento::where('pais_id', $paraguay->id)->where('nombre', $departamento)->sole()->id;
            foreach ($ciudades as $ciudad) {
                Ciudad::firstOrCreate(['departamento_id' => $departamentoId, 'nombre' => $ciudad]);
            }
        }
    }
}
