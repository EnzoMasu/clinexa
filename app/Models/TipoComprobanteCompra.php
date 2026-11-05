<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de comprobante de una compra (docs/compras-gastos.md): catálogo editable, activo o inactivo, nunca se borra. Sus casillas dicen
 * qué exige la compra: número (y con formato legal 000-000-0000000), timbrado, fecha del comprobante y si discrimina IVA. Las reglas
 * de Compras miran SOLO estas casillas, nunca el nombre ni el id. Uno inactivo no se elige en compras nuevas; las existentes lo
 * conservan.
 */
class TipoComprobanteCompra extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'tipos_comprobante_compra';

    /** Las casillas de reglas, en el orden del formulario. */
    public const CASILLAS = [
        'requiere_numero' => 'Requiere número',
        'numero_formato_legal' => 'Número con formato legal (000-000-0000000)',
        'requiere_timbrado' => 'Requiere timbrado',
        'requiere_fecha_comprobante' => 'Requiere fecha del comprobante',
        'discrimina_iva' => 'Discrimina IVA (10 %, 5 % y exento)',
    ];

    protected $fillable = ['nombre', 'requiere_numero', 'numero_formato_legal', 'requiere_timbrado', 'requiere_fecha_comprobante',
        'discrimina_iva', 'estado_id'];

    public static function moduloEstado(): string
    {
        return 'TIPOS_COMPROBANTE_COMPRA';
    }

    protected function casts(): array
    {
        return [
            'requiere_numero' => 'boolean',
            'numero_formato_legal' => 'boolean',
            'requiere_timbrado' => 'boolean',
            'requiere_fecha_comprobante' => 'boolean',
            'discrimina_iva' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }
}
