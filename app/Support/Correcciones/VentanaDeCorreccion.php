<?php

namespace App\Support\Correcciones;

use App\Models\BloqueAnamnesis;
use App\Models\Consulta;
use App\Models\DesbloqueoConsulta;
use App\Models\Diagnostico;
use App\Models\ExamenFisico;
use App\Models\Indicacion;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * El ÚNICO permiso para escribir en una consulta cerrada: vale solo dentro de dentro(), ligado a un desbloqueo
 * de esa consulta, y se cierra al terminar (también si hay error). Nada queda abierto entre pedidos. Dentro,
 * ProtegidoPorCierre deja pasar solo las tablas y los campos de PERMITIDOS, de ESA consulta: nunca los signos
 * vitales (ni su autoría ni sus alertas), el triaje, las recetas, el paciente, el profesional, las fechas, el
 * estado ni la autoría de la consulta. Cualquier otro intento sigue dando 409 (ConsultaCerrada).
 */
final class VentanaDeCorreccion
{
    /** Modelo => campos que se pueden escribir (además de consulta_id y las marcas de tiempo, que no se auditan). */
    public const PERMITIDOS = [
        Consulta::class => ['motivo_consulta'],
        BloqueAnamnesis::class => ['tipo_bloque_anamnesis_id', 'contenido', 'orden', 'activo', 'usuario_id', 'modificado_por_id'],
        ExamenFisico::class => ['hallazgos', 'hallazgos_usuario_id'],
        Diagnostico::class => ['codigo_cie10', 'tipo', 'principal', 'descripcion_adicional', 'activo'],
        Indicacion::class => ['tipo_indicacion_id', 'descripcion', 'orden', 'activo'],
    ];

    /** @var array{consulta_id: int, desbloqueo_id: int}|null */
    private static ?array $abierta = null;

    /** Corre $accion con la ventana abierta para este desbloqueo; la cierra siempre al terminar. */
    public static function dentro(DesbloqueoConsulta $desbloqueo, \Closure $accion): mixed
    {
        if (self::$abierta !== null) {
            throw new LogicException('Ya hay una corrección en curso.');
        }
        self::$abierta = ['consulta_id' => (int) $desbloqueo->consulta_id, 'desbloqueo_id' => (int) $desbloqueo->id];
        try {
            return $accion();
        } finally {
            self::$abierta = null;
        }
    }

    public static function activa(): bool
    {
        return self::$abierta !== null;
    }

    /** ¿Este guardado (crear o modificar) cabe en la ventana abierta? Se llama con la consulta ya cerrada. */
    public static function permite(Model $modelo, ?Consulta $consulta): bool
    {
        if (self::$abierta === null || $consulta === null || (int) $consulta->id !== self::$abierta['consulta_id']) {
            return false;
        }
        $permitidos = self::PERMITIDOS[$modelo::class] ?? null;
        if ($permitidos === null || ($modelo instanceof Consulta && ! $modelo->exists)) {
            return false;
        }

        $cambios = $modelo->exists
            ? array_keys($modelo->getDirty())
            : array_keys(array_filter($modelo->getAttributes(), fn ($valor) => $valor !== null));

        return array_diff($cambios, [...$permitidos, 'id', 'consulta_id', 'created_at', 'updated_at']) === [];
    }
}
