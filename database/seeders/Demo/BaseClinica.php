<?php

namespace Database\Seeders\Demo;

use App\Models\Ciudad;
use App\Models\Consultorio;
use App\Models\Especialidad;
use App\Models\Procedimiento;
use App\Models\Sucursal;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Database\Seeder;

/**
 * Base de la clínica: datos REALES (no de demo), por eso también sirve en producción:
 *   php artisan db:seed --class='Database\Seeders\Demo\BaseClinica' --force
 * Sucursal, especialidad, consultorios 101 y 102, medios de pago y procedimientos.
 *
 * Idempotente y SOLO ADITIVA: busca por la clave natural (nombre de la sucursal, código del procedimiento) y crea
 * lo que falta; lo que ya existe no se toca (si se renombró desde el panel, queda como está). Requiere la
 * geografía y los estados (migraciones y GeografiaSeeder).
 */
class BaseClinica extends Seeder
{
    /** codigo => [nombre, tipo, minutos]. */
    public const PROCEDIMIENTOS = [
        'CONS-001' => ['Consulta', 'CONSULTA', 20],
        'ECO-ABD' => ['Ecografía Abdominal', 'ESTUDIO', 30],
        'ECO-TV' => ['Ecografía Transvaginal', 'ESTUDIO', 30],
        'ECO-OBST' => ['Ecografía Obstétrica', 'ESTUDIO', 30],
        'ECO-OBST-DOP' => ['Ecografía Obstétrica + Doppler', 'ESTUDIO', 40],
        'PAP-COLPO' => ['Pap + Colposcopía', 'ESTUDIO', 30],
        'ECO-CROMO' => ['Eco Cromosómica', 'ESTUDIO', 90],
        'ECO-MORFO' => ['Ecografía Morfológica', 'ESTUDIO', 120],
    ];

    public const CONSULTORIOS = ['101', '102'];

    /** Lo que creó esta corrida, por nombre. */
    public array $creados = [];

    public function run(): void
    {
        $sucursal = Sucursal::firstOrCreate(['nombre' => 'Plenitud Mujer'], [
            'direccion' => 'Iturbe e/ Pte. Franco y Mcal Estigarribia, Concepción - Paraguay',
            'telefono' => '0975282556',
            'ciudad_id' => Ciudad::whereHas('departamento', fn ($query) => $query->where('nombre', 'Concepción'))
                ->where('nombre', 'Concepción')->value('id'),
        ]);
        $this->anotar('sucursal', $sucursal);

        $this->anotar('especialidad', Especialidad::firstOrCreate(['nombre' => 'Ginecología y Obstetricia']));

        foreach (self::CONSULTORIOS as $nombre) {
            $this->anotar('consultorios', Consultorio::firstOrCreate(['sucursal_id' => $sucursal->id, 'nombre' => $nombre]));
        }

        $this->call(MediosPagoSeeder::class);

        foreach (self::PROCEDIMIENTOS as $codigo => [$nombre, $tipo, $minutos]) {
            $this->anotar('procedimientos', Procedimiento::firstOrCreate(['codigo' => $codigo], [
                'nombre' => $nombre,
                'tipo' => $tipo,
                'duracion_estimada_minutos' => $minutos,
            ]));
        }
    }

    private function anotar(string $que, $modelo): void
    {
        if ($modelo->wasRecentlyCreated) {
            $this->creados[$que] = ($this->creados[$que] ?? 0) + 1;
        }
    }
}
