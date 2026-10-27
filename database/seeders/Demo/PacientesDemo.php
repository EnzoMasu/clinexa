<?php

namespace Database\Seeders\Demo;

use App\Models\Ciudad;
use App\Models\Paciente;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\TipoDocumento;
use RuntimeException;

/**
 * 12 pacientes inventadas, adultas, con ficha (el número lo genera el sistema, como en el alta del panel).
 * CI 9000012 en adelante (después de los 11 usuarios), correo paciente01@prueba.test, teléfonos y direcciones
 * variadas, con ciudades de la geografía ya cargada. Las carga Recepción, como en la clínica.
 */
class PacientesDemo extends EtapaDemo
{
    public const CANTIDAD_USUARIOS = 11;

    /** [apellidos, nombres, nacimiento, estado civil, teléfono, dirección, departamento, ciudad] */
    public const PACIENTES = [
        ['Alvarenga Núñez', 'Gloria Estela', '1991-02-11', 'CASADO', '0982 700 001', 'Calle Prueba 201 c/ Ejemplo', 'Concepción', 'Concepción'],
        ['Barrios Colmán', 'Liz Marlene', '1998-06-23', 'SOLTERO', '0983 700 002', 'Barrio San Blas, Casa 12', 'Concepción', 'Horqueta'],
        ['Cabrera Franco', 'Rocío Abigail', '1986-09-04', 'UNIDO', '0984 700 003', 'Ruta 5 km 3, Compañía Ejemplo', 'Concepción', 'Belén'],
        ['Dávalos Pereira', 'Mirtha Graciela', '1974-12-19', 'CASADO', '0985 700 004', 'Avenida Prueba 45', 'Amambay', 'Pedro Juan Caballero'],
        ['Escobar Giménez', 'Tania Soledad', '2001-04-30', 'SOLTERO', '0986 700 005', 'Barrio Centro, Manzana 7', 'Central', 'Luque'],
        ['Fernández Ocampos', 'Lourdes Beatriz', '1993-08-15', 'CASADO', '0971 700 006', 'Calle Inventada 88', 'Central', 'San Lorenzo'],
        ['Godoy Ramírez', 'Elva Cristina', '1967-01-27', 'VIUDO', '0972 700 007', 'Barrio Ejemplo, Casa 3', 'Cordillera', 'Caacupé'],
        ['Insaurralde Paiva', 'Karina Noelia', '1989-10-09', 'SEPARADO', '0973 700 008', 'Avenida Ficticia 150', 'Alto Paraná', 'Ciudad del Este'],
        ['Jara Villalba', 'Dahiana Yesica', '1996-05-21', 'SOLTERO', '0974 700 009', 'Calle Prueba 300', 'Itapúa', 'Encarnación'],
        ['Lugo Benegas', 'Viviana Raquel', '1979-03-06', 'DIVORCIADO', '0975 700 010', 'Compañía Imaginaria s/n', 'Concepción', 'Yby Yaú'],
        ['Maidana Torales', 'Nancy Beatriz', '1984-11-12', 'CASADO', '0976 700 011', 'Barrio Inventado, Casa 21', 'Concepción', 'Loreto'],
        ['Narváez Sosa', 'Carmen Liliana', '2003-07-28', 'SOLTERO', '0961 700 012', 'Calle Ejemplo 77', 'Asunción (Distrito Capital)', 'Asunción'],
    ];

    public function run(): void
    {
        $ci = TipoDocumento::where('codigo', 'CI')->first() ?? throw new RuntimeException('Falta el tipo de documento CI.');
        $paraguay = Pais::idParaguay() ?? throw new RuntimeException('Falta el país Paraguay.');

        $this->como($this->correo('recepcion'), function () use ($ci, $paraguay) {
            foreach (self::PACIENTES as $i => [$apellidos, $nombres, $nacimiento, $estadoCivil, $telefono, $direccion, $departamento, $ciudad]) {
                $ciudadId = Ciudad::whereHas('departamento', fn ($query) => $query->where('nombre', $departamento))->where('nombre', $ciudad)->value('id');
                if (! $ciudadId) {
                    $this->aviso("Sin ciudad {$ciudad} ({$departamento}) en la geografía: la paciente {$apellidos} queda sin ciudad.");
                }

                $persona = Persona::firstOrCreate(['tipo_documento_id' => $ci->id, 'nro_documento' => (string) (self::CI_INICIAL + self::CANTIDAD_USUARIOS + $i)], [
                    'tipo_persona' => 'FISICA', 'apellidos' => $apellidos, 'nombres' => $nombres, 'sexo' => 'F',
                    'fecha_nacimiento' => $nacimiento, 'estado_civil' => $estadoCivil, 'pais_nacionalidad_id' => $paraguay,
                    'email' => $this->correo(sprintf('paciente%02d', $i + 1)), 'telefono' => $telefono, 'direccion' => $direccion,
                    'ciudad_id' => $ciudadId,
                ]);
                $persona->wasRecentlyCreated && $this->contar('personas');

                $paciente = Paciente::firstOrCreate(['persona_id' => $persona->id], ['nro_ficha' => Paciente::siguienteNroFicha()]);
                $paciente->wasRecentlyCreated && $this->contar('pacientes');
            }
        });
    }
}
