<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Enums\CondicionCompra;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una compra o gasto de la clínica con su comprobante (docs/compras-gastos.md), en guaraníes enteros.
 *
 * PENDIENTE ("Borrador": se edita) -> CONFIRMADO (por otra persona: nadie confirma lo que cargó) -> ANULADO (con motivo). El borrador
 * también se descarta: pasa a ANULADO con motivo. Confirmada o anulada es de solo lectura. El modelo rechaza cualquier otro cambio, y
 * el borrado; los pasos y su detalle en Auditoría los da Compras\FlujoCompra.
 */
class Compra extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'compras';

    public const ETIQUETAS = [Estado::PENDIENTE => 'Borrador', Estado::CONFIRMADO => 'Confirmada', Estado::ANULADO => 'Anulada'];

    /** Lo que se edita en el borrador. */
    public const EDITABLES = ['proveedor_id', 'categoria_gasto_id', 'fecha', 'tipo_comprobante_id', 'numero_comprobante', 'timbrado',
        'fecha_comprobante', 'imagen_comprobante', 'moneda_id', 'monto_10', 'monto_5', 'monto_exento', 'iva_10', 'iva_5', 'total',
        'condicion', 'vencimiento', 'anticipo', 'observaciones'];

    private const CONFIRMAR = ['estado_id', 'confirmada_por', 'confirmada_en'];

    private const ANULAR = ['estado_id', 'anulada_por', 'anulada_en', 'motivo_anulacion'];

    /** Cada paso: desde => hacia => los campos que cambia. */
    private const PASOS = [
        Estado::PENDIENTE => [Estado::PENDIENTE => self::EDITABLES, Estado::CONFIRMADO => self::CONFIRMAR, Estado::ANULADO => self::ANULAR],
        Estado::CONFIRMADO => [Estado::ANULADO => self::ANULAR],
    ];

    protected $fillable = [...self::EDITABLES, 'registrada_por'];

    public static function moduloEstado(): string
    {
        return 'COMPRAS';
    }

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fecha_comprobante' => 'date',
            'vencimiento' => 'date',
            'condicion' => CondicionCompra::class,
            'monto_10' => 'integer',
            'monto_5' => 'integer',
            'monto_exento' => 'integer',
            'iva_10' => 'integer',
            'iva_5' => 'integer',
            'total' => 'integer',
            'anticipo' => 'integer',
            'confirmada_en' => 'datetime',
            'anulada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Compra $compra) {
            $desde = Estado::find($compra->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($compra->estado_id)?->codigo;
            $permitidos = self::PASOS[$desde][$hacia] ?? null;
            if ($permitidos === null || array_diff(array_keys($compra->getDirty()), [...$permitidos, 'updated_at']) !== []) {
                throw RegistroEconomicoInmutable::compra();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    /** En Auditoría: el motivo al anular o descartar, y la confirmación (que es un EDITAR del estado). */
    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        if ($accion === AccionAuditoria::ANULAR && $this->motivo_anulacion) {
            $eraBorrador = Estado::find($this->getRawOriginal('estado_id'))?->codigo === Estado::PENDIENTE;

            return ($eraBorrador ? 'Borrador descartado. ' : '')."Motivo: {$this->motivo_anulacion}";
        }

        return $accion === AccionAuditoria::EDITAR && $this->wasChanged('confirmada_en') ? 'Confirmación de la compra' : null;
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    public function esBorrador(): bool
    {
        return $this->tieneEstado(Estado::PENDIENTE);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function categoriaGasto(): BelongsTo
    {
        return $this->belongsTo(CategoriaGasto::class);
    }

    public function tipoComprobante(): BelongsTo
    {
        return $this->belongsTo(TipoComprobanteCompra::class, 'tipo_comprobante_id');
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }

    public function confirmadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmada_por');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }
}
