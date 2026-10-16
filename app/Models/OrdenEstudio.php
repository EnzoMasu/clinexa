<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\OrdenEstudioInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden de estudio (docs/ordenes-estudio.md). Documento aparte de la consulta, inmutable como el certificado de reposo:
 *
 * - EMITIDO: nace con número (OE-0000001), fecha, snapshot (todo lo que se imprime, con los estudios) y huella sha256.
 * - ANULADO: con fecha y motivo; se conserva y se ve. Es lo único que se le puede hacer a una emitida.
 *
 * El modelo rechaza cualquier otro cambio (OrdenEstudioInmutable), también desde código. Nada se borra.
 */
class OrdenEstudio extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'ordenes_estudio';

    public const PREFIJO = 'OE-';

    public const DIGITOS_NUMERO = 7;

    /** Lo único que cambia al anular. */
    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulada_en', 'motivo_anulacion', 'updated_at'];

    public const ETIQUETAS = [Estado::EMITIDO => 'Emitida', Estado::ANULADO => 'Anulada'];

    protected $fillable = ['numero', 'consulta_id', 'fecha', 'estado_id', 'emitida_en', 'snapshot', 'huella', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'ORDENES_ESTUDIO';
    }

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'emitida_en' => 'datetime',
            'anulada_en' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (OrdenEstudio $orden) {
            $desde = Estado::find($orden->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($orden->estado_id)?->codigo;

            if ($desde !== Estado::EMITIDO || $hacia !== Estado::ANULADO) {
                throw $desde === $hacia ? OrdenEstudioInmutable::contenido() : OrdenEstudioInmutable::transicion();
            }
            if (array_diff(array_keys($orden->getDirty()), self::CAMPOS_DE_ANULACION) !== []) {
                throw OrdenEstudioInmutable::contenido();
            }
        });

        static::deleting(fn () => throw OrdenEstudioInmutable::borrado());
    }

    /** El snapshot no va al log (duplicaría el documento); queda la huella, que lo identifica. */
    public function camposNoAuditados(): array
    {
        return ['snapshot'];
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $accion === AccionAuditoria::ANULAR && $this->motivo_anulacion ? "Motivo: {$this->motivo_anulacion}" : null;
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function estaEmitida(): bool
    {
        return $this->tieneEstado(Estado::EMITIDO);
    }

    public function estaAnulada(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    /** Los nombres de los estudios pedidos, tal como quedaron en el snapshot. */
    public function nombresEstudios(): array
    {
        return array_column((array) ($this->snapshot['estudios'] ?? []), 'nombre');
    }

    /** Si el snapshot guardado sigue dando la huella guardada (misma forma canónica que las constancias). */
    public function huellaValida(): bool
    {
        return hash_equals((string) $this->huella, Constancia::calcularHuella((array) $this->snapshot));
    }

    /** El número que tendría la próxima orden: el mayor + 1 (ancho fijo: el orden alfabético es el numérico). */
    public static function siguienteNumero(): string
    {
        $mayor = static::query()->withoutEagerLoads()->max('numero');
        $siguiente = $mayor ? (int) substr($mayor, strlen(self::PREFIJO)) + 1 : 1;

        return self::PREFIJO.str_pad((string) $siguiente, self::DIGITOS_NUMERO, '0', STR_PAD_LEFT);
    }
}
