<?php

namespace Database\Seeders\Demo;

use App\Enums\TipoTurno;
use App\Models\Consultorio;
use App\Models\CupoEntrePacientes;
use App\Models\Disponibilidad;
use App\Models\OrigenTurno;
use App\Models\Paciente;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use App\Support\Agenda;
use App\Support\Fecha;
use App\Support\Turnos\AgendarEntrePacientes;
use App\Support\Turnos\RegistrarUrgencia;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Agenda de los dos médicos demo: disponibilidades de lunes a viernes (mañana y tarde, bloques de 20 minutos; Médica 1
 * en el consultorio 101 y Médico 2 en el 102) y turnos para los próximos 10 días: consultas y estudios de 30, 40, 90 y
 * 120 minutos, algunos "entre pacientes" (con su cupo) y una urgencia (que solo existe hoy).
 *
 * Los turnos con horario se dan por la misma agenda que el panel (Agenda::horario: el horario tiene que estar libre y
 * entrar completo), no en el pasado. Idempotente: las disponibilidades se reconocen por profesional, consultorio, día y
 * franja; los turnos, por la marca MARCA_TURNO: un día en que el profesional ya tiene turnos demo no se vuelve a llenar.
 */
class AgendaDemo extends EtapaDemo
{
    public const DIAS_ADELANTE = 10;

    public const TURNOS_POR_MEDICO_Y_DIA = 4;

    /** Franjas de cada médico: consultorio y [desde, hasta]. Duración de bloque: 20 minutos. */
    private const FRANJAS = [['08:00', '12:00'], ['14:00', '18:00']];

    private const CONSULTORIO_DE = ['medica1' => '101', 'medico2' => '102'];

    /** Los procedimientos que se reparten entre los turnos, en este orden y de a uno. */
    private const CICLO = [
        'CONS-001', 'ECO-ABD', 'CONS-001', 'ECO-OBST', 'CONS-001', 'ECO-TV',
        'ECO-OBST-DOP', 'CONS-001', 'PAP-COLPO', 'ECO-CROMO', 'CONS-001', 'ECO-MORFO',
    ];

    /** Días laborables (por su orden) en que Médico 2 atiende pacientes entre pacientes, y su cupo. */
    private const CUPO_ENTRE_PACIENTES = 2;

    public function run(): void
    {
        $medicos = collect(self::CONSULTORIO_DE)->map(fn ($consultorio, $usuario) => $this->profesional($usuario));
        $pacientes = Paciente::whereHas('persona', fn ($query) => $query->where('email', 'like', 'paciente%@'.self::DOMINIO))->orderBy('id')->get();
        if ($pacientes->isEmpty()) {
            throw new RuntimeException('Faltan las pacientes demo: correr antes PacientesDemo.');
        }

        $this->como($this->correo('gerencia'), fn () => $this->disponibilidades($medicos));
        $this->como($this->correo('recepcion'), function () use ($medicos, $pacientes) {
            $this->turnos($medicos, $pacientes);
            $this->urgencia($medicos['medica1'], $pacientes->last());
        });
    }

    private function disponibilidades($medicos): void
    {
        // Desde hace dos meses, para que después se puedan simular días pasados dentro de la vigencia.
        $desde = self::inicioVigencias()->format('Y-m-d');

        foreach ($medicos as $usuario => $profesional) {
            $consultorio = Consultorio::where('nombre', self::CONSULTORIO_DE[$usuario])->first()
                ?? throw new RuntimeException('Faltan los consultorios: correr antes BaseClinica.');

            foreach (array_slice(array_keys(Disponibilidad::DIAS), 0, 5) as $dia) {
                foreach (self::FRANJAS as [$horaDesde, $horaHasta]) {
                    $existe = Disponibilidad::where(['profesional_id' => $profesional->id, 'consultorio_id' => $consultorio->id, 'dia_semana' => $dia])->get()
                        ->contains(fn ($d) => Disponibilidad::hora($d->hora_desde) === $horaDesde && Disponibilidad::hora($d->hora_hasta) === $horaHasta);
                    if (! $existe) {
                        Disponibilidad::create([
                            'profesional_id' => $profesional->id, 'consultorio_id' => $consultorio->id, 'dia_semana' => $dia,
                            'hora_desde' => $horaDesde, 'hora_hasta' => $horaHasta, 'duracion_turno_minutos' => 20, 'vigencia_desde' => $desde,
                        ]);
                        $this->contar('disponibilidades');
                    }
                }
            }
        }
    }

    private function turnos($medicos, $pacientes): void
    {
        $procedimientos = Procedimiento::whereIn('codigo', array_unique(self::CICLO))->get()->keyBy('codigo');
        if ($procedimientos->count() < count(array_unique(self::CICLO))) {
            throw new RuntimeException('Faltan procedimientos: correr antes BaseClinica.');
        }
        $origenes = OrigenTurno::whereIn('codigo', ['PRESENCIAL', 'TELEFONICO'])->pluck('id')->values();
        $recepcion = User::where('email', $this->correo('recepcion'))->first();

        $paso = 0;      // avanza por CICLO y por las pacientes, de a uno por turno
        $laborable = 0; // cuenta los días laborables
        for ($dias = 1; $dias <= self::DIAS_ADELANTE; $dias++) {
            $fecha = Fecha::hoy()->addDays($dias);
            if ($fecha->dayOfWeekIso > 5) {
                continue;
            }
            $iso = $fecha->format('Y-m-d');
            $usadas = []; // una paciente no repite en el día, con nadie

            foreach ($medicos as $usuario => $profesional) {
                $yaTiene = Turno::where(['profesional_id' => $profesional->id, 'observaciones' => self::MARCA_TURNO])->whereDate('fecha', $iso)->exists();
                if ($yaTiene) {
                    $paso += self::TURNOS_POR_MEDICO_Y_DIA;

                    continue;
                }

                for ($k = 0; $k < self::TURNOS_POR_MEDICO_Y_DIA; $k++, $paso++) {
                    $procedimiento = $procedimientos[self::CICLO[$paso % count(self::CICLO)]];
                    $horarios = Agenda::horariosDisponibles($profesional, $fecha, $procedimiento->duracion_estimada_minutos);
                    // Alternan mañana y tarde: los impares buscan primero desde las 14:00.
                    $horario = ($k % 2 ? $horarios->first(fn ($h) => $h['hora_inicio'] >= '14:00') : null) ?? $horarios->first();
                    $paciente = $this->siguiente($pacientes, $usadas, $paso);
                    if (! $horario || ! $paciente) {
                        $this->aviso("Sin lugar para {$procedimiento->codigo} el {$iso} con {$usuario}: se saltea.");

                        continue;
                    }
                    $usadas[] = $paciente->id;

                    DB::transaction(fn () => Turno::create([
                        'paciente_id' => $paciente->id, 'profesional_id' => $profesional->id, 'fecha' => $iso,
                        'hora_inicio' => $horario['hora_inicio'], 'hora_fin' => $horario['hora_fin'], 'consultorio_id' => $horario['consultorio_id'],
                        'procedimiento_id' => $procedimiento->id, 'origen_turno_id' => $origenes[$paso % max(1, $origenes->count())] ?? null,
                        'observaciones' => self::MARCA_TURNO,
                    ]));
                    $this->contar('turnos con horario');
                }
            }

            // Médico 2 atiende dos pacientes entre pacientes un día laborable por medio.
            if ($laborable++ % 2 === 0) {
                $this->entrePacientes($medicos['medico2'], $iso, $pacientes, $usadas, $procedimientos['CONS-001'], $recepcion, $paso++);
            }
        }
    }

    private function entrePacientes(Profesional $profesional, string $iso, $pacientes, array $usadas, Procedimiento $consulta, ?User $recepcion, int $paso): void
    {
        $hechos = Turno::delTipo(TipoTurno::ENTRE_PACIENTES)->where(['profesional_id' => $profesional->id, 'observaciones' => self::MARCA_TURNO])->whereDate('fecha', $iso)->exists();
        if ($hechos) {
            return;
        }

        $cupo = CupoEntrePacientes::where('profesional_id', $profesional->id)->whereDate('fecha', $iso)->first()
            ?? CupoEntrePacientes::create(['profesional_id' => $profesional->id, 'fecha' => $iso, 'cantidad' => self::CUPO_ENTRE_PACIENTES, 'creado_por' => $recepcion?->id]);
        $cupo->wasRecentlyCreated && $this->contar('cupos entre pacientes');

        for ($i = 0; $i < self::CUPO_ENTRE_PACIENTES; $i++) {
            if (! $paciente = $this->siguiente($pacientes, $usadas, $paso + $i)) {
                return;
            }
            $usadas[] = $paciente->id;
            AgendarEntrePacientes::ejecutar([
                'paciente_id' => $paciente->id, 'profesional_id' => $profesional->id, 'fecha' => $iso,
                'procedimiento_id' => $consulta->id, 'observaciones' => self::MARCA_TURNO,
            ]);
            $this->contar('turnos entre pacientes');
        }
    }

    /** La urgencia existe solo hoy (RegistrarUrgencia): una por paciente demo y profesional, sin repetir. */
    private function urgencia(Profesional $profesional, Paciente $paciente): void
    {
        $hoy = Fecha::hoy()->format('Y-m-d');
        $existe = Turno::delTipo(TipoTurno::URGENCIA)->where(['profesional_id' => $profesional->id, 'paciente_id' => $paciente->id])->whereDate('fecha', $hoy)->exists();
        if (! $existe) {
            RegistrarUrgencia::ejecutar($paciente->id, $profesional->id);
            $this->contar('urgencias');
        }
    }

    /** La próxima paciente del giro que todavía no tiene turno ese día. */
    private function siguiente($pacientes, array $usadas, int $desde): ?Paciente
    {
        foreach (range(0, $pacientes->count() - 1) as $i) {
            $paciente = $pacientes[($desde + $i) % $pacientes->count()];
            if (! in_array($paciente->id, $usadas, true)) {
                return $paciente;
            }
        }

        return null;
    }
}
