<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Prestación: el servicio prestado (docs/tarifas-prestaciones.md). Nace al finalizar la consulta (Prestaciones\GenerarPrestacion):
 * la base (una por consulta) y una por cada informe de estudio (informe_estudio_id). Sin contenido clínico: paciente, profesional,
 * procedimiento, fecha de servicio, consulta, turno e informe (solo el vínculo).
 *
 * - PENDIENTE ("pendiente de tarifar"): sin monto; no había tarifa para ese día.
 * - TARIFADO: con monto, moneda y la tarifa usada, fijados una vez; no cambian aunque la tarifa cambie después.
 * - ANULADO: con motivo, fecha y quién; se conserva.
 *
 * El modelo rechaza cualquier otro cambio, y el borrado.
 */
class Prestacion extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'prestaciones';

    /** El origen, para mostrar y filtrar: la base de la consulta (o el turno) o un informe de estudio. */
    public const ORIGENES = ['CONSULTA' => 'Consulta/Turno', 'INFORME' => 'Informe de estudio'];

    public const ETIQUETAS = [Estado::PENDIENTE => 'Pendiente de tarifar', Estado::TARIFADO => 'Tarifada', Estado::ANULADO => 'Anulada'];

    /** Estado de cobro (docs/cobros-caja.md): derivado de si tiene un cobro vigente; no es un estado de la prestación. */
    public const COBRADA = 'Cobrada';

    public const PENDIENTE_DE_COBRO = 'Pendiente';

    private const CAMPOS_DE_TARIFADO = ['estado_id', 'monto', 'moneda_id', 'tarifa_id', 'tarifada_en', 'tarifada_por', 'updated_at'];

    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulada_en', 'motivo_anulacion', 'anulada_por', 'updated_at'];

    protected $fillable = ['consulta_id', 'turno_id', 'informe_estudio_id', 'paciente_id', 'profesional_id', 'procedimiento_id', 'fecha_servicio'];

    public static function moduloEstado(): string
    {
        return 'PRESTACIONES';
    }

    protected function casts(): array
    {
        return [
            'fecha_servicio' => 'date',
            'tarifada_en' => 'datetime',
            'anulada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Prestacion $prestacion) {
            $desde = Estado::find($prestacion->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($prestacion->estado_id)?->codigo;
            $permitidos = match (true) {
                $desde === Estado::PENDIENTE && $hacia === Estado::TARIFADO && $prestacion->monto !== null => self::CAMPOS_DE_TARIFADO,
                in_array($desde, [Estado::PENDIENTE, Estado::TARIFADO], true) && $hacia === Estado::ANULADO => self::CAMPOS_DE_ANULACION,
                default => null,
            };
            if ($permitidos === null || array_diff(array_keys($prestacion->getDirty()), $permitidos) !== []) {
                throw RegistroEconomicoInmutable::prestacion();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $accion === AccionAuditoria::ANULAR && $this->motivo_anulacion ? "Motivo: {$this->motivo_anulacion}" : null;
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    /** El informe de estudio que la originó (vacío en la base). Solo el vínculo: su contenido es clínico. */
    public function informeEstudio(): BelongsTo
    {
        return $this->belongsTo(InformeEstudio::class);
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function tarifa(): BelongsTo
    {
        return $this->belongsTo(Tarifa::class);
    }

    /** Su reparto (docs/reparto-economico.md), si ya se calculó: uno por prestación. */
    public function reparto(): HasOne
    {
        return $this->hasOne(RepartoPrestacion::class);
    }

    /** Su cobro vigente (docs/cobros-caja.md), si tiene: a lo sumo uno. */
    public function cobroVigente(): HasOne
    {
        return $this->hasOne(CobroPrestacion::class)->whereNull('revertido_en');
    }

    public function tarifadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tarifada_por');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function pendiente(): bool
    {
        return $this->tieneEstado(Estado::PENDIENTE);
    }

    public function anulada(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function origenTexto(): string
    {
        return self::ORIGENES[$this->informe_estudio_id ? 'INFORME' : 'CONSULTA'];
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    /**
     * El estado de cobro, derivado de si tiene un cobro vigente: "Cobrada" o "Pendiente" (vacío en una anulada sin cobro). Usa
     * `cobro_vigente_exists` (withExists('cobroVigente')) si se cargó.
     */
    public function estadoCobroTexto(): string
    {
        $cobrada = $this->cobro_vigente_exists
            ?? ($this->relationLoaded('cobroVigente') ? $this->cobroVigente !== null : $this->cobroVigente()->exists());

        return match (true) {
            (bool) $cobrada => self::COBRADA,
            $this->anulada() => '',
            default => self::PENDIENTE_DE_COBRO,
        };
    }

    public function montoTexto(): string
    {
        return $this->monto === null ? '—' : $this->moneda->formatear($this->monto);
    }
}
