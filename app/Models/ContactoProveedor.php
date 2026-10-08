<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona de contacto de un proveedor (lista simple; no es una Persona del sistema). No se borra:
 * se deshabilita (activo = false). Sus cambios quedan en la auditoría como EDITAR del proveedor.
 */
class ContactoProveedor extends Model
{
    protected $table = 'contactos_proveedor';

    protected $fillable = [
        'proveedor_id',
        'nombre',
        'apellido',
        'telefono',
        'correo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /** "Ruiz, Liz — 0981 123 456 · liz@example.com" (y "(deshabilitado)" si corresponde). */
    public function descripcion(): string
    {
        $medios = implode(' · ', array_filter([$this->telefono, $this->correo]));

        return "{$this->apellido}, {$this->nombre}".($medios !== '' ? " — {$medios}" : '').($this->activo ? '' : ' (deshabilitado)');
    }
}
