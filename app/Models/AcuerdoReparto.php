<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use App\Support\Fecha;
use App\Support\Repartos\Porcentaje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acuerdo de reparto (docs/reparto-economico.md): el porcentaje sobre el monto de la prestación que le toca a un beneficiario.
 *
 * - PROFESIONAL: profesional y procedimiento (vacío = el general del profesional; uno por procedimiento lo pisa).
 * - EQUIPO: el porcentaje del dueño del equipo.
 *
 * Versionado como las tarifas: VIGENTE es el abierto (sin "hasta"), uno solo por clave (índice acuerdos_reparto_una_vigente);
 * "Cambiar porcentaje" lo cierra (HISTORICO) y abre otro. Se aplica a un día por su rango, no por el estado. El modelo rechaza
 * cualquier otro cambio, y el borrado.
 */
class AcuerdoReparto extends Model
{
    use Auditable, TieneEstado;

    public const PROFESIONAL = 'PROFESIONAL';

    public const EQUIPO = 'EQUIPO';

    public const TIPOS = [self::PROFESIONAL => 'Profesional', self::EQUIPO => 'Equipo'];

    protected $table = 'acuerdos_reparto';

    private const CAMPOS_DE_CIERRE = ['estado_id', 'vigente_hasta', 'updated_at'];

    protected $fillable = ['tipo', 'profesional_id', 'procedimiento_id', 'equipo_id', 'porcentaje', 'vigente_desde', 'reemplaza_a_id', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'ACUERDOS_REPARTO';
    }

    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:2',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AcuerdoReparto $acuerdo) {
            $acuerdo->estado_id = Estado::idDe(Estado::VIGENTE);
        });

        static::updating(function (AcuerdoReparto $acuerdo) {
            $cierra = Estado::find($acuerdo->getOriginal('estado_id'))?->codigo === Estado::VIGENTE
                && Estado::find($acuerdo->estado_id)?->codigo === Estado::HISTORICO
                && $acuerdo->vigente_hasta !== null;
            if (! $cierra || array_diff(array_keys($acuerdo->getDirty()), self::CAMPOS_DE_CIERRE) !== []) {
                throw RegistroEconomicoInmutable::acuerdo();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class);
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reemplazaA(): BelongsTo
    {
        return $this->belongsTo(AcuerdoReparto::class, 'reemplaza_a_id');
    }

    public function vigente(): bool
    {
        return $this->tieneEstado(Estado::VIGENTE);
    }

    public function esDeEquipo(): bool
    {
        return $this->tipo === self::EQUIPO;
    }

    /** Los de la misma clave: tipo, profesional, procedimiento (vacío = general) y equipo. */
    public function scopeDeLaClave(Builder $query, string $tipo, ?int $profesionalId, ?int $procedimientoId, ?int $equipoId): void
    {
        $query->where('tipo', $tipo);
        foreach (['profesional_id' => $profesionalId, 'procedimiento_id' => $procedimientoId, 'equipo_id' => $equipoId] as $columna => $valor) {
            $valor === null ? $query->whereNull($columna) : $query->where($columna, $valor);
        }
    }

    /** Los que se aplican a ese día (aaaa-mm-dd), por su rango. */
    public function scopeAplicaA(Builder $query, string $dia): void
    {
        $query->whereDate('vigente_desde', '<=', $dia)
            ->where(fn ($query) => $query->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $dia));
    }

    /** No tuvo ningún día de vigencia: se reemplazó el mismo día en que empezaba. */
    public function sinVigencia(): bool
    {
        return $this->vigente_hasta !== null && $this->vigente_hasta->lt($this->vigente_desde);
    }

    public function porcentajeTexto(): string
    {
        return Porcentaje::texto($this->porcentaje);
    }

    public function vigenciaTexto(): string
    {
        return match (true) {
            $this->sinVigencia() => 'Sin vigencia (reemplazado el mismo día)',
            $this->vigente_hasta === null => 'Desde el '.Fecha::mostrar($this->vigente_desde),
            default => 'Del '.Fecha::mostrar($this->vigente_desde).' al '.Fecha::mostrar($this->vigente_hasta),
        };
    }

    /** "Dra. Benítez" o "Ecógrafo 1 (dueño: Equipos SA)". */
    public function beneficiarioTexto(): string
    {
        return $this->esDeEquipo()
            ? "{$this->equipo?->nombre} (dueño: {$this->equipo?->duenoTexto()})"
            : (string) $this->profesional?->persona?->nombre_completo;
    }

    public function alcanceTexto(): string
    {
        return match (true) {
            $this->esDeEquipo() => 'Dueño del equipo',
            $this->procedimiento_id === null => 'General (todos los procedimientos)',
            default => "{$this->procedimiento?->nombre} ({$this->procedimiento?->codigo})",
        };
    }
}
