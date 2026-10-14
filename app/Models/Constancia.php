<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\ConstanciaInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Base de las constancias (retiro y estado del turno). Documento inmutable, como la receta pero sin borrador:
 *
 * - EMITIDO: nace con número, fecha, snapshot (todo lo que se imprime) y huella sha256 del snapshot. No se modifica.
 * - ANULADO: con fecha y motivo; se conserva y se ve. Es lo único que se le puede hacer a una emitida.
 *
 * El modelo rechaza cualquier otro cambio (ConstanciaInmutable), también desde código. Nada se borra. Cada tipo
 * (ConstanciaRetiro, ConstanciaEstado) tiene su tabla, su prefijo de número y su módulo de permisos.
 * Ver docs/constancias.md.
 */
abstract class Constancia extends Model
{
    use Auditable, TieneEstado;

    public const DIGITOS_NUMERO = 7;

    /** Lo único que cambia al anular. */
    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulada_en', 'motivo_anulacion', 'updated_at'];

    public const ETIQUETAS = [Estado::EMITIDO => 'Emitida', Estado::ANULADO => 'Anulada'];

    protected $fillable = ['numero', 'turno_id', 'fecha', 'estado_id', 'emitida_en', 'snapshot', 'huella', 'usuario_id'];

    /** Prefijo del número: "CR-" o "CE-". */
    abstract public static function prefijo(): string;

    /** Texto del tipo para pantallas y mensajes: "Constancia de retiro". */
    abstract public static function nombreDelTipo(): string;

    /** Por qué este turno no admite una constancia de este tipo, o null si la admite. */
    abstract public static function motivoDeNoAplicar(Turno $turno): ?string;

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'emitida_en' => 'datetime',
            'anulada_en' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Constancia $constancia) {
            $desde = Estado::find($constancia->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($constancia->estado_id)?->codigo;

            if ($desde !== Estado::EMITIDO || $hacia !== Estado::ANULADO) {
                throw $desde === $hacia ? ConstanciaInmutable::contenido() : ConstanciaInmutable::transicion();
            }
            if (array_diff(array_keys($constancia->getDirty()), self::CAMPOS_DE_ANULACION) !== []) {
                throw ConstanciaInmutable::contenido();
            }
        });

        static::deleting(fn () => throw ConstanciaInmutable::borrado());
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

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function estaEmitida(): bool
    {
        return $this->tieneEstado(Estado::EMITIDO);
    }

    public function estaAnulada(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    /** sha256 del snapshot en forma canónica (claves ordenadas): igual en SQLite y en PostgreSQL (jsonb reordena claves). */
    public static function calcularHuella(array $snapshot): string
    {
        return hash('sha256', json_encode(self::ordenar($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Si el snapshot guardado sigue dando la huella guardada. */
    public function huellaValida(): bool
    {
        return hash_equals((string) $this->huella, self::calcularHuella((array) $this->snapshot));
    }

    /** El número que tendría la próxima constancia de este tipo: el mayor + 1 (ancho fijo: el orden alfabético es el numérico). */
    public static function siguienteNumero(): string
    {
        $mayor = static::query()->withoutEagerLoads()->max('numero');
        $siguiente = $mayor ? (int) substr($mayor, strlen(static::prefijo())) + 1 : 1;

        return static::prefijo().str_pad((string) $siguiente, self::DIGITOS_NUMERO, '0', STR_PAD_LEFT);
    }

    private static function ordenar(array $valor): array
    {
        foreach ($valor as $clave => $item) {
            if (is_array($item)) {
                $valor[$clave] = self::ordenar($item);
            }
        }
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }
}
