<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/**
 * Medio de pago: catálogo editable (nada se borra: se desactiva). Los de referencia (Database\Seeders\MediosPagoSeeder) tienen un
 * código fijo, que no se edita desde la pantalla: el monto inicial de la caja va en EFECTIVO (docs/cobros-caja.md).
 */
class MedioPago extends Model
{
    use Auditable, TieneEstado;

    public const EFECTIVO = 'EFECTIVO';

    public const TRANSFERENCIA = 'TRANSFERENCIA';

    public const TARJETA = 'TARJETA';

    protected $table = 'medios_pago';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'MEDIOS_PAGO';
    }

    /** El medio Efectivo (el del monto inicial de la caja), o null si el catálogo no lo tiene. */
    public static function efectivo(): ?self
    {
        return self::where('codigo', self::EFECTIVO)->first();
    }

    public function esEfectivo(): bool
    {
        return $this->codigo === self::EFECTIVO;
    }
}
