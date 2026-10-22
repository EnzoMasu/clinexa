<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una solicitud de reversión de un cobro (docs/cobros-caja.md): la pide Caja, con motivo, cuando el cobro es de una caja cerrada,
 * devuelta o aprobada, o de otro cajero. PENDIENTE -> APROBADO (se revierte el cobro, con el egreso en la caja abierta del
 * solicitante) o -> RECHAZADO con la nota de Gerencia. Una sola pendiente por cobro (`solicitudes_reversion_una_pendiente`). El
 * modelo rechaza cualquier otro cambio, y el borrado.
 */
class SolicitudReversionCobro extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'solicitudes_reversion_cobro';

    public const ETIQUETAS = [Estado::PENDIENTE => 'Pendiente de aprobación', Estado::APROBADO => 'Aprobada', Estado::RECHAZADO => 'Rechazada'];

    private const CAMPOS_DE_REVISION = ['estado_id', 'revisado_por', 'revisado_en', 'nota_revision', 'updated_at'];

    protected $fillable = ['cobro_id', 'usuario_id', 'solicitada_en', 'motivo'];

    public static function moduloEstado(): string
    {
        return 'REVERSIONES_COBRO';
    }

    protected function casts(): array
    {
        return [
            'solicitada_en' => 'datetime',
            'revisado_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SolicitudReversionCobro $solicitud) {
            $desde = Estado::find($solicitud->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($solicitud->estado_id)?->codigo;
            if ($desde !== Estado::PENDIENTE || ! in_array($hacia, [Estado::APROBADO, Estado::RECHAZADO], true) || $solicitud->revisado_en === null
                || array_diff(array_keys($solicitud->getDirty()), self::CAMPOS_DE_REVISION) !== []) {
                throw RegistroEconomicoInmutable::cobro();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        $texto = match (Estado::find($this->estado_id)?->codigo) {
            Estado::APROBADO => 'Reversión aprobada',
            Estado::RECHAZADO => 'Reversión rechazada',
            default => null,
        };

        return $texto && $this->nota_revision ? "{$texto}. Nota: {$this->nota_revision}" : $texto;
    }

    public function scopePendientes(Builder $query): void
    {
        $query->whereNull('solicitudes_reversion_cobro.revisado_en');
    }

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function pendiente(): bool
    {
        return $this->revisado_en === null;
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }
}
