<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\ReposoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Certificado de reposo médico (docs/reposo-medico.md). Documento aparte de la consulta, inmutable como las constancias:
 *
 * - EMITIDO: nace con número (RM-0000001), fecha, snapshot (todo lo que se imprime) y huella sha256 del snapshot.
 * - ANULADO: con fecha y motivo; se conserva y se ve. Es lo único que se le puede hacer a uno emitido.
 *
 * El modelo rechaza cualquier otro cambio (ReposoInmutable), también desde código. Nada se borra.
 */
class ReposoMedico extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'reposos_medicos';

    public const PREFIJO = 'RM-';

    public const DIGITOS_NUMERO = 7;

    /** Lo único que cambia al anular. */
    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulada_en', 'motivo_anulacion', 'updated_at'];

    public const ETIQUETAS = [Estado::EMITIDO => 'Emitido', Estado::ANULADO => 'Anulado'];

    protected $fillable = ['numero', 'consulta_id', 'prorroga_de_id', 'fecha', 'dias', 'desde', 'hasta', 'estado_id', 'emitida_en', 'snapshot', 'huella', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'REPOSO_MEDICO';
    }

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'desde' => 'date',
            'hasta' => 'date',
            'dias' => 'integer',
            'emitida_en' => 'datetime',
            'anulada_en' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ReposoMedico $reposo) {
            $desde = Estado::find($reposo->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($reposo->estado_id)?->codigo;

            if ($desde !== Estado::EMITIDO || $hacia !== Estado::ANULADO) {
                throw $desde === $hacia ? ReposoInmutable::contenido() : ReposoInmutable::transicion();
            }
            if (array_diff(array_keys($reposo->getDirty()), self::CAMPOS_DE_ANULACION) !== []) {
                throw ReposoInmutable::contenido();
            }
        });

        static::deleting(fn () => throw ReposoInmutable::borrado());
    }

    /** El snapshot no va al log (duplicaría el documento); queda la huella, que lo identifica. */
    public function camposNoAuditados(): array
    {
        return ['snapshot'];
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $accion === AccionAuditoria::ANULAR && $this->motivo_anulacion ? "Motivo: {$this->motivo_anulacion}" : null;
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function prorrogaDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prorroga_de_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function estaEmitido(): bool
    {
        return $this->tieneEstado(Estado::EMITIDO);
    }

    public function estaAnulado(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    /** Si el snapshot guardado sigue dando la huella guardada (misma forma canónica que las constancias). */
    public function huellaValida(): bool
    {
        return hash_equals((string) $this->huella, Constancia::calcularHuella((array) $this->snapshot));
    }

    /** El número que tendría el próximo certificado: el mayor + 1 (ancho fijo: el orden alfabético es el numérico). */
    public static function siguienteNumero(): string
    {
        $mayor = static::query()->withoutEagerLoads()->max('numero');
        $siguiente = $mayor ? (int) substr($mayor, strlen(self::PREFIJO)) + 1 : 1;

        return self::PREFIJO.str_pad((string) $siguiente, self::DIGITOS_NUMERO, '0', STR_PAD_LEFT);
    }
}
