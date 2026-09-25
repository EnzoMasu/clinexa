<?php

namespace Database\Seeders;

use App\Models\Especialidad;
use App\Models\Procedimiento;
use App\Models\Sucursal;
use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;

/**
 * Datos reales de la clínica. Idempotente: busca por el campo que identifica cada registro
 * y lo actualiza si ya existe, así se puede correr varias veces sin duplicar.
 */
class DatosRealesClinicaSeeder extends Seeder
{
    public function run(): void
    {
        // sucursales no tiene columna unique en el diseño: se identifica por nombre.
        Sucursal::updateOrCreate(['nombre' => 'Plenitud Mujer'], [
            'direccion' => 'Iturbe e/ Pte. Franco y Mcal Estigarribia, Concepción - Paraguay',
            'telefono' => '0975282556',
            'estado' => 'ACTIVO',
        ]);

        TipoDocumento::updateOrCreate(['codigo' => 'CI'], [
            'nombre' => 'Cédula de identidad',
            'aplica_a' => 'FISICA',
            'estado' => 'ACTIVO',
        ]);

        Especialidad::updateOrCreate(['nombre' => 'Ginecología y Obstetricia'], [
            'descripcion' => null,
        ]);

        $procedimientos = [
            ['CONS-001', 'Consulta', 'CONSULTA', 20],
            ['ECO-ABD', 'Ecografía Abdominal', 'ESTUDIO', 30],
            ['ECO-TV', 'Ecografía Transvaginal (Eco TV)', 'ESTUDIO', 30],
            ['ECO-OBST', 'Ecografía Obstétrica', 'ESTUDIO', 30],
            ['ECO-OBST-DOP', 'Ecografía Obstétrica + Doppler', 'ESTUDIO', 40],
            ['PAP-COLPO', 'Pap + Colposcopía', 'ESTUDIO', 30],
            ['ECO-CROMO', 'Eco Cromosómica', 'ESTUDIO', 90],
        ];

        foreach ($procedimientos as [$codigo, $nombre, $tipo, $duracion]) {
            Procedimiento::updateOrCreate(['codigo' => $codigo], [
                'nombre' => $nombre,
                'tipo' => $tipo,
                'duracion_estimada_minutos' => $duracion,
                'estado' => 'ACTIVO',
            ]);
        }
    }
}
