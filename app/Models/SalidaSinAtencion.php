<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un turno cuyo paciente se fue sin ser atendido: RETIRO (se retiró) o RECHAZO (rechazó la atención). Lo registra
 * recepción, o el cierre de jornada del profesional. Sin contenido clínico: nada del triaje se guarda acá, salvo si
 * estaba terminado (de eso depende el cargo pendiente: solo una marca, sin monto, tarifa ni pagador). Nunca se
 * borra ni se modifica. Ver docs/retiros.md.
 */
class SalidaSinAtencion extends Model
{
    public const RETIRO = 'RETIRO';

    public const RECHAZO = 'RECHAZO';

    public const TIPOS = [self::RETIRO => 'Retiro', self::RECHAZO => 'Rechazo'];

    public const ORIGEN_RECEPCION = 'RECEPCION';

    public const ORIGEN_CIERRE_JORNADA = 'CIERRE_JORNADA';

    use Auditable;

    protected $table = 'salidas_sin_atencion';

    protected $fillable = ['turno_id', 'tipo', 'origen', 'triaje_terminado', 'cargo_pendiente', 'usuario_id'];

    protected function casts(): array
    {
        return ['triaje_terminado' => 'boolean', 'cargo_pendiente' => 'boolean'];
    }

    public static function moduloEstado(): string
    {
        return 'TURNOS';
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function nota(): HasOne
    {
        return $this->hasOne(NotaSalida::class, 'salida_id');
    }
}
