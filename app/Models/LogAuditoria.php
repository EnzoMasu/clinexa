<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Models\Builders\ConsultaLogAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un evento de auditoría. Solo se agrega: no se edita ni se borra (en PostgreSQL además lo
 * impide un trigger). Se escribe con App\Support\Auditoria.
 */
class LogAuditoria extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'logs_auditoria';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'tabla_afectada',
        'registro_afectado_id',
        'accion',
        'valor_anterior',
        'valor_nuevo',
        'detalle',
        'ip_origen',
        'fecha_hora',
    ];

    protected function casts(): array
    {
        return [
            'accion' => AccionAuditoria::class,
            'valor_anterior' => 'array',
            'valor_nuevo' => 'array',
            'fecha_hora' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $inmutable = fn () => throw new LogicException('El log de auditoría no se puede modificar ni borrar.');

        static::updating($inmutable);
        static::deleting($inmutable);
    }

    /** Tampoco en masa (update/delete/truncate sobre una consulta). */
    public function newEloquentBuilder($query): ConsultaLogAuditoria
    {
        return new ConsultaLogAuditoria($query);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
