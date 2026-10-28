<?php

namespace App\Models;

use App\Exceptions\AccionRechazada;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La clínica como contribuyente emisor de las facturas (docs/facturacion.md): una sola fila por instalación (`clinicas_una_sola`).
 * No es una persona jurídica: puede ser UNIPERSONAL (una persona física con RUC) o una SOCIEDAD. Las sucursales apuntan a ella; su
 * dirección y teléfono son los del establecimiento. La factura copia la razón social, el RUC y la dirección al emitirse. Se edita
 * (Gerencia y el Administrador), no se borra.
 */
class Clinica extends Model
{
    use Auditable;

    protected $table = 'clinicas';

    public const UNIPERSONAL = 'UNIPERSONAL';

    public const SOCIEDAD = 'SOCIEDAD';

    public const TIPOS = [self::UNIPERSONAL => 'Unipersonal (persona física con RUC)', self::SOCIEDAD => 'Sociedad'];

    protected $fillable = ['tipo_contribuyente', 'razon_social', 'nombre_fantasia', 'ruc', 'actividad_economica', 'direccion_fiscal',
        'ciudad_id', 'telefono', 'correo', 'logotipo'];

    /** Sin estados propios: su auditoría es la de los datos fiscales. */
    public static function moduloAuditoria(): string
    {
        return 'DATOS_FISCALES';
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new AccionRechazada('Los datos de la clínica no se borran.'));
    }

    /** La de esta instalación, o null si todavía no se cargó. */
    public static function actual(): ?self
    {
        return self::query()->orderBy('id')->first();
    }

    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class);
    }

    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class);
    }

    /** "Plenitud Mujer (Clínica Plenitud S.A.)": el nombre de fantasía, si lo tiene, con la razón social. */
    public function nombreVisible(): string
    {
        return filled($this->nombre_fantasia) ? "{$this->nombre_fantasia} ({$this->razon_social})" : (string) $this->razon_social;
    }

    /** Ruta absoluta del logotipo para los PDF: el cargado o, si no hay, el de config/clinexa.php. */
    public static function rutaLogotipo(?self $clinica): string
    {
        if ($clinica?->logotipo && is_file($ruta = storage_path('app/private/'.$clinica->logotipo))) {
            return $ruta;
        }

        return public_path(config('clinexa.clinica.logo'));
    }

    /** Lo que falta para emitir (aviso), o null si están los datos fiscales. */
    public static function faltaParaEmitir(?self $clinica): ?string
    {
        return match (true) {
            $clinica === null => 'Faltan los datos fiscales de la clínica: la factura no se puede emitir. Gerencia o el Administrador los cargan en Sucursales → Datos de la clínica.',
            blank($clinica->ruc) || blank($clinica->razon_social) || blank($clinica->direccion_fiscal) || blank($clinica->actividad_economica) => 'Los datos fiscales de la clínica están incompletos (razón social, RUC, actividad o dirección fiscal): la factura no se puede emitir.',
            default => null,
        };
    }

    /** Para el nombre en orden natural de un UNIPERSONAL no hace falta nada: la razón social es el nombre. */
    public function etiquetaTipo(): string
    {
        return self::TIPOS[$this->tipo_contribuyente] ?? (string) $this->tipo_contribuyente;
    }
}
