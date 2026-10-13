<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ventana de corrección de una consulta cerrada: de un solo uso, vigente 24 h, una por consulta a la vez, con
 * motivo. Estados (catálogo compartido): VIGENTE, REALIZADO (se usó), VENCIDO y CANCELADO. Nunca se borra.
 */
class DesbloqueoConsulta extends Model
{
    use Auditable, TieneEstado;

    public const HORAS_DE_VIGENCIA = 24;

    public const MOTIVO_MINIMO = 20;

    public const MOTIVO_MAXIMO = 500;

    protected $table = 'desbloqueos_consulta';

    protected $fillable = ['consulta_id', 'motivo', 'usuario_id', 'vence_en'];

    protected function casts(): array
    {
        return ['vence_en' => 'datetime', 'usado_en' => 'datetime'];
    }

    public static function moduloEstado(): string
    {
        return 'CORRECCIONES_HC';
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function estaVigente(): bool
    {
        return $this->tieneEstado(Estado::VIGENTE) && $this->vence_en->isFuture();
    }

    /** Pasa a VENCIDO los VIGENTES cuyo plazo terminó (de esta consulta, o de todas). Devuelve cuántos. */
    public static function vencerLosVencidos(?int $consultaId = null): int
    {
        $vencidos = self::query()->where('estado_id', Estado::idDe(Estado::VIGENTE))->where('vence_en', '<=', now())
            ->when($consultaId, fn ($query) => $query->where('consulta_id', $consultaId))->get();
        foreach ($vencidos as $desbloqueo) {
            \App\Support\Auditoria::conDetalle('Desbloqueo vencido', fn () => $desbloqueo->forceFill(['estado_id' => Estado::idDe(Estado::VENCIDO)])->save());
        }

        return $vencidos->count();
    }

    /** El desbloqueo VIGENTE de la consulta (vence los que corresponda antes), o null. */
    public static function vigenteDe(int $consultaId): ?self
    {
        self::vencerLosVencidos($consultaId);

        return self::query()->where('consulta_id', $consultaId)->where('estado_id', Estado::idDe(Estado::VIGENTE))->first();
    }
}
