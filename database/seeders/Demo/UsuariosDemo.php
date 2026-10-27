<?php

namespace Database\Seeders\Demo;

use App\Models\CargoSalud;
use App\Models\Ciudad;
use App\Models\Especialidad;
use App\Models\PerfilAcceso;
use App\Models\PersonalSalud;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Support\PerfilesPredefinidos as Perfiles;
use RuntimeException;

/**
 * Un usuario demo por perfil, cada uno con UN solo perfil. Personas físicas con CI 9000001 en adelante y correo
 * @prueba.test. Médica 1 y Médico 2 tienen además el rol de profesional (matrícula inventada) en Ginecología y
 * Obstetricia; Enfermería, el de personal de salud.
 *
 * La contraseña se pasa con conClave() y se usa solo al CREAR un usuario: a uno que ya existe no se le toca.
 * El orden de la lista es el del CI: no se reordena ni se intercala, para que cada persona conserve el suyo.
 */
class UsuariosDemo extends EtapaDemo
{
    /** correo => [perfil, sexo, apellidos, nombres, nacimiento, estado civil, teléfono, dirección, [rol, datos...]] */
    private const USUARIOS = [
        'gerencia' => [Perfiles::GERENCIA, 'F', 'Cardozo Ortigoza', 'Mariana Beatriz', '1981-03-14', 'CASADO', '0981 900 001', 'Calle Prueba 101, Concepción'],
        'caja1' => [Perfiles::CAJA, 'M', 'Ayala Rojas', 'Julio César', '1990-07-22', 'SOLTERO', '0981 900 002', 'Calle Prueba 102, Concepción'],
        'caja2' => [Perfiles::CAJA, 'F', 'Meza Servín', 'Nilda Raquel', '1988-11-05', 'UNIDO', '0981 900 003', 'Calle Prueba 103, Concepción'],
        'facturacion' => [Perfiles::FACTURACION, 'F', 'Giménez Báez', 'Lorena Patricia', '1985-01-30', 'CASADO', '0981 900 004', 'Calle Prueba 104, Concepción'],
        'compras' => [Perfiles::COMPRAS_TESORERIA, 'M', 'Vera Acosta', 'Ramón Alcides', '1979-09-18', 'CASADO', '0981 900 005', 'Calle Prueba 105, Concepción'],
        'auditor' => [Perfiles::AUDITOR, 'F', 'Fretes Lezcano', 'Silvia Noemí', '1976-05-09', 'DIVORCIADO', '0981 900 006', 'Calle Prueba 106, Concepción'],
        'medica1' => [Perfiles::MEDICO, 'F', 'Benítez Ayala', 'Carolina Beatriz', '1983-12-02', 'CASADO', '0981 900 007', 'Calle Prueba 107, Concepción', ['profesional', '900101', 'E-900101']],
        'medico2' => [Perfiles::MEDICO, 'M', 'Duarte Vera', 'Marcos Daniel', '1980-04-25', 'SOLTERO', '0981 900 008', 'Calle Prueba 108, Concepción', ['profesional', '900102', 'E-900102']],
        'enfermeria' => [Perfiles::ENFERMERIA, 'F', 'Giménez Cabrera', 'Rosa Elena', '1992-08-12', 'SOLTERO', '0981 900 009', 'Calle Prueba 109, Concepción', ['personal', 'ENF-900201']],
        'recepcion' => [Perfiles::RECEPCION, 'F', 'Acosta Lezcano', 'Patricia Noemí', '1994-02-17', 'SOLTERO', '0981 900 010', 'Calle Prueba 110, Concepción'],
        'supervision' => [Perfiles::SUPERVISION_MEDICA, 'M', 'Ríos Samaniego', 'Héctor Fabián', '1972-10-08', 'CASADO', '0981 900 011', 'Calle Prueba 111, Concepción'],
    ];

    private ?string $clave = null;

    public function conClave(string $clave): static
    {
        $this->clave = $clave;

        return $this;
    }

    public function run(): void
    {
        $ci = TipoDocumento::where('codigo', 'CI')->first() ?? throw new RuntimeException('Falta el tipo de documento CI.');
        $paraguay = Pais::idParaguay() ?? throw new RuntimeException('Falta el país Paraguay: correr antes GeografiaSeeder.');
        $ciudad = Ciudad::whereHas('departamento', fn ($query) => $query->where('nombre', 'Concepción'))->where('nombre', 'Concepción')->value('id');
        $ginecologia = Especialidad::where('nombre', 'Ginecología y Obstetricia')->first() ?? throw new RuntimeException('Falta la especialidad: correr antes BaseClinica.');

        $gerencia = $this->correo('gerencia');
        $n = 0;
        foreach (self::USUARIOS as $usuario => $datos) {
            $documento = (string) (self::CI_INICIAL + $n++);
            $email = $this->correo($usuario);
            // El primero (Gerencia) no tiene a nadie que lo cargue; los demás quedan en auditoría a su nombre.
            $this->como($gerencia, fn () => $this->cargar($email, $documento, $datos, $ci->id, $paraguay, $ciudad, $ginecologia));
        }
    }

    private function cargar(string $email, string $documento, array $datos, int $ciId, int $paraguay, ?int $ciudad, Especialidad $ginecologia): void
    {
        [$codigoPerfil, $sexo, $apellidos, $nombres, $nacimiento, $estadoCivil, $telefono, $direccion] = $datos;
        $rol = $datos[8] ?? null;

        $persona = Persona::firstOrCreate(['tipo_documento_id' => $ciId, 'nro_documento' => $documento], [
            'tipo_persona' => 'FISICA', 'apellidos' => $apellidos, 'nombres' => $nombres, 'sexo' => $sexo,
            'fecha_nacimiento' => $nacimiento, 'estado_civil' => $estadoCivil, 'pais_nacionalidad_id' => $paraguay,
            'email' => $email, 'telefono' => $telefono, 'direccion' => $direccion, 'ciudad_id' => $ciudad,
        ]);
        $persona->wasRecentlyCreated && $this->contar('personas');

        if ($rol && $rol[0] === 'profesional') {
            $profesional = Profesional::firstOrCreate(['persona_id' => $persona->id], ['matricula' => $rol[1]]);
            $profesional->wasRecentlyCreated && $this->contar('profesionales');
            if (! $profesional->especialidades()->whereKey($ginecologia->id)->exists()) {
                $profesional->auditarRelacion('especialidades', fn () => $profesional->especialidades()->attach($ginecologia->id, [
                    'fecha_desde' => '2015-01-01', 'nro_matricula_especialidad' => $rol[2],
                ]));
            }
        }
        if ($rol && $rol[0] === 'personal') {
            $cargo = CargoSalud::where('nombre', 'Enfermero/a')->first() ?? throw new RuntimeException('Falta el cargo de salud Enfermero/a.');
            $personal = PersonalSalud::firstOrCreate(['persona_id' => $persona->id], ['cargo_salud_id' => $cargo->id, 'registro_profesional' => $rol[1]]);
            $personal->wasRecentlyCreated && $this->contar('personal de salud');
        }

        if (User::where('email', $email)->exists()) {
            return;
        }
        $perfil = PerfilAcceso::where('codigo', $codigoPerfil)->first() ?? throw new RuntimeException("Falta el perfil {$codigoPerfil}: correr antes PerfilesPredefinidosSeeder.");
        $usuario = User::create(['persona_id' => $persona->id, 'email' => $email, 'password' => $this->clave ?? throw new RuntimeException('Falta la contraseña.')]);
        $usuario->sincronizarAuditado('perfiles', [$perfil->id]);
        $this->contar('usuarios');
    }
}
