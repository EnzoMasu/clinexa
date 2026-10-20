<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ProtegidoPorCierre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Informe de un estudio hecho en la clínica (docs/informes-estudio.md), parte de la consulta: texto libre, el estudio
 * (del catálogo o escrito a mano; el nombre se copia en estudio_nombre) y el solicitante opcional. No se borra: se retira
 * (activo = false) y se puede reponer. Sus cambios quedan en la auditoría como EDITAR de la consulta.
 */
class InformeEstudio extends Model
{
    use Auditable, ProtegidoPorCierre;

    public const MAXIMO_TEXTO = 5000;

    public const MAXIMO_SOLICITANTE = 150;

    protected $table = 'informes_estudio';

    protected $fillable = [
        'consulta_id',
        'estudio_id',
        'estudio_nombre',
        'solicitante',
        'texto',
        'orden',
        'activo',
        'usuario_id',
        'modificado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public static function moduloAuditoria(): string
    {
        return 'HISTORIA_CLINICA';
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class);
    }

    /** Quién lo escribió. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** El último que lo cambió (si no fue quien lo escribió). */
    public function modificadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modificado_por_id');
    }

    /** Para el log: "Ecografía (solicitante: Dr. X): texto", y " (retirado)" si está retirado. */
    public function descripcionAuditoria(): string
    {
        return $this->estudio_nombre.($this->solicitante ? " (solicitante: {$this->solicitante})" : '').": {$this->texto}".($this->activo ? '' : ' (retirado)');
    }

    /** Regla de cierre (ProtegidoPorCierre): la consulta de la que cuelga, leída de la base. */
    protected function consultaDeCierre(): ?Consulta
    {
        return $this->consulta_id ? Consulta::query()->select(['id', 'estado_id'])->find($this->consulta_id) : null;
    }
}
