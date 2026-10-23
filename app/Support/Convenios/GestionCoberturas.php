<?php

namespace App\Support\Convenios;

use App\Exceptions\AccionRechazada;
use App\Models\CoberturaPaciente;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LineaVisacion;
use App\Models\Paciente;
use App\Models\PlanConvenio;
use App\Models\Procedimiento;
use App\Models\Turno;
use App\Models\User;
use App\Models\Visacion;
use App\Support\Auditoria;
use App\Support\Cobros\Guaranies;
use App\Support\Fecha;
use App\Support\Repartos\Porcentaje;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La cobertura de la paciente y sus visaciones (docs/convenios-seguros.md), cargadas por Recepción desde la ficha, el turno o la
 * prestación. No calculan nada: la prestación usa la visación al fijar su monto (CalcularCobertura).
 *
 * - cargarCobertura: un solo formulario para la primera y para la que reemplaza a la vigente (la cierra con "hasta" = el día anterior
 *   a la nueva y queda en el historial). Una sola abierta por paciente; sus rangos no se solapan.
 * - bajaCobertura: la cierra con su último día, sin reemplazo.
 * - cargarVisacion: para UN turno o UNA consulta de la paciente, ligada a su cobertura abierta, con el número (único por responsable
 *   de pago entre las no anuladas), la fecha y una línea por procedimiento.
 * - anularVisacion: con motivo. Lo ya aplicado no se recalcula solo (la prestación muestra un aviso).
 */
final class GestionCoberturas
{
    public const NUMERO_MAXIMO = 50;

    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public const SIN_COBERTURA = 'La paciente no tiene una cobertura cargada: cárguela antes de la visación.';

    public const NUMERO_REPETIDO = 'Ya hay una visación vigente con ese número para esta aseguradora.';

    public static function cargarCobertura(User $usuario, Paciente $paciente, int $planId, string $numero, string $tipo, Carbon $desde, ?Carbon $hasta): CoberturaPaciente
    {
        $numero = trim($numero);
        if ($numero === '' || mb_strlen($numero) > self::NUMERO_MAXIMO) {
            throw new AccionRechazada('Escriba el número de asegurado (hasta '.self::NUMERO_MAXIMO.' caracteres).');
        }
        if (! array_key_exists($tipo, CoberturaPaciente::TIPOS)) {
            throw new AccionRechazada('Indique si es titular o beneficiario.');
        }
        if ($hasta !== null && $hasta->lt($desde)) {
            throw new AccionRechazada('El vencimiento no puede ser anterior al inicio.');
        }

        return DB::transaction(function () use ($usuario, $paciente, $planId, $numero, $tipo, $desde, $hasta) {
            // Bloquea la paciente: dos cargas simultáneas se ordenan.
            DB::table('pacientes')->where('id', $paciente->id)->lockForUpdate()->first();
            $plan = PlanConvenio::query()->with('convenio')->find($planId);
            if ($plan === null || ! $plan->estaActivo() || ! $plan->convenio->vigente()) {
                throw new AccionRechazada('El plan no existe, no está activo o su convenio ya no está vigente.');
            }
            $vigente = CoberturaPaciente::query()->where('paciente_id', $paciente->id)->whereNull('cerrada_en')->lockForUpdate()->first();
            $dia = $desde->format('Y-m-d');

            if ($vigente !== null && $dia < $vigente->vigente_desde->format('Y-m-d')) {
                throw new AccionRechazada('La nueva cobertura debe empezar el '.Fecha::mostrar($vigente->vigente_desde).' o después (el inicio de la vigente).');
            }
            if ($vigente === null) {
                $ultimo = CoberturaPaciente::query()->where('paciente_id', $paciente->id)->max('vigente_hasta');
                if ($ultimo !== null && $dia <= Carbon::parse($ultimo)->format('Y-m-d')) {
                    throw new AccionRechazada('La cobertura debe empezar después del '.Fecha::mostrar($ultimo).' (el último día de la anterior).');
                }
            }

            return Auditoria::conDetalle($vigente ? 'Cambio de cobertura' : 'Alta de la cobertura', function () use ($usuario, $paciente, $plan, $numero, $tipo, $desde, $hasta, $vigente) {
                if ($vigente !== null) {
                    $hastaAnterior = $desde->copy()->subDay();
                    self::cerrar($usuario, $vigente, $vigente->vigente_hasta !== null && $vigente->vigente_hasta->lt($hastaAnterior) ? $vigente->vigente_hasta : $hastaAnterior);
                }

                return CoberturaPaciente::create([
                    'paciente_id' => $paciente->id, 'plan_convenio_id' => $plan->id, 'numero_asegurado' => $numero, 'tipo_afiliado' => $tipo,
                    'vigente_desde' => $desde->format('Y-m-d'), 'vigente_hasta' => $hasta?->format('Y-m-d'), 'reemplaza_a_id' => $vigente?->id,
                    'usuario_id' => $usuario->id,
                ]);
            });
        });
    }

    public static function bajaCobertura(User $usuario, CoberturaPaciente $cobertura, Carbon $ultimoDia): CoberturaPaciente
    {
        return DB::transaction(function () use ($usuario, $cobertura, $ultimoDia) {
            $actual = CoberturaPaciente::query()->whereKey($cobertura->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->abierta()) {
                throw new AccionRechazada('Esta cobertura ya está cerrada.');
            }
            if ($ultimoDia->lt($actual->vigente_desde->copy()->subDay())) {
                throw new AccionRechazada('El último día no puede ser anterior al inicio de la cobertura ('.Fecha::mostrar($actual->vigente_desde).').');
            }
            $hasta = $actual->vigente_hasta !== null && $actual->vigente_hasta->lt($ultimoDia) ? $actual->vigente_hasta : $ultimoDia;
            Auditoria::conDetalle('Baja de la cobertura', fn () => self::cerrar($usuario, $actual, $hasta));

            return $actual;
        });
    }

    /**
     * @param  'turno'|'consulta'  $tipo
     * @param  list<array{procedimiento_id: int, resultado: string, copago: ?string, valor: ?string}>  $lineas
     */
    public static function cargarVisacion(User $usuario, Paciente $paciente, string $tipo, int $destinoId, string $numero, Carbon $fecha, array $lineas): Visacion
    {
        $numero = trim($numero);
        if ($numero === '' || mb_strlen($numero) > self::NUMERO_MAXIMO) {
            throw new AccionRechazada('Escriba el número de visación (hasta '.self::NUMERO_MAXIMO.' caracteres).');
        }
        $lineas = self::lineasValidas($lineas);

        try {
            return DB::transaction(function () use ($usuario, $paciente, $tipo, $destinoId, $numero, $fecha, $lineas) {
                $cobertura = CoberturaPaciente::query()->where('paciente_id', $paciente->id)->whereNull('cerrada_en')->with('plan.convenio')->first()
                    ?? throw new AccionRechazada(self::SIN_COBERTURA);
                $destino = self::destino($paciente, $tipo, $destinoId);
                $responsableId = $cobertura->plan->convenio->responsable_pago_id;
                $repetido = Visacion::query()->where('responsable_pago_id', $responsableId)->where('numero', $numero)->whereNull('anulada_en')->lockForUpdate()->exists();
                if ($repetido) {
                    throw new AccionRechazada(self::NUMERO_REPETIDO);
                }

                return Auditoria::conDetalle("Visación N.º {$numero}", function () use ($usuario, $cobertura, $responsableId, $tipo, $destino, $numero, $fecha, $lineas) {
                    $visacion = Visacion::create([
                        'cobertura_paciente_id' => $cobertura->id, 'responsable_pago_id' => $responsableId,
                        'turno_id' => $tipo === 'turno' ? $destino->id : null, 'consulta_id' => $tipo === 'consulta' ? $destino->id : null,
                        'numero' => $numero, 'fecha' => $fecha->format('Y-m-d'), 'usuario_id' => $usuario->id,
                    ]);
                    foreach ($lineas as $linea) {
                        LineaVisacion::create(['visacion_id' => $visacion->id, ...$linea]);
                    }

                    return $visacion;
                });
            });
        } catch (QueryException $e) {
            // Otra carga con el mismo número ganó por el índice único parcial.
            if (str_contains($e->getMessage(), 'visaciones_numero_unico') || str_contains($e->getMessage(), 'visaciones.responsable_pago_id')) {
                throw new AccionRechazada(self::NUMERO_REPETIDO);
            }
            throw $e;
        }
    }

    public static function anularVisacion(User $usuario, Visacion $visacion, string $motivo): Visacion
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('Indique el motivo de la anulación (al menos '.self::MOTIVO_MINIMO.' caracteres).');
        }

        return DB::transaction(function () use ($usuario, $visacion, $motivo) {
            $actual = Visacion::query()->whereKey($visacion->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->vigente()) {
                throw new AccionRechazada('Esta visación ya está anulada.');
            }
            $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_en' => now(),
                'motivo_anulacion' => mb_substr($motivo, 0, self::MOTIVO_MAXIMO), 'anulada_por' => $usuario->id,
            ])->save();
            $actual->unsetRelation('estado');

            return $actual;
        });
    }

    private static function cerrar(User $usuario, CoberturaPaciente $cobertura, Carbon $hasta): void
    {
        $cobertura->forceFill([
            'estado_id' => Estado::idDe(Estado::HISTORICO), 'vigente_hasta' => $hasta->format('Y-m-d'),
            'cerrada_en' => now(), 'cerrada_por' => $usuario->id,
        ])->save();
    }

    /** El turno (no cancelado) o la consulta (no anulada) de la paciente. */
    private static function destino(Paciente $paciente, string $tipo, int $id): Turno|Consulta
    {
        if ($tipo === 'turno') {
            $turno = Turno::query()->whereKey($id)->where('paciente_id', $paciente->id)->first();
            if ($turno === null || $turno->tieneEstado(Estado::CANCELADO)) {
                throw new AccionRechazada('El turno elegido no es de esta paciente o está cancelado.');
            }

            return $turno;
        }
        if ($tipo === 'consulta') {
            $consulta = Consulta::query()->whereKey($id)->whereHas('historiaClinica', fn ($query) => $query->where('paciente_id', $paciente->id))->first();
            if ($consulta === null || $consulta->tieneEstado(Estado::ANULADO)) {
                throw new AccionRechazada('La consulta elegida no es de esta paciente o está anulada.');
            }

            return $consulta;
        }

        throw new AccionRechazada('Elija el turno o la consulta de la visación.');
    }

    /**
     * Las líneas cargadas (las vacías no cuentan), validadas: procedimiento activo, sin repetir, resultado y, si es APROBADO, lo que paga
     * la paciente (sin copago, un porcentaje de 0 a 100 o un monto fijo en guaraníes).
     *
     * @return list<array{procedimiento_id: int, resultado: string, copago: ?string, porcentaje: ?string, monto: ?int}>
     */
    private static function lineasValidas(array $lineas): array
    {
        $validas = [];
        foreach ($lineas as $linea) {
            $procedimientoId = (int) ($linea['procedimiento_id'] ?? 0);
            if ($procedimientoId === 0) {
                continue;
            }
            if (isset($validas[$procedimientoId])) {
                throw new AccionRechazada('Un procedimiento figura dos veces en la visación.');
            }
            $procedimiento = Procedimiento::query()->find($procedimientoId);
            if ($procedimiento === null || ! $procedimiento->estaActivo()) {
                throw new AccionRechazada('Uno de los procedimientos no existe o no está activo.');
            }
            $resultado = (string) ($linea['resultado'] ?? '');
            $copago = $resultado === LineaVisacion::APROBADO ? (string) ($linea['copago'] ?? '') : null;
            $valor = trim((string) ($linea['valor'] ?? ''));
            $validas[$procedimientoId] = match (true) {
                $resultado === LineaVisacion::NO_CUBIERTO => ['resultado' => $resultado, 'copago' => null, 'porcentaje' => null, 'monto' => null],
                $resultado !== LineaVisacion::APROBADO => throw new AccionRechazada("Indique si {$procedimiento->nombre} está aprobado o no cubierto."),
                $copago === LineaVisacion::NINGUNO => ['resultado' => $resultado, 'copago' => $copago, 'porcentaje' => null, 'monto' => null],
                $copago === LineaVisacion::PORCENTAJE => ['resultado' => $resultado, 'copago' => $copago, 'porcentaje' => Porcentaje::validado($valor), 'monto' => null],
                $copago === LineaVisacion::MONTO_FIJO && preg_match(Guaranies::FORMATO, $valor) === 1 && Guaranies::entero($valor) <= Guaranies::MAXIMO
                    => ['resultado' => $resultado, 'copago' => $copago, 'porcentaje' => null, 'monto' => Guaranies::entero($valor)],
                $copago === LineaVisacion::MONTO_FIJO => throw new AccionRechazada('El copago de '.$procedimiento->nombre.': '.Guaranies::MENSAJE),
                default => throw new AccionRechazada("Indique cuánto paga la paciente en {$procedimiento->nombre}."),
            } + ['procedimiento_id' => $procedimientoId];
        }
        if ($validas === []) {
            throw new AccionRechazada('Cargue al menos un procedimiento en la visación.');
        }

        return array_values($validas);
    }
}
