<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Support\Repartos\Porcentaje;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del reparto de una prestación (docs/reparto-economico.md): el beneficiario (CLINICA, PROFESIONAL o EQUIPO, con el
 * profesional o el proveedor dueño), su porcentaje, su monto en guaraníes enteros y los acuerdos usados. Es una foto: no se
 * edita ni se borra (al anular la prestación se anula el reparto, y la línea queda).
 */
class LineaReparto extends Model
{
    use Auditable;

    public const CLINICA = 'CLINICA';

    public const PROFESIONAL = 'PROFESIONAL';

    public const EQUIPO = 'EQUIPO';

    public const ETIQUETAS = [self::CLINICA => 'Clínica', self::PROFESIONAL => 'Profesional', self::EQUIPO => 'Dueño del equipo'];

    protected $table = 'lineas_reparto';

    protected $fillable = ['reparto_prestacion_id', 'beneficiario', 'profesional_id', 'proveedor_id', 'equipo_id', 'porcentaje', 'monto',
        'acuerdo_profesional_id', 'acuerdo_equipo_id'];

    protected function casts(): array
    {
        return ['porcentaje' => 'decimal:2'];
    }

    /** Sin estados propios: su auditoría es la de los repartos. */
    public static function moduloAuditoria(): string
    {
        return 'REPARTOS';
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw RegistroEconomicoInmutable::reparto());
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function reparto(): BelongsTo
    {
        return $this->belongsTo(RepartoPrestacion::class, 'reparto_prestacion_id');
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    public function acuerdoProfesional(): BelongsTo
    {
        return $this->belongsTo(AcuerdoReparto::class, 'acuerdo_profesional_id');
    }

    public function acuerdoEquipo(): BelongsTo
    {
        return $this->belongsTo(AcuerdoReparto::class, 'acuerdo_equipo_id');
    }

    public function beneficiarioTexto(): string
    {
        return match ($this->beneficiario) {
            self::PROFESIONAL => (string) $this->profesional?->persona?->nombre_completo,
            self::EQUIPO => "{$this->proveedor?->persona?->nombre_completo} (dueño de {$this->equipo?->nombre})",
            default => 'Clínica',
        };
    }

    public function porcentajeTexto(): string
    {
        return Porcentaje::texto($this->porcentaje);
    }
}
