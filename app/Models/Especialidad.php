<?php

namespace App\Models;

use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Especialidad extends Model
{
    use TieneEstado;

    protected $table = 'especialidades';

    protected $fillable = [
        'nombre',
        'descripcion',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'ESPECIALIDADES';
    }

    public function profesionales(): BelongsToMany
    {
        return $this->belongsToMany(Profesional::class, 'profesional_especialidad', 'especialidad_id', 'profesional_id')
            ->withPivot('nro_matricula_especialidad', 'fecha_desde');
    }
}
