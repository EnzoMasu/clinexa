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

    /** Sigla de cada signo vital para las pastillas de la consulta ("FC 88 lpm"). */
    public const SIGLAS = [
        'presion_arterial' => 'PA',
        'frecuencia_cardiaca' => 'FC',
        'frecuencia_respiratoria' => 'FR',
        'temperatura' => 'T',
        'peso' => 'Peso',
        'talla' => 'Talla',
        'saturacion_oxigeno' => 'SatO₂',
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
        'signos_usuario_id',
        'hallazgos_usuario_id',
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

    /** Quién cargó (o cambió por última vez) los signos vitales. */
    public function signosUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signos_usuario_id');
    }

    /** Quién cargó (o cambió por última vez) los hallazgos. */
    public function hallazgosUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hallazgos_usuario_id');
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
    /** Para el log: nombre del campo => "Nombre: valor con unidad", solo los cargados. */
    public function descripcionPorCampo(): array
    {
        return collect(array_keys(self::CAMPOS))
            ->mapWithKeys(fn (string $campo) => [self::CAMPOS[$campo][0] => $this->valorTexto($campo)])
            ->filter(fn ($texto) => $texto !== null)->all();
    }

    public function descripcion(): array
    {
        return collect(array_keys(self::CAMPOS))
            ->map(fn (string $campo) => ($texto = $this->valorTexto($campo)) === null ? null : self::CAMPOS[$campo][0].': '.$texto)
            ->filter()->values()->all();
    }
}
