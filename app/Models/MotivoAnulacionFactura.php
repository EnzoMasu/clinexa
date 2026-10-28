<?php

namespace App\Models;

use App\Exceptions\AccionRechazada;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/**
 * Motivo de anulación de una factura (docs/facturacion.md): catálogo editable, nada se borra (se desactiva). Los de referencia
 * (Database\Seeders\MotivosAnulacionFacturaSeeder) tienen un código fijo. `requiere_detalle`: "Otro (detallar)" pide el detalle.
 */
class MotivoAnulacionFactura extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'motivos_anulacion_factura';

    protected $fillable = ['nombre', 'requiere_detalle', 'estado_id'];

    public static function moduloEstado(): string
    {
        return 'MOTIVOS_ANULACION';
    }

    protected function casts(): array
    {
        return ['requiere_detalle' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new AccionRechazada('Un motivo de anulación no se borra: se desactiva.'));
    }
}
