<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Convenio con un responsable de pago (la aseguradora) (docs/convenios-seguros.md): nombre, vigencia y sus planes (solo nombres)
 * y tarifas. No define qué cubre cada plan: eso lo decide la aseguradora en cada visación.
 *
 * - VIGENTE: en uso. Se aplica a un día por su rango [desde, hasta] (hasta vacío = sin fin).
 * - HISTORICO: pasado a histórico con su último día. Nada se borra.
 *
 * Solo cambian el nombre y el paso a histórico; el modelo rechaza el resto, y el borrado.
 */
class Convenio extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'convenios';

    private const CAMPOS_EDITABLES = ['nombre', 'updated_at'];

    private const CAMPOS_DE_CIERRE = ['estado_id', 'vigente_hasta', 'updated_at'];

    protected $fillable = ['responsable_pago_id', 'nombre', 'vigente_desde', 'vigente_hasta', 'usuario_id'];

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
        static::creating(function (Convenio $convenio) {
            $convenio->estado_id = Estado::idDe(Estado::VIGENTE);
        });

        static::updating(function (Convenio $convenio) {
            $cierra = Estado::find($convenio->getOriginal('estado_id'))?->codigo === Estado::VIGENTE
                && Estado::find($convenio->estado_id)?->codigo === Estado::HISTORICO && $convenio->vigente_hasta !== null;
            $permitidos = $cierra ? self::CAMPOS_DE_CIERRE : self::CAMPOS_EDITABLES;
            if (array_diff(array_keys($convenio->getDirty()), $permitidos) !== []) {
                throw RegistroEconomicoInmutable::convenio();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function responsablePago(): BelongsTo
    {
        return $this->belongsTo(ResponsablePago::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function planes(): HasMany
    {
        return $this->hasMany(PlanConvenio::class)->orderBy('nombre');
    }

    public function tarifas(): HasMany
    {
        return $this->hasMany(TarifaConvenio::class);
    }

    public function vigente(): bool
    {
        return $this->tieneEstado(Estado::VIGENTE);
    }

    /** Se aplica a ese día (aaaa-mm-dd) por su rango. */
    public function aplicaA(string $dia): bool
    {
        return $this->vigente_desde->format('Y-m-d') <= $dia && ($this->vigente_hasta === null || $this->vigente_hasta->format('Y-m-d') >= $dia);
    }

    public function aseguradoraTexto(): string
    {
        return (string) $this->responsablePago?->persona?->nombre_completo;
    }

    /** "Aseguradora — Convenio". */
    public function nombreCompleto(): string
    {
        return $this->aseguradoraTexto().' — '.$this->nombre;
    }

    public function vigenciaTexto(): string
    {
        return $this->vigente_hasta === null
            ? 'Desde el '.Fecha::mostrar($this->vigente_desde)
            : 'Del '.Fecha::mostrar($this->vigente_desde).' al '.Fecha::mostrar($this->vigente_hasta);
    }
}
