<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/**
 * Medio de pago: catálogo editable (nada se borra: se desactiva). Los de referencia (Database\Seeders\MediosPagoSeeder) tienen un
 * código fijo, que no se edita desde la pantalla: el monto inicial de la caja va en EFECTIVO (docs/cobros-caja.md). `es_efectivo`
 * marca el medio que sale del cajón físico (a lo sumo uno; lo pone el seeder en Efectivo): solo en él lo esperado y lo contado de
 * la caja no pueden quedar negativos.
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

    protected function casts(): array
    {
        return ['es_efectivo' => 'boolean'];
    }

    /** El medio efectivo (el del monto inicial de la caja), o null si el catálogo no lo tiene. */
    public static function efectivo(): ?self
    {
        return self::where('es_efectivo', true)->first();
    }

    public function esEfectivo(): bool
    {
        return (bool) $this->es_efectivo;
    }
}
