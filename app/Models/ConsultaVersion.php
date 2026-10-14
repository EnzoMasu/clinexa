<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que tenía una consulta ANTES de una corrección autorizada (todas las secciones corregibles), numerado por
 * consulta. Inmutable. Ver App\Support\Correcciones\Instantanea.
 */
class ConsultaVersion extends Model
{
    use Auditable, Inmutable;

    protected $table = 'consulta_versiones';

    protected $fillable = ['consulta_id', 'numero', 'snapshot', 'desbloqueo_id', 'usuario_id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public static function moduloEstado(): string
    {
        return 'CORRECCIONES_HC';
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function desbloqueo(): BelongsTo
    {
        return $this->belongsTo(DesbloqueoConsulta::class, 'desbloqueo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
