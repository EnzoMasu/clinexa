<?php

namespace Database\Seeders;

use App\Models\Ciudad;
use App\Models\Departamento;
use App\Models\Pais;
use Illuminate\Database\Seeder;

/**
 * Geografía real básica: Paraguay, sus 17 departamentos más Asunción (Distrito Capital, que no
 * pertenece a ningún departamento), y las ciudades que usa hoy la clínica: Asunción y los 14
 * distritos del departamento de Concepción (fuente: Wikipedia, "Departamento de Concepción
 * (Paraguay)", consultada el 28/09/2026). El resto de las ciudades se carga más adelante.
 *
 * Idempotente: busca por nombre (dentro de su país/departamento) y no duplica.
 */
class GeografiaSeeder extends Seeder
{
    public const DEPARTAMENTOS = [
        'Concepción', 'San Pedro', 'Cordillera', 'Guairá', 'Caaguazú', 'Caazapá', 'Itapúa', 'Misiones',
        'Paraguarí', 'Alto Paraná', 'Central', 'Ñeembucú', 'Amambay', 'Canindeyú', 'Presidente Hayes',
        'Boquerón', 'Alto Paraguay',
    ];

    public const DISTRITO_CAPITAL = 'Asunción (Distrito Capital)';

    public const CIUDADES = [
        self::DISTRITO_CAPITAL => ['Asunción'],
        'Concepción' => [
            'Concepción', 'Arroyito', 'Azotey', 'Belén', 'Horqueta', 'Itacuá', 'Loreto', 'Paso Barreto',
            'Paso Horqueta', 'San Alfredo', 'San Carlos del Apa', 'San Lázaro', 'Sargento José Félix López', 'Yby Yaú',
        ],
    ];

    public function run(): void
    {
        $paraguay = Pais::firstOrCreate(['nombre' => 'Paraguay']);

        foreach ([...self::DEPARTAMENTOS, self::DISTRITO_CAPITAL] as $nombre) {
            Departamento::firstOrCreate(['pais_id' => $paraguay->id, 'nombre' => $nombre]);
        }

        foreach (self::CIUDADES as $departamento => $ciudades) {
            $departamentoId = Departamento::where('pais_id', $paraguay->id)->where('nombre', $departamento)->value('id');
            foreach ($ciudades as $ciudad) {
                Ciudad::firstOrCreate(['departamento_id' => $departamentoId, 'nombre' => $ciudad]);
            }
        }
    }
}
