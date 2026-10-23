<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La cobertura de una paciente (docs/convenios-seguros.md): plan, número de asegurado, titular o beneficiario y vigencia. Es solo un
 * dato de afiliación para armar la visación y el ticket: no calcula nada.
 *
 * - VIGENTE: la abierta (sin `cerrada_en`); una sola por paciente (`coberturas_una_vigente`) y, en PostgreSQL, los rangos de una
 *   paciente no se solapan.
 * - HISTORICO: cerrada al cargar otra (que la reemplaza) o al darla de baja, con su "hasta". Queda en el historial.
 *
 * Se aplica a un día por su rango. El modelo rechaza cualquier otro cambio, y el borrado.
 */
class CoberturaPaciente extends Model
{
    use Auditable, TieneEstado;

    public const TIPOS = ['TITULAR' => 'Titular', 'BENEFICIARIO' => 'Beneficiario'];

    protected $table = 'coberturas_paciente';

    private const CAMPOS_DE_CIERRE = ['estado_id', 'vigente_hasta', 'cerrada_en', 'cerrada_por', 'updated_at'];

    protected $fillable = ['paciente_id', 'plan_convenio_id', 'numero_asegurado', 'tipo_afiliado', 'vigente_desde', 'vigente_hasta', 'reemplaza_a_id', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'COBERTURAS';
    }

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'cerrada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CoberturaPaciente $cobertura) {
            $cobertura->estado_id = Estado::idDe(Estado::VIGENTE);
        });

        static::updating(function (CoberturaPaciente $cobertura) {
            $cierra = $cobertura->getOriginal('cerrada_en') === null && $cobertura->cerrada_en !== null
                && Estado::find($cobertura->estado_id)?->codigo === Estado::HISTORICO;
            if (! $cierra || array_diff(array_keys($cobertura->getDirty()), self::CAMPOS_DE_CIERRE) !== []) {
                throw RegistroEconomicoInmutable::convenio();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanConvenio::class, 'plan_convenio_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function abierta(): bool
    {
        return $this->cerrada_en === null;
    }

    /** Vale para ese día (aaaa-mm-dd) por su rango. */
    public function aplicaA(string $dia): bool
    {
        return $this->vigente_desde->format('Y-m-d') <= $dia && ($this->vigente_hasta === null || $this->vigente_hasta->format('Y-m-d') >= $dia);
    }

    public function tipoTexto(): string
    {
        return self::TIPOS[$this->tipo_afiliado] ?? (string) $this->tipo_afiliado;
    }

    public function vigenciaTexto(): string
    {
        return match (true) {
            $this->vigente_hasta !== null && $this->vigente_hasta->lt($this->vigente_desde) => 'Sin vigencia (reemplazada el mismo día)',
            $this->vigente_hasta === null => 'Desde el '.Fecha::mostrar($this->vigente_desde),
            default => 'Del '.Fecha::mostrar($this->vigente_desde).' al '.Fecha::mostrar($this->vigente_hasta),
        };
    }
}
