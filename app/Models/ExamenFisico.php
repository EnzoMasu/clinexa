<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Examen físico de una consulta (como mucho uno). Todos los campos son opcionales; si no se cargó
 * ninguno no se crea la fila. Sus cambios quedan en la auditoría como EDITAR de la consulta.
 */
class ExamenFisico extends Model
{
    use Auditable;

    protected $table = 'examenes_fisicos';

    /** Campo => [etiqueta, unidad], en el orden del formulario. */
    public const CAMPOS = [
        'presion_arterial' => ['Presión arterial', 'mmHg'],
        'frecuencia_cardiaca' => ['Frecuencia cardíaca', 'lpm'],
        'frecuencia_respiratoria' => ['Frecuencia respiratoria', 'rpm'],
        'temperatura' => ['Temperatura', '°C'],
        'peso' => ['Peso', 'kg'],
        'talla' => ['Talla', 'cm'],
        'saturacion_oxigeno' => ['Saturación de oxígeno', '%'],
        'hallazgos' => ['Hallazgos', null],
    ];

    protected $fillable = [
        'consulta_id',
        'presion_arterial',
        'frecuencia_cardiaca',
        'frecuencia_respiratoria',
        'temperatura',
        'peso',
        'talla',
        'saturacion_oxigeno',
        'hallazgos',
    ];

    protected function casts(): array
    {
        return [
            'frecuencia_cardiaca' => 'integer',
            'frecuencia_respiratoria' => 'integer',
            'saturacion_oxigeno' => 'integer',
            'temperatura' => 'decimal:1',
            'peso' => 'decimal:2',
            'talla' => 'decimal:1',
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

    /** Un campo para mostrar: "36,5 °C", "120/80 mmHg"; null si no se cargó. */
    public function valorTexto(string $campo): ?string
    {
        $valor = $this->{$campo};
        if ($valor === null || $valor === '') {
            return null;
        }

        $unidad = self::CAMPOS[$campo][1];
        $texto = is_string($valor) && is_numeric($valor) && str_contains($valor, '.')
            ? rtrim(rtrim(str_replace('.', ',', $valor), '0'), ',') // 36.50 -> 36,5 ; 70.00 -> 70
            : (string) $valor;

        return $unidad ? "{$texto} {$unidad}" : $texto;
    }

    /** Para el log: ["Presión arterial: 120/80 mmHg", "Temperatura: 36,5 °C", ...], solo lo cargado. */
    public function descripcion(): array
    {
        return collect(array_keys(self::CAMPOS))
            ->map(fn (string $campo) => ($texto = $this->valorTexto($campo)) === null ? null : self::CAMPOS[$campo][0].': '.$texto)
            ->filter()->values()->all();
    }
}
