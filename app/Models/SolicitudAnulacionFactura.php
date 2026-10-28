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
 * Una solicitud de anulación de una factura (docs/facturacion.md): la pide el cajero, con un motivo del catálogo (y un detalle),
 * cuando pasó el plazo, la caja está cerrada o la factura es de otro cajero. PENDIENTE -> APROBADO (se revierte su cobro y se anula
 * la factura) o -> RECHAZADO con la nota de Gerencia. Nadie revisa la propia. Una sola pendiente por factura
 * (`solicitudes_anulacion_una_pendiente`). El modelo rechaza cualquier otro cambio, y el borrado.
 */
class SolicitudAnulacionFactura extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'solicitudes_anulacion_factura';

    public const ETIQUETAS = [Estado::PENDIENTE => 'Pendiente de aprobación', Estado::APROBADO => 'Aprobada', Estado::RECHAZADO => 'Rechazada'];

    private const CAMPOS_DE_REVISION = ['estado_id', 'revisado_por', 'revisado_en', 'nota_revision', 'updated_at'];

    protected $fillable = ['factura_id', 'usuario_id', 'solicitada_en', 'motivo_anulacion_id', 'detalle'];

    public static function moduloEstado(): string
    {
        return 'ANULACIONES_FACTURA';
    }

    protected function casts(): array
    {
        return ['solicitada_en' => 'datetime', 'revisado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (SolicitudAnulacionFactura $solicitud) {
            $desde = Estado::find($solicitud->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($solicitud->estado_id)?->codigo;
            if ($desde !== Estado::PENDIENTE || ! in_array($hacia, [Estado::APROBADO, Estado::RECHAZADO], true) || $solicitud->revisado_en === null
                || array_diff(array_keys($solicitud->getDirty()), self::CAMPOS_DE_REVISION) !== []) {
                throw RegistroEconomicoInmutable::factura();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        $texto = match (Estado::find($this->estado_id)?->codigo) {
            Estado::APROBADO => 'Anulación aprobada',
            Estado::RECHAZADO => 'Anulación rechazada',
            default => null,
        };

        return $texto && $this->nota_revision ? "{$texto}. Nota: {$this->nota_revision}" : $texto;
    }

    public function scopePendientes(Builder $query): void
    {
        $query->whereNull('solicitudes_anulacion_factura.revisado_en');
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(MotivoAnulacionFactura::class, 'motivo_anulacion_id');
    }

    public function pendiente(): bool
    {
        return $this->revisado_en === null;
    }

    public function motivoTexto(): string
    {
        return $this->motivo->nombre.($this->detalle ? " ({$this->detalle})" : '');
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }
}
