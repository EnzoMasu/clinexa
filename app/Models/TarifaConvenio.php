<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarifa de un procedimiento en un convenio (docs/convenios-seguros.md). Versionada como las tarifas generales:
 *
 * - VIGENTE: la abierta (sin "hasta"); una sola por convenio y procedimiento (`tarifas_convenio_una_vigente`).
 * - HISTORICO: cerrada al cambiar el monto (cierra y abre otra) o al darla de baja, con su "hasta".
 *
 * Se aplica a un día por su rango, no por el estado. Si el procedimiento no figura en el convenio ese día, la prestación usa la
 * tarifa general. El modelo rechaza cualquier otro cambio, y el borrado.
 */
class TarifaConvenio extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'tarifas_convenio';

    private const CAMPOS_DE_CIERRE = ['estado_id', 'vigente_hasta', 'updated_at'];

    protected $fillable = ['convenio_id', 'procedimiento_id', 'monto', 'moneda_id', 'vigente_desde', 'reemplaza_a_id', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'CONVENIOS';
    }

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TarifaConvenio $tarifa) {
            $tarifa->estado_id = Estado::idDe(Estado::VIGENTE);
        });

        static::updating(function (TarifaConvenio $tarifa) {
            $cierra = Estado::find($tarifa->getOriginal('estado_id'))?->codigo === Estado::VIGENTE
                && Estado::find($tarifa->estado_id)?->codigo === Estado::HISTORICO
                && $tarifa->vigente_hasta !== null;
            if (! $cierra || array_diff(array_keys($tarifa->getDirty()), self::CAMPOS_DE_CIERRE) !== []) {
                throw RegistroEconomicoInmutable::convenio();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vigente(): bool
    {
        return $this->tieneEstado(Estado::VIGENTE);
    }

    /** Las de ese día (aaaa-mm-dd), por su rango. */
    public function scopeAplicaA(Builder $query, string $dia): void
    {
        $query->whereDate('vigente_desde', '<=', $dia)
            ->where(fn ($query) => $query->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $dia));
    }

    public function sinVigencia(): bool
    {
        return $this->vigente_hasta !== null && $this->vigente_hasta->lt($this->vigente_desde);
    }

    public function montoTexto(): string
    {
        return $this->moneda->formatear($this->monto);
    }

    public function vigenciaTexto(): string
    {
        return match (true) {
            $this->sinVigencia() => 'Sin vigencia (reemplazada el mismo día)',
            $this->vigente_hasta === null => 'Desde el '.Fecha::mostrar($this->vigente_desde),
            default => 'Del '.Fecha::mostrar($this->vigente_desde).' al '.Fecha::mostrar($this->vigente_hasta),
        };
    }
}
