<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una factura individual CONTADO (docs/facturacion.md), emitida en Caja antes de registrar su cobro, en la misma transacción. Lleva
 * el timbrado y su número (001-001-0000001), la fecha y hora, el snapshot del emisor (la clínica y la sucursal) y del cliente (la
 * paciente), la moneda, una línea por prestación (DetalleFactura: solo lo que paga la paciente) y los totales por tasa de IVA.
 *
 * EMITIDO -> COBRADO (la contado, en la misma operación) -> ANULADO (con motivo, quién y cuándo; su número se conserva y no se
 * reutiliza). El estado de cobro sale del cobro vigente. El modelo rechaza cualquier otro cambio, y el borrado.
 */
class Factura extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'facturas';

    public const CONTADO = 'CONTADO';

    public const ETIQUETAS = [Estado::EMITIDO => 'Emitida (pendiente de cobro)', Estado::COBRADO => 'Cobrada', Estado::ANULADO => 'Anulada'];

    /** Los totales por tasa (columna => texto). */
    public const TOTALES = ['gravado_10' => 'Gravado 10 %', 'iva_10' => 'IVA 10 %', 'gravado_5' => 'Gravado 5 %', 'iva_5' => 'IVA 5 %', 'exento' => 'Exento'];

    private const CAMPOS_DE_COBRO = ['estado_id', 'updated_at'];

    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulada_en', 'anulada_por', 'motivo_anulacion_id', 'detalle_anulacion', 'updated_at'];

    protected $fillable = ['timbrado_id', 'sucursal_id', 'numero_timbrado', 'timbrado_desde', 'timbrado_hasta', 'establecimiento', 'punto_expedicion',
        'numero', 'numero_completo', 'emitida_en', 'condicion', 'emisor_razon_social', 'emisor_nombre_fantasia', 'emisor_ruc', 'emisor_actividad',
        'emisor_direccion', 'emisor_telefono', 'paciente_id', 'cliente_nombre', 'cliente_documento', 'moneda_id', 'gravado_10', 'iva_10',
        'gravado_5', 'iva_5', 'exento', 'total', 'usuario_id', 'caja_id', 'simulada', 'emisor_documentos', 'reemplaza_a_id'];

    public static function moduloEstado(): string
    {
        return 'FACTURAS';
    }

    protected function casts(): array
    {
        return ['emitida_en' => 'datetime', 'anulada_en' => 'datetime', 'timbrado_desde' => 'date', 'timbrado_hasta' => 'date',
            'simulada' => 'boolean', 'numero' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (Factura $factura) {
            $desde = Estado::find($factura->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($factura->estado_id)?->codigo;
            $cambios = array_keys($factura->getDirty());
            $permitido = match (true) {
                $desde === Estado::EMITIDO && $hacia === Estado::COBRADO => array_diff($cambios, self::CAMPOS_DE_COBRO) === [],
                in_array($desde, [Estado::EMITIDO, Estado::COBRADO], true) && $hacia === Estado::ANULADO => $factura->anulada_en !== null
                    && $factura->anulada_por !== null && $factura->motivo_anulacion_id !== null && array_diff($cambios, self::CAMPOS_DE_ANULACION) === [],
                default => false,
            };
            if (! $permitido) {
                throw RegistroEconomicoInmutable::factura();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return match (Estado::find($this->estado_id)?->codigo) {
            Estado::COBRADO => "Factura {$this->numero_completo} cobrada",
            Estado::ANULADO => "Factura {$this->numero_completo} anulada. Motivo: ".MotivoAnulacionFactura::find($this->motivo_anulacion_id)?->nombre
                .($this->detalle_anulacion ? " ({$this->detalle_anulacion})" : ''),
            default => null,
        };
    }

    public function timbrado(): BelongsTo
    {
        return $this->belongsTo(Timbrado::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleFactura::class);
    }

    /** El cobro registrado contra esta factura (vigente o, si se anuló la factura, revertido). */
    public function cobro(): HasOne
    {
        return $this->hasOne(Cobro::class);
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function motivoAnulacion(): BelongsTo
    {
        return $this->belongsTo(MotivoAnulacionFactura::class, 'motivo_anulacion_id');
    }

    /** La anulada a la que reemplaza esta. */
    public function reemplazaA(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'reemplaza_a_id');
    }

    /** La que reemplaza a esta (si está anulada). */
    public function reemplazadaPor(): HasOne
    {
        return $this->hasOne(Factura::class, 'reemplaza_a_id');
    }

    public function solicitudesAnulacion(): HasMany
    {
        return $this->hasMany(SolicitudAnulacionFactura::class);
    }

    public function solicitudPendiente(): HasOne
    {
        return $this->hasOne(SolicitudAnulacionFactura::class)->whereNull('revisado_en');
    }

    public function anulada(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    /** "Cobrada", "Pendiente de cobro" o "Cobro revertido": sale del cobro registrado contra ella. */
    public function estadoCobroTexto(): string
    {
        $cobro = $this->cobro;

        return match (true) {
            $cobro === null => 'Pendiente de cobro',
            $cobro->revertido() => 'Cobro revertido',
            default => 'Cobrada',
        };
    }

    public function formatear(int|float|string|null $monto): string
    {
        return $this->moneda->formatear((int) round((float) $monto));
    }

    public function entero(string $columna): int
    {
        return (int) round((float) $this->{$columna});
    }

    public function totalTexto(): string
    {
        return $this->formatear($this->total);
    }

    /** Lo que puede vincularse a una factura (notas de crédito o de débito, de fases futuras): hoy, la que la reemplaza. */
    public function tieneDocumentosVinculados(): bool
    {
        return $this->reemplazadaPor()->exists();
    }
}
