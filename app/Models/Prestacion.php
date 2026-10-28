<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Prestación: el servicio prestado (docs/tarifas-prestaciones.md). Nace al finalizar la consulta (Prestaciones\GenerarPrestacion):
 * la base (una por consulta) y una por cada informe de estudio (informe_estudio_id). Sin contenido clínico: paciente, profesional,
 * procedimiento, fecha de servicio, consulta, turno e informe (solo el vínculo).
 *
 * - PENDIENTE ("pendiente de tarifar"): sin monto; no había tarifa para ese día.
 * - TARIFADO: con monto, moneda y la tarifa usada, fijados una vez; no cambian aunque la tarifa cambie después.
 * - ANULADO: con motivo, fecha y quién; se conserva.
 *
 * Al fijar el monto guarda también el snapshot del pagador (docs/convenios-seguros.md): PACIENTE o ASEGURADORA, el responsable de
 * pago, convenio, plan y visación, la tarifa usada (la general o la del convenio) y la parte de cada uno, que suman exacto. Vacío en
 * las anteriores a los convenios: son particulares (la paciente paga el monto). Lo de la aseguradora queda "pendiente de liquidar".
 * Una tarifada solo cambia al "Aplicar visación" (recalcula el snapshot, con motivo) y con la firma recibida.
 *
 * El modelo rechaza cualquier otro cambio, y el borrado.
 */
class Prestacion extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'prestaciones';

    /** El origen, para mostrar y filtrar: la base de la consulta (o el turno) o un informe de estudio. */
    public const ORIGENES = ['CONSULTA' => 'Consulta/Turno', 'INFORME' => 'Informe de estudio'];

    public const ETIQUETAS = [Estado::PENDIENTE => 'Pendiente de tarifar', Estado::TARIFADO => 'Tarifada', Estado::ANULADO => 'Anulada'];

    /** Estado de cobro (docs/cobros-caja.md): derivado de si tiene un cobro vigente; no es un estado de la prestación. */
    public const COBRADA = 'Cobrada';

    public const PENDIENTE_DE_COBRO = 'Pendiente';

    /** Sin nada que cobrarle a la paciente: lo cubre todo la aseguradora. */
    public const A_CARGO_DE_ASEGURADORA = 'A cargo de aseguradora';

    public const PACIENTE = 'PACIENTE';

    public const ASEGURADORA = 'ASEGURADORA';

    public const PAGADORES = [self::PACIENTE => 'Particular (paciente)', self::ASEGURADORA => 'Aseguradora'];

    /**
     * Lo de la aseguradora, a liquidar. La marca no cambia al liquidarla: está liquidada si tiene una línea en una liquidación no
     * anulada (`lineaLiquidacion`, docs/liquidaciones-aseguradoras.md).
     */
    public const PENDIENTE_DE_LIQUIDAR = 'PENDIENTE';

    /** El snapshot del pagador (docs/convenios-seguros.md): se fija con el monto y solo cambia al aplicar una visación. */
    public const CAMPOS_DEL_PAGADOR = ['pagador', 'responsable_pago_id', 'convenio_id', 'plan_convenio_id', 'visacion_id', 'linea_visacion_id',
        'numero_visacion', 'tarifa_convenio_id', 'monto_paciente', 'monto_aseguradora', 'liquidacion', 'aviso_cobertura'];

    private const CAMPOS_DE_TARIFADO = ['estado_id', 'monto', 'moneda_id', 'tarifa_id', 'tarifada_en', 'tarifada_por', 'updated_at', ...self::CAMPOS_DEL_PAGADOR];

    private const CAMPOS_DE_FIRMA = ['firma_recibida_en', 'firma_recibida_por', 'updated_at'];

    /** "Aplicar visación": el monto, la tarifa y el snapshot, con quién, cuándo y por qué (y la firma vuelve a pedirse). */
    private const CAMPOS_DE_APLICACION = [...self::CAMPOS_DE_TARIFADO, 'visacion_aplicada_en', 'visacion_aplicada_por', 'motivo_aplicacion', 'firma_recibida_en', 'firma_recibida_por'];

    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulada_en', 'motivo_anulacion', 'anulada_por', 'updated_at'];

    protected $fillable = ['consulta_id', 'turno_id', 'informe_estudio_id', 'paciente_id', 'profesional_id', 'procedimiento_id', 'fecha_servicio'];

    public static function moduloEstado(): string
    {
        return 'PRESTACIONES';
    }

    protected function casts(): array
    {
        return [
            'fecha_servicio' => 'date',
            'tarifada_en' => 'datetime',
            'anulada_en' => 'datetime',
            'firma_recibida_en' => 'datetime',
            'visacion_aplicada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Prestacion $prestacion) {
            $desde = Estado::find($prestacion->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($prestacion->estado_id)?->codigo;
            $aplica = $prestacion->isDirty('visacion_aplicada_en') && $prestacion->visacion_aplicada_en !== null;
            $permitidos = match (true) {
                in_array($desde, [Estado::PENDIENTE, Estado::TARIFADO], true) && $hacia === Estado::TARIFADO && $prestacion->monto !== null && $aplica => self::CAMPOS_DE_APLICACION,
                $desde === Estado::PENDIENTE && $hacia === Estado::TARIFADO && $prestacion->monto !== null => self::CAMPOS_DE_TARIFADO,
                $desde === Estado::TARIFADO && $hacia === Estado::TARIFADO => self::CAMPOS_DE_FIRMA,
                in_array($desde, [Estado::PENDIENTE, Estado::TARIFADO], true) && $hacia === Estado::ANULADO => self::CAMPOS_DE_ANULACION,
                default => null,
            };
            if ($permitidos === null || array_diff(array_keys($prestacion->getDirty()), $permitidos) !== []) {
                throw RegistroEconomicoInmutable::prestacion();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $accion === AccionAuditoria::ANULAR && $this->motivo_anulacion ? "Motivo: {$this->motivo_anulacion}" : null;
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    /** El informe de estudio que la originó (vacío en la base). Solo el vínculo: su contenido es clínico. */
    public function informeEstudio(): BelongsTo
    {
        return $this->belongsTo(InformeEstudio::class);
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function tarifa(): BelongsTo
    {
        return $this->belongsTo(Tarifa::class);
    }

    /**
     * Su reparto (docs/reparto-economico.md), si ya se calculó: uno por prestación entre los no anulados. Al aplicar una visación que
     * cambia el total, el anterior se anula y se calcula otro: es el último.
     */
    public function reparto(): HasOne
    {
        return $this->hasOne(RepartoPrestacion::class)->latestOfMany();
    }

    public function responsablePago(): BelongsTo
    {
        return $this->belongsTo(ResponsablePago::class);
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanConvenio::class, 'plan_convenio_id');
    }

    public function visacion(): BelongsTo
    {
        return $this->belongsTo(Visacion::class);
    }

    public function lineaVisacion(): BelongsTo
    {
        return $this->belongsTo(LineaVisacion::class);
    }

    public function tarifaConvenio(): BelongsTo
    {
        return $this->belongsTo(TarifaConvenio::class);
    }

    public function firmaRecibidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'firma_recibida_por');
    }

    public function visacionAplicadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visacion_aplicada_por');
    }

    /**
     * Su línea en una liquidación no anulada (docs/liquidaciones-aseguradoras.md), si tiene: a lo sumo una. Sin ella, lo de la
     * aseguradora sigue pendiente de liquidar.
     */
    public function lineaLiquidacion(): HasOne
    {
        return $this->hasOne(LineaLiquidacion::class)->whereNull('lineas_liquidacion.liberada_en');
    }

    /** La línea de la parte de la paciente en una factura no anulada (docs/facturacion.md), si tiene: a lo sumo una. */
    public function detalleFactura(): HasOne
    {
        return $this->hasOne(DetalleFactura::class)->where('detalles_factura.parte', DetalleFactura::PACIENTE)->whereNull('detalles_factura.liberada_en');
    }

    /** Sus líneas en facturas no anuladas, de cualquier parte (a lo sumo una por parte). Con alguna, la prestación queda bloqueada. */
    public function detallesFactura(): HasMany
    {
        return $this->hasMany(DetalleFactura::class)->whereNull('detalles_factura.liberada_en');
    }

    /** Su cobro vigente (docs/cobros-caja.md), si tiene: a lo sumo uno. */
    public function cobroVigente(): HasOne
    {
        return $this->hasOne(CobroPrestacion::class)->whereNull('revertido_en');
    }

    public function tarifadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tarifada_por');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function pendiente(): bool
    {
        return $this->tieneEstado(Estado::PENDIENTE);
    }

    public function anulada(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function deAseguradora(): bool
    {
        return $this->pagador === self::ASEGURADORA;
    }

    /** Lo que se le cobra a la paciente: su parte (en las anteriores a los convenios, el monto). Null sin monto. */
    public function montoPaciente(): ?int
    {
        $monto = $this->monto_paciente ?? $this->monto;

        return $monto === null ? null : (int) round((float) $monto);
    }

    public function pagadorTexto(): string
    {
        return self::PAGADORES[$this->pagador ?? self::PACIENTE];
    }

    public function origenTexto(): string
    {
        return self::ORIGENES[$this->informe_estudio_id ? 'INFORME' : 'CONSULTA'];
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    /**
     * El estado de cobro, derivado de si tiene un cobro vigente y de la parte de la paciente: "Cobrada", "Pendiente" o "A cargo de
     * aseguradora" (sin nada que cobrarle a la paciente); vacío en una anulada sin cobro. Usa
     * `cobro_vigente_exists` (withExists('cobroVigente')) si se cargó.
     */
    public function estadoCobroTexto(): string
    {
        $cobrada = $this->cobro_vigente_exists
            ?? ($this->relationLoaded('cobroVigente') ? $this->cobroVigente !== null : $this->cobroVigente()->exists());

        return match (true) {
            (bool) $cobrada => self::COBRADA,
            $this->anulada() => '',
            $this->monto !== null && $this->montoPaciente() === 0 => self::A_CARGO_DE_ASEGURADORA,
            default => self::PENDIENTE_DE_COBRO,
        };
    }

    public function montoTexto(): string
    {
        return $this->monto === null ? '—' : $this->moneda->formatear($this->monto);
    }

    /** La parte de la paciente ("₲ 100.000"), o "—" sin monto. */
    public function montoPacienteTexto(): string
    {
        return $this->monto === null ? '—' : $this->moneda->formatear($this->montoPaciente());
    }

    public function montoAseguradoraTexto(): string
    {
        return $this->monto === null ? '—' : $this->moneda->formatear((int) round((float) ($this->monto_aseguradora ?? 0)));
    }
}
