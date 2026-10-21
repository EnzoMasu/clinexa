<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un cobro (docs/cobros-caja.md): en una caja abierta, a una paciente, siempre completo. El total es la suma exacta de las
 * prestaciones que cubre (CobroPrestacion) y la de sus líneas de pago (LineaCobro); cada línea es un movimiento de la caja. Sin
 * vuelto, saldos ni pagos parciales. VIGENTE; una vez hecho no se edita ni se borra (la reversión es de la fase 3b).
 */
class Cobro extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'cobros';

    protected $fillable = ['caja_id', 'paciente_id', 'cobrado_en', 'total', 'moneda_id', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'COBROS';
    }

    protected function casts(): array
    {
        return ['cobrado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw RegistroEconomicoInmutable::cobro());
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function cajero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function prestaciones(): HasMany
    {
        return $this->hasMany(CobroPrestacion::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(LineaCobro::class);
    }

    public function totalTexto(): string
    {
        return $this->moneda->formatear($this->total);
    }
}
