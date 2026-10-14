<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Constancia de retiro o rechazo: la emite Recepción sobre una salida sin atención. SIN contenido clínico: solo
 * paciente, fecha y hora, clínica y el hecho (ver App\Support\Constancias\DatosConstancia).
 */
class ConstanciaRetiro extends Constancia
{
    protected $table = 'constancias_retiro';

    protected $fillable = ['numero', 'turno_id', 'salida_id', 'fecha', 'estado_id', 'emitida_en', 'snapshot', 'huella', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'CONSTANCIAS_RETIRO';
    }

    public static function prefijo(): string
    {
        return 'CR-';
    }

    public static function nombreDelTipo(): string
    {
        return 'Constancia de retiro';
    }

    public static function motivoDeNoAplicar(Turno $turno): ?string
    {
        return $turno->tieneEstado(Estado::RETIRADO) && $turno->salida !== null
            ? null
            : 'La constancia de retiro es solo para turnos con un retiro o rechazo registrado.';
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaSinAtencion::class, 'salida_id');
    }
}
