<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * La caja de un cajero (docs/cobros-caja.md): se abre con un monto inicial en efectivo y recibe cobros solo mientras está ABIERTA
 * y en el día de su apertura. Una sola abierta por cajero (`cajas_una_abierta_por_cajero`: abierta = sin `cerrada_en`).
 *
 * - ABIERTO -> CERRADO: el cajero declara lo contado por medio (CierreCaja). Queda pendiente de aprobación.
 * - CERRADO -> APROBADO o DEVUELTO: Gerencia. La devuelta solo se vuelve a cerrar (DEVUELTO -> CERRADO), sin recibir cobros.
 *
 * El modelo rechaza cualquier otro cambio, y el borrado.
 */
class Caja extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'cajas';

    public const ETIQUETAS = [
        Estado::ABIERTO => 'Abierta',
        Estado::CERRADO => 'Cerrada (pendiente de aprobación)',
        Estado::APROBADO => 'Aprobada',
        Estado::DEVUELTO => 'Devuelta para corregir',
    ];

    private const CAMPOS_DE_CIERRE = ['estado_id', 'cerrada_en', 'updated_at'];

    private const CAMPOS_DE_REVISION = ['estado_id', 'updated_at'];

    protected $fillable = ['usuario_id', 'fecha', 'abierta_en', 'monto_inicial', 'moneda_id'];

    public static function moduloEstado(): string
    {
        return 'CAJAS';
    }

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'abierta_en' => 'datetime',
            'cerrada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Caja $caja) {
            $desde = Estado::find($caja->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($caja->estado_id)?->codigo;
            $permitidos = match (true) {
                in_array($desde, [Estado::ABIERTO, Estado::DEVUELTO], true) && $hacia === Estado::CERRADO && $caja->cerrada_en !== null => self::CAMPOS_DE_CIERRE,
                $desde === Estado::CERRADO && in_array($hacia, [Estado::APROBADO, Estado::DEVUELTO], true) => self::CAMPOS_DE_REVISION,
                default => null,
            };
            if ($permitidos === null || array_diff(array_keys($caja->getDirty()), $permitidos) !== []) {
                throw RegistroEconomicoInmutable::caja();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return match (Estado::find($this->estado_id)?->codigo) {
            Estado::CERRADO => 'Cierre de caja',
            Estado::APROBADO => 'Cierre aprobado',
            Estado::DEVUELTO => 'Cierre devuelto',
            default => null,
        };
    }

    public function cajero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class);
    }

    public function cobros(): HasMany
    {
        return $this->hasMany(Cobro::class);
    }

    public function cierres(): HasMany
    {
        return $this->hasMany(CierreCaja::class);
    }

    /** El último cierre declarado (el que se aprueba o se devuelve). */
    public function ultimoCierre(): HasOne
    {
        return $this->hasOne(CierreCaja::class)->latestOfMany();
    }

    /** La caja abierta del cajero, si tiene. */
    public function scopeAbiertaDe(Builder $query, User $usuario): void
    {
        $query->where('usuario_id', $usuario->id)->whereNull('cerrada_en');
    }

    public function abierta(): bool
    {
        return $this->tieneEstado(Estado::ABIERTO);
    }

    public function cerrada(): bool
    {
        return $this->tieneEstado(Estado::CERRADO);
    }

    public function devuelta(): bool
    {
        return $this->tieneEstado(Estado::DEVUELTO);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    public function montoInicialTexto(): string
    {
        return $this->moneda->formatear($this->monto_inicial);
    }

    /**
     * Lo esperado por medio de pago: la suma de los movimientos (el monto inicial solo en efectivo, más los cobros), medio => monto
     * en guaraníes enteros.
     *
     * @return array<int, int>
     */
    public function esperadoPorMedio(): array
    {
        return $this->movimientos()->selectRaw('medio_pago_id, SUM(monto) AS total')->groupBy('medio_pago_id')->pluck('total', 'medio_pago_id')
            ->map(fn ($total) => (int) round((float) $total))->all();
    }
}
