<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota clínica del profesional sobre un retiro o rechazo: una sola vez, sin edición ni borrado. Es contenido clínico
 * (HISTORIA_CLINICA): recepción no la ve y Auditoría la oculta sin VER sobre la historia clínica.
 */
class NotaSalida extends Model
{
    use Auditable, Inmutable;

    public const TEXTO_MINIMO = 5;

    public const TEXTO_MAXIMO = 2000;

    protected $table = 'notas_salida';

    protected $fillable = ['salida_id', 'texto', 'profesional_id', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'HISTORIA_CLINICA';
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaSinAtencion::class, 'salida_id');
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }
}
