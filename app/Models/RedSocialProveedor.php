<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Red social de un proveedor (empresa). El enlace puede ser una URL http(s), un @usuario o un
 * número (WhatsApp): solo una URL http(s) se muestra como enlace (App\Support\Enlace). Se puede
 * quitar al editar; el valor anterior queda en la auditoría (EDITAR del proveedor).
 */
class RedSocialProveedor extends Model
{
    protected $table = 'redes_sociales_proveedor';

    protected $fillable = [
        'proveedor_id',
        'tipo_red_social_id',
        'enlace',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function tipoRedSocial(): BelongsTo
    {
        return $this->belongsTo(TipoRedSocial::class);
    }
}
