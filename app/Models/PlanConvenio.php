<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plan de un convenio (docs/convenios-seguros.md): SOLO un nombre, sin reglas de cobertura ni copago. ACTIVO o INACTIVO (nada se
 * borra, se desactiva). Un plan inactivo no se ofrece al cargar una cobertura; las coberturas ya cargadas no cambian.
 */
class PlanConvenio extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'planes_convenio';

    protected $fillable = ['convenio_id', 'nombre', 'estado_id'];

    /** Sin módulo de estados propio: ACTIVO e INACTIVO (Estado::delModulo). */
    public static function moduloEstado(): string
    {
        return 'PLANES_CONVENIO';
    }

    /** Su auditoría es la de los convenios. */
    public static function moduloAuditoria(): string
    {
        return 'CONVENIOS';
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    /** "Aseguradora — Convenio — Plan". */
    public function nombreCompleto(): string
    {
        return $this->convenio->nombreCompleto().' — '.$this->nombre;
    }
}
