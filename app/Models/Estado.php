<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RuntimeException;

/**
 * Catálogo único de estados del sistema. Qué estados puede tomar cada módulo (y cuál es el
 * inicial) se define en estado_modulo; la lógica compara siempre por codigo, nunca por nombre.
 */
class Estado extends Model
{
    public const ACTIVO = 'ACTIVO';

    public const INACTIVO = 'INACTIVO';

    public const BLOQUEADO = 'BLOQUEADO';

    // Estados de los turnos.
    public const PENDIENTE = 'PENDIENTE';

    public const CONFIRMADO = 'CONFIRMADO';

    public const ATENDIDO = 'ATENDIDO';

    public const CANCELADO = 'CANCELADO';

    public const AUSENTE = 'AUSENTE';

    /** Turno: el paciente está en el consultorio (se pasa con Atender). */
    public const EN_CONSULTA = 'EN_CONSULTA';

    /** Turno: lo llamaron y no estaba ("No se presentó"); se lo vuelve a llamar. */
    public const SALTADO = 'SALTADO';

    /** Turno: el paciente se retiró o rechazó la atención sin ser atendido (ver SalidaSinAtencion). */
    public const RETIRADO = 'RETIRADO';

    // Estados de la consulta (módulo HISTORIA_CLINICA): EN_PREPARACION (inicial), EN_CURSO, FINALIZADO y ANULADO.
    public const EN_PREPARACION = 'EN_PREPARACION';

    public const EN_CURSO = 'EN_CURSO';

    public const FINALIZADO = 'FINALIZADO';

    /** Recetas: emitida (impresa, ya no se modifica). El borrador usa PENDIENTE. */
    public const EMITIDO = 'EMITIDO';

    /** Recetas: anulada (se conserva y se ve, con el motivo). */
    public const ANULADO = 'ANULADO';

    /** Desbloqueo de una consulta cerrada: la ventana de corrección vigente, usada (REALIZADO) o vencida (CANCELADO la anula). */
    public const VIGENTE = 'VIGENTE';

    public const REALIZADO = 'REALIZADO';

    public const VENCIDO = 'VENCIDO';

    /** Parámetros clínicos: aprobado por un médico (si no, PENDIENTE: no marca alertas). */
    public const APROBADO = 'APROBADO';

    /** Tarifas: la que se cerró al cambiar el monto (la abierta es VIGENTE). */
    public const HISTORICO = 'HISTORICO';

    /** Prestaciones: con su monto fijado (la que todavía no tiene usa PENDIENTE: "pendiente de tarifar"). */
    public const TARIFADO = 'TARIFADO';

    /** Reparto de una prestación: hecho, con sus líneas fijadas (el que no se pudo calcular usa PENDIENTE, con el motivo). */
    public const REPARTIDO = 'REPARTIDO';

    /** Caja (docs/cobros-caja.md): abierta, cerrada (pendiente de aprobación), aprobada (APROBADO) o devuelta para corregir. */
    public const ABIERTO = 'ABIERTO';

    public const CERRADO = 'CERRADO';

    public const DEVUELTO = 'DEVUELTO';

    /** Cobro revertido (docs/cobros-caja.md): se conserva, con el motivo; sus prestaciones vuelven a estar sin cobrar. */
    public const REVERTIDO = 'REVERTIDO';

    /** Solicitud de reversión de un cobro rechazada por Gerencia (la aprobada usa APROBADO; la pendiente, PENDIENTE). */
    public const RECHAZADO = 'RECHAZADO';

    /**
     * Liquidación a una aseguradora (docs/liquidaciones-aseguradoras.md): EN_PREPARACION (inicial), pendiente de aprobación, APROBADO,
     * pagada en parte (PARCIAL) o del todo (PAGADO), o ANULADO. PARCIAL y PAGADO ya existían en el catálogo inicial.
     */
    public const PENDIENTE_APROBACION = 'PENDIENTE_APROBACION';

    public const PARCIAL = 'PARCIAL';

    public const PAGADO = 'PAGADO';

    /**
     * Factura (docs/facturacion.md): EMITIDO ("Emitida", pendiente de cobro, inicial; ya existía), COBRADO ("Cobrada": la contado
     * pasa en la misma operación) o ANULADO ("Anulada", se conserva con su número).
     */
    public const COBRADO = 'COBRADO';

    protected $table = 'estados';

    protected $fillable = [
        'codigo',
        'nombre',
    ];

    /**
     * ids por código, cargados una vez: el catálogo lo siembra una migración y no cambia en runtime.
     *
     * @var array<string, int>
     */
    private static array $ids = [];

    public static function idDe(string $codigo): int
    {
        return self::$ids[$codigo] ??= self::where('codigo', $codigo)->value('id')
            ?? throw new RuntimeException("No existe el estado {$codigo} en la tabla estados.");
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(ModuloSistema::class, 'estado_modulo', 'estado_id', 'modulo_sistema_id')
            ->withPivot('es_inicial');
    }

    /**
     * Estados habilitados para un módulo (por código de modulos_sistema), el inicial primero.
     * Si el módulo todavía no tiene estados configurados, ACTIVO e INACTIVO.
     *
     * @return Collection<int, Estado>
     */
    public static function delModulo(string $modulo): Collection
    {
        $estados = self::query()
            ->join('estado_modulo', 'estado_modulo.estado_id', '=', 'estados.id')
            ->join('modulos_sistema', 'modulos_sistema.id', '=', 'estado_modulo.modulo_sistema_id')
            ->where('modulos_sistema.codigo', $modulo)
            ->orderByDesc('estado_modulo.es_inicial')
            ->orderBy('estados.id')
            ->get(['estados.*']);

        return $estados->isNotEmpty()
            ? $estados
            : self::whereIn('codigo', [self::ACTIVO, self::INACTIVO])->orderBy('id')->get();
    }

    /**
     * Estado inicial de un módulo (es_inicial en estado_modulo), o null si no tiene configurado.
     */
    public static function inicialDe(string $modulo): ?int
    {
        return self::query()
            ->join('estado_modulo', 'estado_modulo.estado_id', '=', 'estados.id')
            ->join('modulos_sistema', 'modulos_sistema.id', '=', 'estado_modulo.modulo_sistema_id')
            ->where('modulos_sistema.codigo', $modulo)
            ->where('estado_modulo.es_inicial', true)
            ->value('estados.id');
    }
}
