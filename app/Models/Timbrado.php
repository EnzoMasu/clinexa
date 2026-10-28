<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un timbrado (simulado, docs/facturacion.md): número, vigencia, establecimiento y punto de expedición de una sucursal de la clínica,
 * con su rango de numeración. VIGENTE (sin `historico_en`; uno solo por sucursal, establecimiento y punto: `timbrados_uno_vigente`)
 * o HISTORICO. La numeración la avanza Facturacion\EmitirFactura, bajo bloqueo, de a uno: sin saltos ni repetidos (sin evento
 * propio en Auditoría: el número queda en cada factura). Vencido (por la
 * fecha) o agotado (llegó al número final) no emite. El modelo solo admite avanzar `numero_actual` de a uno y pasar a histórico; no
 * se borra.
 */
class Timbrado extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'timbrados';

    public const ETIQUETAS = [Estado::VIGENTE => 'Vigente', Estado::HISTORICO => 'Histórico'];

    private const CAMPOS_DE_HISTORICO = ['estado_id', 'historico_en', 'historico_por', 'updated_at'];

    protected $fillable = ['clinica_id', 'sucursal_id', 'numero', 'vigente_desde', 'vigente_hasta', 'establecimiento', 'punto_expedicion',
        'numero_inicial', 'numero_final', 'numero_actual', 'cargado_por'];

    public static function moduloEstado(): string
    {
        return 'TIMBRADOS';
    }

    protected function casts(): array
    {
        return ['vigente_desde' => 'date', 'vigente_hasta' => 'date', 'historico_en' => 'datetime',
            'numero_inicial' => 'integer', 'numero_final' => 'integer', 'numero_actual' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (Timbrado $timbrado) {
            $cambios = array_keys($timbrado->getDirty());
            $avanza = array_diff($cambios, ['numero_actual', 'updated_at']) === []
                && (int) $timbrado->numero_actual === (int) $timbrado->getOriginal('numero_actual') + 1
                && (int) $timbrado->numero_actual <= (int) $timbrado->numero_final;
            $pasaAHistorico = $timbrado->getOriginal('historico_en') === null && $timbrado->historico_en !== null && $timbrado->historico_por !== null
                && Estado::find($timbrado->estado_id)?->codigo === Estado::HISTORICO && array_diff($cambios, self::CAMPOS_DE_HISTORICO) === [];
            if (! $avanza && ! $pasaAHistorico) {
                throw RegistroEconomicoInmutable::factura();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('timbrados.historico_en');
    }

    public function clinica(): BelongsTo
    {
        return $this->belongsTo(Clinica::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cargado_por');
    }

    public function historicoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'historico_por');
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    public function historico(): bool
    {
        return $this->historico_en !== null;
    }

    public function agotado(): bool
    {
        return $this->numero_actual >= $this->numero_final;
    }

    /** Si el día está dentro de su vigencia (desde y hasta, inclusive). */
    public function vigenteEl(\DateTimeInterface $dia): bool
    {
        $iso = $dia->format('Y-m-d');

        return $this->vigente_desde->format('Y-m-d') <= $iso && $iso <= $this->vigente_hasta->format('Y-m-d');
    }

    /** Cuántos números quedan. */
    public function disponibles(): int
    {
        return max(0, $this->numero_final - $this->numero_actual);
    }

    /** "001-001": establecimiento y punto de expedición. */
    public function punto(): string
    {
        return "{$this->establecimiento}-{$this->punto_expedicion}";
    }

    public static function numeroCompleto(string $establecimiento, string $punto, int $numero): string
    {
        return $establecimiento.'-'.$punto.'-'.str_pad((string) $numero, 7, '0', STR_PAD_LEFT);
    }

    public function vigenciaTexto(): string
    {
        return Fecha::mostrar($this->vigente_desde).' al '.Fecha::mostrar($this->vigente_hasta);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }
}
