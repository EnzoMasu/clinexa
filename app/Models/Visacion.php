<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Visación (docs/convenios-seguros.md): la autorización que la aseguradora da para UN turno o UNA consulta de la paciente, con su
 * número (único por responsable de pago entre las no anuladas: `visaciones_numero_unico`), la fecha y una línea por procedimiento
 * (LineaVisacion: APROBADO, con lo que paga la paciente, o NO_CUBIERTO).
 *
 * - VIGENTE: la usa la prestación al crearse (o "Aplicar visación").
 * - ANULADO: con motivo, quién y cuándo. Las prestaciones que ya la aplicaron quedan como están (con un aviso).
 *
 * Nada se edita ni se borra.
 */
class Visacion extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'visaciones';

    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulada_en', 'motivo_anulacion', 'anulada_por', 'updated_at'];

    protected $fillable = ['cobertura_paciente_id', 'responsable_pago_id', 'turno_id', 'consulta_id', 'numero', 'fecha', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'VISACIONES';
    }

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'anulada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Visacion $visacion) {
            $visacion->estado_id = Estado::idDe(Estado::VIGENTE);
        });

        static::updating(function (Visacion $visacion) {
            $anula = Estado::find($visacion->getOriginal('estado_id'))?->codigo === Estado::VIGENTE
                && Estado::find($visacion->estado_id)?->codigo === Estado::ANULADO;
            if (! $anula || array_diff(array_keys($visacion->getDirty()), self::CAMPOS_DE_ANULACION) !== []) {
                throw RegistroEconomicoInmutable::convenio();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $accion === AccionAuditoria::ANULAR && $this->motivo_anulacion ? "Motivo: {$this->motivo_anulacion}" : null;
    }

    public function cobertura(): BelongsTo
    {
        return $this->belongsTo(CoberturaPaciente::class, 'cobertura_paciente_id');
    }

    public function responsablePago(): BelongsTo
    {
        return $this->belongsTo(ResponsablePago::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(LineaVisacion::class)->orderBy('id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function vigente(): bool
    {
        return $this->tieneEstado(Estado::VIGENTE);
    }

    public function anulada(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function scopeVigentes(Builder $query): void
    {
        $query->where('visaciones.estado_id', Estado::idDe(Estado::VIGENTE));
    }

    /** "del turno del 22/10/2026" o "de la consulta N.º 15". */
    public function destinoTexto(): string
    {
        return $this->turno_id
            ? 'Turno del '.\App\Support\Fecha::mostrar($this->turno->fecha).($this->turno->tieneHorario() ? ', '.substr($this->turno->hora_inicio, 0, 5) : '')
            : "Consulta N.º {$this->consulta_id}";
    }
}
