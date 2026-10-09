<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RecetaInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Receta de una consulta. Estados (códigos compartidos de la tabla estados):
 *
 * - PENDIENTE ("Borrador"): se edita libremente, incluso quitando renglones.
 * - EMITIDO ("Emitida"): tiene número, fecha y snapshot; no se modifica nunca. Se reimprime desde el
 *   snapshot, así sale idéntica aunque cambien los datos del paciente o del profesional.
 * - ANULADO ("Anulada"): con fecha y motivo; se conserva y se ve.
 *
 * Las transiciones válidas están solo en TRANSICIONES. El modelo rechaza cualquier otro cambio de una
 * receta emitida o anulada (RecetaInmutable), también si se toca directamente desde código.
 */
class Receta extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'recetas';

    /** Con microsegundos: updated_at es el control de concurrencia del borrador y de la vista previa. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** Prefijo y ancho del número: "RE-0000001". */
    public const PREFIJO_NUMERO = 'RE-';

    public const DIGITOS_NUMERO = 7;

    /** Estado actual => estados a los que puede pasar. Es el único lugar donde se definen. */
    public const TRANSICIONES = [
        Estado::PENDIENTE => [Estado::EMITIDO, Estado::ANULADO],
        Estado::EMITIDO => [Estado::ANULADO],
    ];

    /** Lo único que cambia al anular una receta emitida. */
    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulada_en', 'motivo_anulacion', 'updated_at'];

    /** Cómo se muestra cada estado en pantalla. */
    public const ETIQUETAS = [
        Estado::PENDIENTE => 'Borrador',
        Estado::EMITIDO => 'Emitida',
        Estado::ANULADO => 'Anulada',
    ];

    protected $fillable = [
        'consulta_id',
        'observaciones',
        'estado_id',
        'reemplaza_a_id',
    ];

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
        static::updating(function (Receta $receta) {
            $desde = Estado::find($receta->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($receta->estado_id)?->codigo;

            if ($desde !== $hacia && ! in_array($hacia, self::TRANSICIONES[$desde] ?? [], true)) {
                throw RecetaInmutable::transicion(self::ETIQUETAS[$desde] ?? (string) $desde, self::ETIQUETAS[$hacia] ?? (string) $hacia);
            }

            // Emitida o anulada: solo se permite anular una emitida (estado, fecha y motivo de anulación).
            if ($desde !== Estado::PENDIENTE) {
                $cambios = array_keys($receta->getDirty());
                if ($hacia !== Estado::ANULADO || $desde === Estado::ANULADO || array_diff($cambios, self::CAMPOS_DE_ANULACION) !== []) {
                    throw RecetaInmutable::contenido();
                }
            }
        });

        static::deleting(fn () => throw RecetaInmutable::borrado());
    }

    public static function moduloEstado(): string
    {
        return 'RECETAS';
    }

    /** El snapshot no va al log: duplicaría todo el contenido clínico de la hoja. */
    public function camposNoAuditados(): array
    {
        return ['snapshot'];
    }

    /** En el log, los renglones legibles: "Amoxicilina 500 mg · caja x 21 · 1 comprimido · cada 8 h · 7 días". */
    public function relacionesAuditadas(): array
    {
        return [
            'detalles' => fn (Collection $detalles) => $detalles->sortBy(['orden', 'id'])
                ->map(fn (DetalleReceta $detalle) => $detalle->descripcionAuditoria())->values()->all(),
        ];
    }

    /** Al anular, el motivo va en el detalle del evento ANULAR. */
    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $accion === AccionAuditoria::ANULAR && $this->motivo_anulacion ? "Motivo: {$this->motivo_anulacion}" : null;
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    /** Los renglones, sin orden fijo: ordenar al mostrar. */
    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleReceta::class);
    }

    /** La receta anulada a la que esta reemplaza ("Anular y corregir"). */
    public function reemplazaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reemplaza_a_id');
    }

    public function esBorrador(): bool
    {
        return $this->tieneEstado(Estado::PENDIENTE);
    }

    public function estaEmitida(): bool
    {
        return $this->tieneEstado(Estado::EMITIDO);
    }

    public function estaAnulada(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function puedePasarA(string $estado): bool
    {
        return in_array($estado, self::TRANSICIONES[$this->estado?->codigo] ?? [], true);
    }

    /** "Borrador", "Emitida" o "Anulada". */
    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    /** updated_at como se manda en el campo oculto del formulario y de la vista previa. */
    public function version(): string
    {
        return $this->updated_at?->format('Y-m-d H:i:s.u') ?? '';
    }

    /**
     * El número que tendría la próxima receta emitida: el mayor RE- + 1. Los números tienen ancho fijo,
     * así que el orden alfabético es el numérico. Si dos emisiones chocan, el unique de la base rechaza
     * la segunda y la emisión reintenta (ver EmisionReceta).
     */
    public static function siguienteNumero(): string
    {
        $mayor = self::query()->withoutEagerLoads()->where('numero', 'like', self::PREFIJO_NUMERO.'%')->max('numero');
        $siguiente = $mayor ? (int) substr($mayor, strlen(self::PREFIJO_NUMERO)) + 1 : 1;

        return self::PREFIJO_NUMERO.str_pad((string) $siguiente, self::DIGITOS_NUMERO, '0', STR_PAD_LEFT);
    }
}
