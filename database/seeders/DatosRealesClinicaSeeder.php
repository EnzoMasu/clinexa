<?php

namespace Database\Seeders;

use App\Models\Ciudad;
use App\Models\Especialidad;
use App\Models\ModuloSistema;
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
            // La clínica está en la ciudad de Concepción (requiere GeografiaSeeder antes).
            'ciudad_id' => Ciudad::whereHas('departamento', fn ($query) => $query->where('nombre', 'Concepción'))
                ->where('nombre', 'Concepción')->value('id'),
        ]);

        // El pasaporte se había cargado a mano con código "PAS": se renombra (mismo registro).
        if (! TipoDocumento::where('codigo', 'PASAPORTE')->exists()) {
            TipoDocumento::where('codigo', 'PAS')->update(['codigo' => 'PASAPORTE']);
        }

        $tiposDocumento = [
            'CI' => 'Cédula de identidad',
            'PASAPORTE' => 'Pasaporte',
            'RUC' => 'Registro Único del Contribuyente',
            'DNI' => 'Documento Nacional de Identidad',
        ];
        foreach ($tiposDocumento as $codigo => $nombre) {
            TipoDocumento::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        }

        // Tipos de documento que acepta el módulo Personas; CI es el predeterminado.
        // Requiere que existan los módulos (ModuloSistemaSeeder corre antes en DatabaseSeeder).
        ModuloSistema::where('codigo', 'PERSONAS')->first()
            ?->configurarTiposDocumento(['CI', 'PASAPORTE', 'RUC', 'DNI']);

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
            ]);
        }
    }
}
