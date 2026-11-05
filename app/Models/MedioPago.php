<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/**
 * Medio de pago: catálogo editable (nada se borra: se desactiva). Los de referencia (Database\Seeders\MediosPagoSeeder) tienen un
 * código fijo, que no se edita desde la pantalla: el monto inicial de la caja va en EFECTIVO (docs/cobros-caja.md). `es_efectivo`
 * marca el medio que sale del cajón físico (a lo sumo uno; lo pone el seeder en Efectivo): solo en él lo esperado y lo contado de
 * la caja no pueden quedar negativos. `requiere_comprobante` (nunca en el efectivo) exige el número de comprobante al cobrar; `nombre_comprobante`
 * dice cómo se llama (docs/cobros-caja.md).
 */
class MedioPago extends Model
{
    use Auditable, TieneEstado;

    public const EFECTIVO = 'EFECTIVO';

    public const TRANSFERENCIA = 'TRANSFERENCIA';

    public const TARJETA = 'TARJETA';

    public const DEPOSITO = 'DEPOSITO';

    public const CHEQUE = 'CHEQUE';

    protected $table = 'medios_pago';

    protected $fillable = [
        'nombre',
        'estado_id',
        'requiere_comprobante',
        'nombre_comprobante',
    ];

    public static function moduloEstado(): string
    {
        return 'MEDIOS_PAGO';
    }

    protected function casts(): array
    {
        return ['es_efectivo' => 'boolean', 'requiere_comprobante' => 'boolean'];
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

    /** Si al cobrar con este medio hay que cargar el número de comprobante (nunca el efectivo). */
    public function exigeComprobante(): bool
    {
        return ! $this->es_efectivo && (bool) $this->requiere_comprobante;
    }

    /** Cómo se llama el comprobante de este medio ("Voucher del POS"); "Comprobante" si no se le puso nombre. */
    public function etiquetaComprobante(): string
    {
        return trim((string) $this->nombre_comprobante) !== '' ? $this->nombre_comprobante : 'Comprobante';
    }

    /** El mensaje cuando falta el comprobante de una línea de este medio. */
    public function mensajeComprobanteFaltante(): string
    {
        return "Indique el comprobante del pago con {$this->nombre} ({$this->etiquetaComprobante()}).";
    }
}
