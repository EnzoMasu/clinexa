<?php

namespace Database\Seeders;

use App\Models\Paciente;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\PropietarioEquipo;
use App\Models\Proveedor;
use App\Models\ResponsablePago;
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
 * También les asigna roles de ejemplo (pacientes, proveedor, propietaria de equipo, responsable de
 * pago), igualmente ficticios.
 *
 * Idempotente: actualiza por tipo_documento_id + nro_documento, sin duplicar; cada rol se crea
 * solo si la persona todavía no lo tiene.
 * Requiere el tipo de documento CI (lo crea DatosRealesClinicaSeeder) y el país Paraguay (GeografiaSeeder).
 */
class PersonasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $ci = TipoDocumento::where('codigo', 'CI')->first()
            ?? throw new RuntimeException('Falta el tipo de documento CI: correr antes DatosRealesClinicaSeeder.');

        $paraguay = Pais::idParaguay()
            ?? throw new RuntimeException('Falta el país Paraguay: correr antes GeografiaSeeder.');

        $comunes = [
            'tipo_persona' => 'FISICA',
            'tipo_documento_id' => $ci->id,
            'sexo' => 'F',
            'pais_nacionalidad_id' => $paraguay,
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

        $this->roles($ci);
    }

    /** Roles de ejemplo sobre las personas de arriba (datos ficticios). */
    private function roles(TipoDocumento $ci): void
    {
        $persona = fn (string $documento) => Persona::where('tipo_documento_id', $ci->id)->where('nro_documento', $documento)->sole();

        // Pacientes: el número de ficha lo genera el sistema, como en el alta desde la pantalla.
        foreach (['5234567', '5876543'] as $documento) { // Duarte, Carmen Sofía; Ramírez, Ana Belén
            Paciente::firstOrCreate(['persona_id' => $persona($documento)->id], ['nro_ficha' => Paciente::siguienteNroFicha()]);
        }

        Proveedor::firstOrCreate(['persona_id' => $persona('3456789')->id], [ // González, Marta Elena
            'condiciones_comerciales' => 'Pago a 30 días (dato ficticio de demo)',
        ]);
        PropietarioEquipo::firstOrCreate(['persona_id' => $persona('2987654')->id], ['datos_bancarios' => null]); // Benítez, Rosa Alicia
        ResponsablePago::firstOrCreate(['persona_id' => $persona('4123456')->id], ['limite_credito' => null]); // Insfrán, Laura Beatriz
    }
}
