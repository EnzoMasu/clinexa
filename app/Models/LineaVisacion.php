<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Support\Repartos\Porcentaje;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la visación (docs/convenios-seguros.md): un procedimiento y la respuesta de la aseguradora.
 *
 * - APROBADO, con lo que paga la paciente: NINGUNO (la aseguradora cubre todo), PORCENTAJE (0 a 100, dos decimales) o MONTO_FIJO
 *   (guaraníes). Nunca más que la tarifa: el resto lo paga la aseguradora.
 * - NO_CUBIERTO: la paciente paga la tarifa general completa; la aseguradora no interviene.
 *
 * Es una foto: no se edita ni se borra.
 */
class LineaVisacion extends Model
{
    use Auditable;

    public const APROBADO = 'APROBADO';

    public const NO_CUBIERTO = 'NO_CUBIERTO';

    public const RESULTADOS = [self::APROBADO => 'Aprobado', self::NO_CUBIERTO => 'No cubierto'];

    public const NINGUNO = 'NINGUNO';

    public const PORCENTAJE = 'PORCENTAJE';

    public const MONTO_FIJO = 'MONTO_FIJO';

    public const COPAGOS = [self::NINGUNO => 'Sin copago (cubre todo)', self::PORCENTAJE => 'Porcentaje', self::MONTO_FIJO => 'Monto fijo'];

    protected $table = 'lineas_visacion';

    protected $fillable = ['visacion_id', 'procedimiento_id', 'resultado', 'copago', 'porcentaje', 'monto'];

    protected function casts(): array
    {
        return ['porcentaje' => 'decimal:2'];
    }

    /** Sin estados propios: su auditoría es la de las visaciones. */
    public static function moduloAuditoria(): string
    {
        return 'VISACIONES';
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw RegistroEconomicoInmutable::convenio());
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function visacion(): BelongsTo
    {
        return $this->belongsTo(Visacion::class);
    }

    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class);
    }

    public function aprobada(): bool
    {
        return $this->resultado === self::APROBADO;
    }

    /** "Aprobado, sin copago", "Aprobado, copago 30 %", "Aprobado, copago ₲ 100.000" o "No cubierto". */
    public function textoResultado(Moneda $moneda): string
    {
        return match (true) {
            ! $this->aprobada() => self::RESULTADOS[self::NO_CUBIERTO],
            $this->copago === self::PORCENTAJE => 'Aprobado, copago '.Porcentaje::texto($this->porcentaje),
            $this->copago === self::MONTO_FIJO => 'Aprobado, copago '.$moneda->formatear($this->monto),
            default => 'Aprobado, sin copago',
        };
    }
}
