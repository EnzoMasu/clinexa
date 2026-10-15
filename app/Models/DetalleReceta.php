<?php

namespace App\Models;

use App\Exceptions\RecetaInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\ProtegidoPorCierre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de medicamento de una receta, en texto libre (no hay catálogo): el medicamento y la cantidad
 * salen en la mitad de arriba del PDF; la indicación de uso (obligatoria para emitir), en la de abajo.
 * Las columnas dosis, vía, frecuencia, duración y observaciones son de las recetas anteriores (se
 * siguen imprimiendo desde su snapshot) y ya no se cargan. Solo se crea, cambia o quita mientras la receta es un BORRADOR (la única excepción a "no
 * se borra nada"); en una receta emitida o anulada el modelo lo rechaza. Sus cambios quedan en la
 * auditoría como EDITAR de la receta.
 */
class DetalleReceta extends Model
{
    use Auditable, ProtegidoPorCierre;

    protected $table = 'detalles_receta';

    /** Campo => etiqueta, en el orden del formulario. */
    public const CAMPOS = [
        'medicamento' => 'Medicamento',
        'cantidad' => 'Cantidad',
        'indicacion_uso' => 'Indicación de uso',
    ];

    /** Largo máximo de la indicación de uso. */
    public const MAXIMO_INDICACION = 500;

    protected $fillable = [
        'receta_id',
        'medicamento',
        'cantidad',
        'dosis',
        'via',
        'frecuencia',
        'duracion',
        'observaciones',
        'indicacion_uso',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Inmutabilidad: los renglones de una receta que ya no es borrador no se tocan.
        foreach (['creating', 'updating', 'deleting'] as $evento) {
            static::$evento(function (DetalleReceta $detalle) {
                $receta = Receta::find($detalle->receta_id);
                if ($receta && ! $receta->esBorrador()) {
                    throw RecetaInmutable::renglones();
                }
            });
        }
    }

    public static function moduloAuditoria(): string
    {
        return 'RECETAS';
    }

    public function receta(): BelongsTo
    {
        return $this->belongsTo(Receta::class);
    }

    /** Para el log: "Amoxicilina 500 mg · caja x 21 · 1 comprimido cada 8 horas por 7 días" (solo lo cargado). */
    public function descripcionAuditoria(): string
    {
        return collect(array_keys(self::CAMPOS))->map(fn (string $campo) => $this->{$campo})->filter(fn ($valor) => filled($valor))->join(' · ');
    }

    /** Regla de cierre (ProtegidoPorCierre): la consulta de su receta, leída de la base. */
    protected function consultaDeCierre(): ?Consulta
    {
        $consultaId = Receta::query()->whereKey($this->receta_id)->value('consulta_id');

        return $consultaId ? Consulta::query()->select(['id', 'estado_id'])->find($consultaId) : null;
    }

    /** "Quitar" un renglón: solo de una receta en BORRADOR (el único borrado permitido en la historia clínica). */
    protected function sePuedeBorrar(): bool
    {
        return (bool) Receta::find($this->receta_id)?->esBorrador();
    }
}
