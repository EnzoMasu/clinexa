<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un cierre declarado de una caja (docs/cobros-caja.md): lo contado por medio (CierreCajaMedio), el motivo si hay diferencia y su
 * revisión. PENDIENTE (de aprobación) -> APROBADO, o -> DEVUELTO con la nota de Gerencia; una caja devuelta y vuelta a cerrar
 * tiene otro cierre, y el anterior se conserva. El modelo rechaza cualquier otro cambio, y el borrado.
 */
class CierreCaja extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'cierres_caja';

    public const ETIQUETAS = [Estado::PENDIENTE => 'Pendiente de aprobación', Estado::APROBADO => 'Aprobado', Estado::DEVUELTO => 'Devuelto'];

    private const CAMPOS_DE_REVISION = ['estado_id', 'revisado_por', 'revisado_en', 'nota_revision', 'updated_at'];

    protected $fillable = ['caja_id', 'usuario_id', 'cerrado_en', 'motivo_diferencia'];

    public static function moduloEstado(): string
    {
        return 'CIERRES_CAJA';
    }

    protected function casts(): array
    {
        return [
            'cerrado_en' => 'datetime',
            'revisado_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CierreCaja $cierre) {
            $desde = Estado::find($cierre->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($cierre->estado_id)?->codigo;
            if ($desde !== Estado::PENDIENTE || ! in_array($hacia, [Estado::APROBADO, Estado::DEVUELTO], true)
                || array_diff(array_keys($cierre->getDirty()), self::CAMPOS_DE_REVISION) !== []) {
                throw RegistroEconomicoInmutable::caja();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $this->nota_revision ? "Nota: {$this->nota_revision}" : null;
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function cajero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function medios(): HasMany
    {
        return $this->hasMany(CierreCajaMedio::class);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }
}
