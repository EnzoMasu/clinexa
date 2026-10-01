<?php

namespace App\Support;

use App\Models\Persona;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Verificación "al vuelo" de campos únicos (endpoint verificar-unico + resources/js/verificar-unico.js).
 *
 * Solo se pueden consultar los campos registrados acá (nunca una tabla o columna que venga del
 * navegador). Cada uno indica su tabla y columna, el módulo cuyo permiso hace falta (CREAR al
 * dar de alta, EDITAR al editar), los otros campos del formulario que forman parte de la clave
 * ("con", p. ej. el tipo de documento) y el aviso. Es una ayuda: la validación al guardar sigue
 * siendo la que garantiza la unicidad.
 */
final class Unicidad
{
    /** Por debajo de esta cantidad de caracteres no se consulta (el valor todavía no tiene sentido). */
    public const MINIMO = 2;

    public const SUFIJO = ' Corríjalo para poder guardar.';

    public const CAMPOS = [
        'persona.documento' => ['tabla' => 'personas', 'columna' => 'nro_documento', 'con' => ['tipo_documento_id'], 'modulo' => 'PERSONAS',
            'mensaje' => 'Ya hay una persona registrada con ese tipo y número de documento.'],
        // Especial: solo cuenta si la persona tiene usuario (su email se copia a users.email, que es único).
        'persona.email' => ['modulo' => 'PERSONAS', 'mensaje' => 'Ese email ya lo usa otro usuario del sistema.'],
        'profesional.matricula' => ['tabla' => 'profesionales', 'columna' => 'matricula', 'modulo' => 'PROFESIONALES',
            'mensaje' => 'Esta matrícula ya está registrada.'],
        'paciente.nro_ficha' => ['tabla' => 'pacientes', 'columna' => 'nro_ficha', 'modulo' => 'PACIENTES',
            'mensaje' => 'Este número de ficha ya está registrado.'],
        'tipo_documento.codigo' => ['tabla' => 'tipos_documento', 'columna' => 'codigo', 'modulo' => 'TIPOS_DOCUMENTO',
            'mensaje' => 'Ya hay un tipo de documento con este código.'],
        'procedimiento.codigo' => ['tabla' => 'procedimientos', 'columna' => 'codigo', 'modulo' => 'PROCEDIMIENTOS',
            'mensaje' => 'Ya hay un procedimiento con este código.'],
        'cie10.codigo' => ['tabla' => 'catalogo_cie10', 'columna' => 'codigo', 'clave' => 'codigo', 'modulo' => 'CIE10',
            'mensaje' => 'Este código CIE-10 ya está cargado.'],
        'especialidad.nombre' => ['tabla' => 'especialidades', 'columna' => 'nombre', 'modulo' => 'ESPECIALIDADES',
            'mensaje' => 'Ya hay una especialidad con este nombre.'],
        'categoria_gasto.nombre' => ['tabla' => 'categorias_gasto', 'columna' => 'nombre', 'modulo' => 'CATEGORIAS_GASTO',
            'mensaje' => 'Ya hay una categoría de gasto con este nombre.'],
        'perfil_acceso.nombre' => ['tabla' => 'perfiles_acceso', 'columna' => 'nombre', 'modulo' => 'PERFILES_ACCESO',
            'mensaje' => 'Ya hay un perfil de acceso con este nombre.'],
        'sucursal.nombre' => ['tabla' => 'sucursales', 'columna' => 'nombre', 'modulo' => 'SUCURSALES',
            'mensaje' => 'Ya hay una sucursal con este nombre.'],
        'medio_pago.nombre' => ['tabla' => 'medios_pago', 'columna' => 'nombre', 'modulo' => 'MEDIOS_PAGO',
            'mensaje' => 'Ya hay un medio de pago con este nombre.'],
    ];

    /** El usuario puede consultar ese campo: CREAR en su módulo al dar de alta, EDITAR al editar. */
    public static function puedeConsultar(User $usuario, string $campo, bool $editando): bool
    {
        return isset(self::CAMPOS[$campo]) && $usuario->tienePermiso(self::CAMPOS[$campo]['modulo'], $editando ? 'EDITAR' : 'CREAR');
    }

    /**
     * Mensaje si el valor ya existe (sin contar el registro $ignorar, el que se está editando), o
     * null si está libre. Valores vacíos o muy cortos se consideran libres (no se consulta).
     *
     * @param  array<string, mixed>  $con  valores de los otros campos de la clave
     */
    public static function conflicto(string $campo, string $valor, int|string|null $ignorar = null, array $con = []): ?string
    {
        $definicion = self::CAMPOS[$campo];
        $valor = trim($valor);
        if (mb_strlen($valor) < self::MINIMO) {
            return null;
        }

        $existe = $campo === 'persona.email'
            ? self::emailDeUsuarioEnUso($valor, $ignorar)
            : DB::table($definicion['tabla'])
                ->where($definicion['columna'], $valor)
                ->when($ignorar !== null && $ignorar !== '', fn ($query) => $query->where($definicion['clave'] ?? 'id', '!=', $ignorar))
                ->when($definicion['con'] ?? [], function ($query, array $columnas) use ($con) {
                    foreach ($columnas as $columna) {
                        $query->where($columna, $con[$columna] ?? null);
                    }
                })
                ->exists();

        return $existe ? $definicion['mensaje'].self::SUFIJO : null;
    }

    /** Igual que la validación al guardar de Persona: solo importa si la persona ($ignorar) tiene usuario. */
    private static function emailDeUsuarioEnUso(string $email, int|string|null $personaId): bool
    {
        $usuario = $personaId ? Persona::find($personaId)?->usuario : null;

        return $usuario !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && User::where('email', Persona::emailDeUsuario($email))->whereKeyNot($usuario->id)->exists();
    }
}
