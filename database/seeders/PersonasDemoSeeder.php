<?php

namespace Database\Seeders;

use App\Models\Persona;
use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * PERSONAS DE EJEMPLO — TODOS LOS DATOS SON FICTICIOS.
 *
 * Ninguna de estas personas es una profesional ni una paciente real de la clínica: los
 * nombres, números de cédula, fechas de nacimiento, emails, teléfonos y direcciones son
 * inventados para la demo y las pruebas. Los emails usan el dominio reservado example.com
 * (RFC 2606), así que nunca llegan a una casilla real. No se cargan en producción
 * (ver DatabaseSeeder).
 *
 * Idempotente: actualiza por tipo_documento_id + nro_documento, sin duplicar.
 * Requiere el tipo de documento CI (lo crea DatosRealesClinicaSeeder).
 */
class PersonasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $ci = TipoDocumento::where('codigo', 'CI')->first()
            ?? throw new RuntimeException('Falta el tipo de documento CI: correr antes DatosRealesClinicaSeeder.');

        $comunes = [
            'tipo_persona' => 'FISICA',
            'tipo_documento_id' => $ci->id,
            'sexo' => 'F',
            'nacionalidad' => 'Paraguaya',
            'estado' => 'ACTIVO',
        ];

        $personas = [
            // Profesionales (ficticias)
            ['3456789', 'González', 'Marta Elena', '1978-05-12', 'CASADO', 'marta.gonzalez@example.com', '0981 100 001', 'Calle Ficticia 123, Concepción'],
            ['2987654', 'Benítez', 'Rosa Alicia', '1975-09-03', 'CASADO', 'rosa.benitez@example.com', '0981 100 002', 'Calle Ficticia 456, Concepción'],
            ['4123456', 'Insfrán', 'Laura Beatriz', '1983-11-27', 'SOLTERO', 'laura.insfran@example.com', '0981 100 003', 'Calle Ficticia 789, Concepción'],
            // Pacientes (ficticias)
            ['5234567', 'Duarte', 'Carmen Sofía', '1990-03-15', 'CASADO', 'carmen.duarte@example.com', '0981 200 001', 'Barrio Ejemplo 12, Concepción'],
            ['5876543', 'Ramírez', 'Ana Belén', '1985-07-22', 'SOLTERO', 'ana.ramirez@example.com', '0981 200 002', 'Barrio Ejemplo 34, Concepción'],
        ];

        foreach ($personas as [$documento, $apellidos, $nombres, $nacimiento, $estadoCivil, $email, $telefono, $direccion]) {
            Persona::updateOrCreate(
                ['tipo_documento_id' => $ci->id, 'nro_documento' => $documento],
                [
                    ...$comunes,
                    'apellidos' => $apellidos,
                    'nombres' => $nombres,
                    'fecha_nacimiento' => $nacimiento,
                    'estado_civil' => $estadoCivil,
                    'email' => $email,
                    'telefono' => $telefono,
                    'direccion' => $direccion,
                ],
            );
        }
    }
}
