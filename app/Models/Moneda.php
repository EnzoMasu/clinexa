<?php

namespace App\Models;

use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/**
 * Moneda (ISO 4217). Catálogo de referencia, sin pantalla: la migración de las tarifas carga Guaraní (PYG), la única que
 * se usa. El modelo admite otras (decimales propios) para más adelante. Ver docs/tarifas-prestaciones.md.
 */
class Moneda extends Model
{
    use TieneEstado;

    public const GUARANI = 'PYG';

    protected $table = 'monedas';

    protected $fillable = ['codigo', 'nombre', 'simbolo', 'decimales', 'estado_id'];

    protected function casts(): array
    {
        return ['decimales' => 'integer'];
    }

    /** Sin módulo propio: ACTIVO e INACTIVO (Estado::delModulo). */
    public static function moduloEstado(): string
    {
        return 'MONEDAS';
    }

    public static function guarani(): self
    {
        return self::where('codigo', self::GUARANI)->sole();
    }

    /** "₲ 150.000" (separador de miles con punto, como se escribe en Paraguay). */
    public function formatear(string|int|float|null $monto): string
    {
        return $monto === null ? '' : $this->simbolo.' '.number_format((float) $monto, $this->decimales, ',', '.');
    }
}
