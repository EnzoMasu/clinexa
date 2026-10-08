<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    use Auditable, EsRolDePersona, TieneEstado;

    protected $table = 'proveedores';

    protected $fillable = [
        'persona_id',
        'condiciones_comerciales',
        'datos_bancarios',
        'sitio_web',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'PROVEEDORES';
    }

    /**
     * Cómo se ven en la auditoría las listas que se editan en el formulario (EDITAR del proveedor,
     * con la lista de antes y la de después).
     */
    public function relacionesAuditadas(): array
    {
        return [
            // Rubros del proveedor (insumos médicos, equipos médicos, ...): puede tener varios.
            'categorias' => fn (Collection $categorias) => $categorias->pluck('nombre')->sort()->values()->all(),
            // "Ruiz, Liz — 0981 123 456 · liz@example.com (deshabilitado)", en el orden en que se cargaron.
            'contactos' => fn (Collection $contactos) => $contactos->sortBy('id')->map(fn (ContactoProveedor $contacto) => $contacto->descripcion())->values()->all(),
            // "Instagram: @clinica".
            'redesSociales' => fn (Collection $redes) => $redes->sortBy('id')->loadMissing('tipoRedSocial')
                ->map(fn (RedSocialProveedor $red) => "{$red->tipoRedSocial->nombre}: {$red->enlace}")->values()->all(),
        ];
    }

    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(CategoriaProveedor::class, 'proveedor_categoria', 'proveedor_id', 'categoria_proveedor_id');
    }

    /** Personas de contacto, activas y deshabilitadas (no se borran). Sin orden fijo: se usa en conteos. */
    public function contactos(): HasMany
    {
        return $this->hasMany(ContactoProveedor::class);
    }

    public function redesSociales(): HasMany
    {
        return $this->hasMany(RedSocialProveedor::class);
    }
}
