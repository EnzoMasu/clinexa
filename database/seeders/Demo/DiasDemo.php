<?php

namespace Database\Seeders\Demo;

use App\Enums\TipoDiagnostico;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Consulta;
use App\Models\CupoEntrePacientes;
use App\Models\Dieta;
use App\Models\Estado;
use App\Models\Estudio;
use App\Models\MedioPago;
use App\Models\OrigenTurno;
use App\Models\Paciente;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Receta;
use App\Models\SalidaSinAtencion;
use App\Models\TipoBloqueAnamnesis;
use App\Models\TipoIndicacion;
use App\Models\Turno;
use App\Models\User;
use App\Support\Agenda;
use App\Support\Atencion\Atender;
use App\Support\Atencion\Autoguardado;
use App\Support\Atencion\CerrarJornada;
use App\Support\Atencion\Finalizar;
use App\Support\Atencion\FormularioConsulta;
use App\Support\Atencion\MarcarLista;
use App\Support\Atencion\Preparar;
use App\Support\Atencion\RegistrarSalida;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\CerrarCaja;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\RevertirCobro;
use App\Support\Cobros\RevisarCierre;
use App\Support\Convenios\FirmaRecibida;
use App\Support\Convenios\GestionCoberturas;
use App\Support\Convenios\TicketVisacion;
use App\Support\Correcciones\AgregarAdenda;
use App\Support\Correcciones\CorregirConsulta;
use App\Support\Correcciones\Desbloquear;
use App\Support\Fecha;
use App\Support\HojaReceta;
use App\Support\OrdenEstudio\EmitirOrden;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Reposo\AnularReposo;
use App\Support\Reposo\EmitirReposo;
use App\Support\Turnos\AgendarEntrePacientes;
use App\Support\Turnos\RegistrarUrgencia;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Días simulados (etapa 2, partes C y D): los últimos 10 días hábiles, uno por uno, con la hora movida
 * (Carbon::setTestNow) para que cada cosa quede con su fecha y hora como si se hubiera hecho ese día, y hoy la Caja 2 abierta.
 *
 * Cada día sigue PLAN: Recepción da los turnos (por la Agenda) y carga las visaciones, Caja 1 abre, Enfermería hace el triaje,
 * el médico atiende y finaliza (FormularioConsulta + Finalizar: la prestación y su reparto los crea la aplicación), emite
 * recetas, reposos y órdenes; Recepción registra retiros, rechazos y cancelaciones, la firma y el ticket; Caja 1 cobra
 * (solo la parte de la paciente), los médicos cierran la jornada (los que no vinieron, AUSENTE), Caja 1 cierra y Gerencia
 * revisa. Casos a propósito: cubre todo, copago porcentaje y monto fijo, NO_CUBIERTO, un estudio sin visar, una cobertura
 * vencida, PAP-COLPO pendiente de tarifar, el Dr. Duarte pendiente de reparto, un cierre con diferencia, uno devuelto, una
 * reversión aprobada y una rechazada; hoy, una reversión directa y una solicitud pendiente.
 *
 * Sin servicio (con los modelos y su auditoría, como el panel): cancelar un turno (Turno::pasarA), el cupo entre pacientes,
 * los turnos con horario (como AgendaDemo) y las recetas (crear, emitir y anular: la lógica está en RecetaController).
 *
 * Los hitos (PRESTACIONES, PRESTACIONES_INFORMES, REPARTOS, CONVENIOS) se adelantan a la víspera del primer día, solo si no hay
 * consultas finalizadas ni prestaciones anteriores (si no, nada de lo simulado tendría prestación, seguro ni reparto).
 *
 * Se carga UNA sola vez: si ya hay cajas de Caja 1 o turnos demo de días pasados, no hace nada.
 */
class DiasDemo extends EtapaDemo
{
    public const DIAS = 10;

    public const HITOS = [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, 'REPARTOS', 'CONVENIOS'];

    public const MONTO_INICIAL_CAJA = 500000;

    /**
     * Un día por renglón: [médico, procedimiento (o URGENCIA, ENTRE), paciente (orden), desenlace, extras].
     * Desenlaces: atender, ausente (lo cierra la jornada), cancelar, retiro (con el triaje terminado), rechazo.
     * Extras: triaje, visacion [resultado, copago, valor], informe (código), receta, receta_anulada, reposo, reposo_anulado,
     * orden, dieta, adenda, desbloqueo.
     */
    public const PLAN = [
        [
            ['medica1', 'CONS-001', 0, 'atender', ['triaje' => true, 'visacion' => ['APROBADO', 'NINGUNO', null], 'receta' => true, 'dieta' => true]],
            ['medica1', 'ECO-TV', 1, 'atender', ['visacion' => ['APROBADO', 'PORCENTAJE', '20'], 'informe' => 'ECO-TV']],
            ['medica1', 'CONS-001', 6, 'ausente', []],
            ['medico2', 'CONS-001', 4, 'atender', ['receta' => true]],
            ['medico2', 'PAP-COLPO', 5, 'atender', []],
        ],
        [
            // La ecografía de la consulta no está en la visación: "procedimiento sin visar".
            ['medica1', 'CONS-001', 2, 'atender', ['visacion' => ['APROBADO', 'MONTO_FIJO', '50000'], 'informe' => 'ECO-TV']],
            ['medica1', 'ECO-OBST', 7, 'atender', ['informe' => 'ECO-OBST', 'orden' => true]],
            ['medica1', 'URGENCIA', 10, 'atender', ['receta' => true]],
            ['medico2', 'CONS-001', 8, 'cancelar', []],
            ['medico2', 'ECO-ABD', 9, 'atender', ['informe' => 'ECO-ABD']],
        ],
        [
            ['medica1', 'CONS-001', 3, 'atender', ['reposo' => true]], // cobertura vencida
            ['medica1', 'CONS-001', 11, 'atender', ['triaje' => true, 'informe' => 'ECO-TV']], // ecografía hecha en la consulta
            ['medico2', 'CONS-001', 0, 'atender', []], // con cobertura y sin visación
            ['medico2', 'ENTRE', 1, 'atender', []],
        ],
        [
            ['medica1', 'ECO-CROMO', 4, 'atender', ['informe' => 'ECO-CROMO']],
            ['medica1', 'CONS-001', 5, 'retiro', []],
            ['medico2', 'CONS-001', 6, 'rechazo', []],
            ['medico2', 'ECO-OBST-DOP', 7, 'atender', ['informe' => 'ECO-OBST-DOP']],
        ],
        [
            ['medica1', 'CONS-001', 2, 'atender', ['visacion' => ['NO_CUBIERTO', null, null]]],
            ['medica1', 'ECO-MORFO', 8, 'atender', ['informe' => 'ECO-MORFO', 'receta' => true, 'receta_anulada' => true]],
            ['medico2', 'CONS-001', 9, 'atender', ['reposo' => true, 'reposo_anulado' => true]],
            ['medico2', 'PAP-COLPO', 10, 'ausente', []],
        ],
        [
            ['medica1', 'CONS-001', 11, 'atender', ['adenda' => true, 'dieta' => true]],
            ['medica1', 'ECO-ABD', 0, 'atender', ['informe' => 'ECO-ABD']],
            ['medico2', 'CONS-001', 3, 'atender', ['orden' => true]],
            ['medico2', 'CONS-001', 4, 'cancelar', []],
        ],
        [
            ['medica1', 'CONS-001', 5, 'atender', ['triaje' => true, 'desbloqueo' => true]],
            ['medica1', 'ECO-TV', 6, 'atender', ['informe' => 'ECO-TV']],
            ['medico2', 'CONS-001', 7, 'atender', ['orden' => true, 'receta' => true]],
            ['medico2', 'CONS-001', 8, 'ausente', []],
        ],
        [
            ['medica1', 'CONS-001', 9, 'atender', ['dieta' => true, 'receta' => true]],
            ['medica1', 'ECO-OBST', 10, 'atender', ['informe' => 'ECO-OBST']],
            ['medico2', 'ECO-TV', 11, 'atender', ['informe' => 'ECO-TV']],
            ['medico2', 'CONS-001', 1, 'atender', ['visacion' => ['APROBADO', 'NINGUNO', null]]],
        ],
        [
            ['medica1', 'CONS-001', 0, 'atender', ['visacion' => ['APROBADO', 'PORCENTAJE', '30']]],
            ['medico2', 'CONS-001', 2, 'atender', []],
            ['medico2', 'PAP-COLPO', 3, 'atender', []],
        ],
        [
            ['medica1', 'CONS-001', 4, 'atender', ['reposo' => true]],
            ['medica1', 'ECO-TV', 5, 'atender', ['informe' => 'ECO-TV']],
            ['medico2', 'CONS-001', 6, 'atender', []],
            ['medico2', 'CONS-001', 7, 'cancelar', []],
        ],
    ];

    /** Pacientes (orden) del último día que no se cobran: las cobra hoy la Caja 2. */
    public const PARA_HOY = [4, 5];

    /** Diagnóstico de prueba por procedimiento (CIE-10). */
    private const DIAGNOSTICO = [
        'CONS-001' => 'Z01.4', 'PAP-COLPO' => 'Z01.4', 'ECO-ABD' => 'R10.2', 'ECO-TV' => 'Z01.4',
        'ECO-OBST' => 'Z34.9', 'ECO-OBST-DOP' => 'Z34.9', 'ECO-CROMO' => 'Z34.9', 'ECO-MORFO' => 'Z34.9',
    ];

    public const CODIGOS_CIE10 = ['Z01.4', 'R10.2', 'Z34.9'];

    private int $paso = 0;

    /** Los cobros de cada día (índice del día => cobros), para las reversiones. */
    private array $cobros = [];

    private array $medios;

    private $pacientes;

    /** Los 10 días hábiles anteriores a hoy, del más viejo al más nuevo. */
    public static function dias(): array
    {
        $dias = [];
        for ($dia = Fecha::hoy()->subDay(); count($dias) < self::DIAS; $dia = $dia->copy()->subDay()) {
            if ($dia->dayOfWeekIso <= 5) {
                array_unshift($dias, $dia->copy());
            }
        }

        return $dias;
    }

    /** El día anterior al primero simulado, a las 8: el momento en que se cargan catálogos, tarifas y seguros. */
    public static function vispera(): Carbon
    {
        return self::dias()[0]->copy()->subDay()->setTime(8, 0);
    }

    public static function yaCargados(): bool
    {
        $caja1 = User::where('email', 'caja1@'.self::DOMINIO)->value('id');

        return ($caja1 && Caja::where('usuario_id', $caja1)->exists())
            || Turno::where('observaciones', self::MARCA_TURNO)->whereDate('fecha', '<', Fecha::hoy()->format('Y-m-d'))->exists();
    }

    public function run(): void
    {
        if (self::yaCargados()) {
            $this->aviso('Días simulados: ya estaban cargados (se cargan una sola vez).');

            return;
        }
        $dias = self::dias();
        $original = Carbon::getTestNow();
        $this->pacientes = $this->pacientesDemo();
        $this->medios = MedioPago::whereIn('codigo', ['EFECTIVO', 'TRANSFERENCIA', 'TARJETA'])->pluck('id', 'codigo')->all();

        try {
            $this->adelantarHitos($dias[0]->copy()->subDay());
            foreach ($dias as $indice => $dia) {
                $this->dia($indice, $dia, self::PLAN[$indice]);
            }
            Carbon::setTestNow($original);
            $this->hoy();
        } finally {
            Carbon::setTestNow($original);
        }
    }

    /** Lo creado antes de un hito no tiene prestación, seguro ni reparto: se adelantan a la víspera si no había nada antes. */
    private function adelantarHitos(Carbon $vispera): void
    {
        $nuevo = $vispera->copy()->startOfDay()->utc();
        foreach (self::HITOS as $codigo) {
            $actual = DB::table('hitos_sistema')->where('codigo', $codigo)->value('fecha_hora')
                ?? throw new RuntimeException("Falta el hito {$codigo}: correr las migraciones.");
            $actual = Carbon::parse($actual, 'UTC');
            if ($actual->lte($nuevo)) {
                continue;
            }
            $previas = Consulta::where('estado_id', Estado::idDe(Estado::FINALIZADO))->where('finalizada_en', '<', $actual)->exists()
                || Prestacion::where('created_at', '<', $actual)->exists();
            if ($previas) {
                throw new RuntimeException("No se puede adelantar el hito {$codigo}: ya hay consultas finalizadas o prestaciones anteriores. Use una base nueva.");
            }
            DB::table('hitos_sistema')->where('codigo', $codigo)->update(['fecha_hora' => $nuevo->format('Y-m-d H:i:s'), 'updated_at' => now()]);
            $this->aviso("Hito {$codigo} adelantado al ".Fecha::mostrar($nuevo, conHora: true).' (solo desarrollo).');
        }
    }

    private function momento(Carbon $dia, string $hora, int $mas = 0): void
    {
        Carbon::setTestNow(Carbon::parse($dia->format('Y-m-d').' '.$hora, config('app.zona_horaria_local'))->addMinutes($mas));
    }

    private function dia(int $indice, Carbon $dia, array $casos): void
    {
        // 07:00 Recepción da los turnos del día (y el cupo entre pacientes); las urgencias llegan después.
        $this->momento($dia, '07:00');
        $casos = $this->como($this->correo('recepcion'), fn () => array_map(fn (array $caso) => $this->darTurno($dia, $caso), $casos));

        $this->momento($dia, '07:30');
        $this->como($this->correo('caja1'), fn () => AbrirCaja::ejecutar($this->usuario('caja1'), self::MONTO_INICIAL_CAJA));
        $this->contar('cajas abiertas');

        $this->momento($dia, '07:40');
        $this->como($this->correo('recepcion'), function () use ($casos, $dia) {
            foreach ($casos as $caso) {
                if (isset($caso['extras']['visacion'])) {
                    $this->visacion($caso, $dia);
                }
                if ($caso['desenlace'] === 'cancelar') {
                    $caso['turno']->pasarA(Estado::CANCELADO);
                    $this->contar('turnos cancelados');
                }
            }
        });

        usort($casos, fn ($a, $b) => $a['hora'] <=> $b['hora']);
        foreach ($casos as $caso) {
            $this->caso($dia, $caso);
        }

        $this->momento($dia, '17:30');
        $this->cobrarDelDia($indice, $casos);
        $this->reversiones($indice, $dia);

        $this->momento($dia, '18:00');
        foreach (collect($casos)->pluck('medico')->unique() as $medico) {
            [$ausentes] = $this->como($this->correo($medico), fn () => CerrarJornada::ejecutar($this->usuario($medico), confirmoUrgencias: true));
            $this->contar('turnos ausentes', $ausentes);
        }

        $this->cierreDeCaja($indice, $dia);
    }

    /** El turno del caso (las urgencias, después: llegan sin turno). Devuelve el caso con su turno y su hora. */
    private function darTurno(Carbon $dia, array $plan): array
    {
        [$medico, $codigo, $orden, $desenlace, $extras] = $plan;
        $caso = ['medico' => $medico, 'codigo' => $codigo, 'paciente' => $this->pacientes[$orden], 'desenlace' => $desenlace, 'extras' => $extras, 'turno' => null];
        $profesional = $this->profesional($medico);

        if ($codigo === 'URGENCIA') {
            return [...$caso, 'hora' => '10:30'];
        }
        $consulta = Procedimiento::where('codigo', $codigo === 'ENTRE' ? 'CONS-001' : $codigo)->firstOrFail();
        if ($codigo === 'ENTRE') {
            $cupo = CupoEntrePacientes::where('profesional_id', $profesional->id)->whereDate('fecha', $dia->format('Y-m-d'))->first()
                ?? CupoEntrePacientes::create(['profesional_id' => $profesional->id, 'fecha' => $dia->format('Y-m-d'), 'cantidad' => 1, 'creado_por' => $this->usuario('recepcion')->id]);
            $turno = AgendarEntrePacientes::ejecutar([
                'paciente_id' => $caso['paciente']->id, 'profesional_id' => $profesional->id, 'fecha' => $dia->format('Y-m-d'),
                'procedimiento_id' => $consulta->id, 'observaciones' => self::MARCA_TURNO,
            ]);
            $this->contar('turnos entre pacientes');

            return [...$caso, 'turno' => $turno, 'hora' => '11:00'];
        }

        $horario = Agenda::horariosDisponibles($profesional, $dia, $consulta->duracion_estimada_minutos)->first()
            ?? throw new RuntimeException("Sin horario para {$codigo} el {$dia->format('d/m/Y')}.");
        $origenes = OrigenTurno::whereIn('codigo', ['PRESENCIAL', 'TELEFONICO'])->pluck('id')->values();
        $turno = DB::transaction(fn () => Turno::create([
            'paciente_id' => $caso['paciente']->id, 'profesional_id' => $profesional->id, 'fecha' => $dia->format('Y-m-d'),
            'hora_inicio' => $horario['hora_inicio'], 'hora_fin' => $horario['hora_fin'], 'consultorio_id' => $horario['consultorio_id'],
            'procedimiento_id' => $consulta->id, 'origen_turno_id' => $origenes[$this->paso++ % max(1, $origenes->count())] ?? null,
            'observaciones' => self::MARCA_TURNO,
        ]));
        $this->contar('turnos con horario');

        return [...$caso, 'turno' => $turno, 'hora' => substr($horario['hora_inicio'], 0, 5)];
    }

    /** La visación del turno, solo con la línea de su procedimiento (lo que la consulta agregue queda "sin visar"). */
    private function visacion(array $caso, Carbon $dia): void
    {
        [$resultado, $copago, $valor] = $caso['extras']['visacion'];
        GestionCoberturas::cargarVisacion($this->usuario('recepcion'), $caso['paciente'], 'turno', $caso['turno']->id,
            sprintf('V-%s-%03d', $dia->format('ymd'), ++$this->paso), $dia,
            [['procedimiento_id' => $caso['turno']->procedimiento_id, 'resultado' => $resultado, 'copago' => $copago, 'valor' => $valor]]);
        $this->contar('visaciones');
    }

    private function caso(Carbon $dia, array $caso): void
    {
        $hora = $caso['hora'];
        $medico = $this->usuario($caso['medico']);

        if ($caso['codigo'] === 'URGENCIA') {
            $this->momento($dia, $hora);
            $caso['turno'] = $this->como($this->correo('recepcion'), fn () => RegistrarUrgencia::ejecutar($caso['paciente']->id, $this->profesional($caso['medico'])->id));
            $this->contar('urgencias');
        }

        match ($caso['desenlace']) {
            'ausente', 'cancelar' => null,
            'rechazo' => $this->salida($dia, $hora, $caso['turno'], SalidaSinAtencion::RECHAZO),
            'retiro' => [$this->triaje($dia, $hora, $caso['turno']), $this->salida($dia, $hora, $caso['turno'], SalidaSinAtencion::RETIRO)],
            'atender' => $this->atender($dia, $hora, $caso, $medico),
        };
    }

    private function salida(Carbon $dia, string $hora, Turno $turno, string $tipo): void
    {
        $this->momento($dia, $hora, 10);
        $this->como($this->correo('recepcion'), fn () => RegistrarSalida::ejecutar($this->usuario('recepcion'), $turno->fresh(), $tipo));
        $this->contar($tipo === SalidaSinAtencion::RETIRO ? 'retiros' : 'rechazos');
    }

    /** Enfermería: inicia el triaje, carga un bloque de anamnesis (autoguardado) y lo termina. */
    private function triaje(Carbon $dia, string $hora, Turno $turno): void
    {
        $this->momento($dia, $hora, -10);
        $this->como($this->correo('enfermeria'), function () use ($turno) {
            $enfermeria = $this->usuario('enfermeria');
            $consulta = Preparar::ejecutar($enfermeria, $turno->fresh());
            Autoguardado::ejecutar($enfermeria, $consulta, Request::create('/', 'POST', [
                'version' => $consulta->version(), 'con_anamnesis' => '1',
                'anamnesis' => [['uid' => 'a1', 'tipo_bloque_anamnesis_id' => (string) TipoBloqueAnamnesis::where('nombre', 'Enfermedad actual')->value('id'),
                    'contenido' => 'Texto de prueba (demo): lo que la paciente refiere en el triaje.']],
            ]));
            MarcarLista::ejecutar($enfermeria, $consulta->fresh(), true);
        });
        $this->contar('triajes');
    }

    private function atender(Carbon $dia, string $hora, array $caso, User $medico): void
    {
        $extras = $caso['extras'];
        if ($extras['triaje'] ?? false) {
            $this->triaje($dia, $hora, $caso['turno']);
        }

        $this->como($medico->email, function () use ($dia, $hora, $caso, $medico, $extras) {
            $this->momento($dia, $hora);
            $consulta = Atender::ejecutar($medico, $caso['turno']->fresh(), confirmaUrgencia: true);

            $this->momento($dia, $hora, 5);
            $recetas = [];
            if ($extras['receta'] ?? false) {
                $recetas[] = $this->receta($consulta, 'Medicamento de prueba A (demo)');
            }
            if ($extras['receta_anulada'] ?? false) {
                $recetas[] = $this->receta($consulta, 'Medicamento de prueba B (demo)');
            }

            $this->momento($dia, $hora, 15);
            $consulta = $consulta->fresh();
            Finalizar::ejecutar($medico, $consulta, $this->datosFinales($consulta, $caso), $consulta->version());
            $this->contar('consultas finalizadas');

            $this->momento($dia, $hora, 17);
            $consulta = $consulta->fresh();
            $diagnosticos = $consulta->diagnosticos()->where('activo', true)->pluck('id')->all();
            if ($extras['reposo'] ?? false) {
                EmitirReposo::ejecutar($medico, $consulta, ['dias' => 3, 'desde' => Fecha::hoy(), 'modalidad' => 'RELATIVO', 'modalidad_otra' => null,
                    'empleador' => null, 'observaciones' => 'Texto de prueba (demo).', 'diagnosticos' => [], 'prorroga_de' => null]);
                $this->contar('reposos');
            }
            if ($extras['reposo_anulado'] ?? false) {
                $reposo = EmitirReposo::ejecutar($medico, $consulta, ['dias' => 2, 'desde' => Fecha::hoy(), 'modalidad' => 'DOMICILIARIO', 'modalidad_otra' => null,
                    'empleador' => 'Empresa de prueba (demo)', 'observaciones' => null, 'diagnosticos' => $diagnosticos, 'prorroga_de' => null]);
                AnularReposo::ejecutar($reposo, 'Emitido con la modalidad equivocada (demo).');
                $this->contar('reposos anulados');
            }
            if ($extras['orden'] ?? false) {
                EmitirOrden::ejecutar($medico, $consulta, [
                    'estudios' => Estudio::whereIn('nombre', array_slice(EconomiaDemo::ESTUDIOS, $this->paso++ % 3, 3))->pluck('id')->all(),
                    'otros' => ['Densitometría ósea'], 'indicacion_clinica' => 'Indicación de prueba (demo).', 'diagnosticos' => $diagnosticos,
                ]);
                $this->contar('órdenes de estudio');
            }
            if ($extras['receta_anulada'] ?? false) {
                $this->anularReceta(end($recetas));
            }
        });

        $consulta = Consulta::where('turno_id', $caso['turno']->id)->firstOrFail();
        $this->firmaYTicket($dia, $hora, $consulta);
        if ($extras['adenda'] ?? false) {
            $this->momento($dia, '16:30');
            $this->como($medico->email, fn () => AgregarAdenda::ejecutar($medico, $consulta, 'Dato omitido en la consulta (demo)',
                'Texto de prueba (demo): nota agregada después de finalizar.'));
            $this->contar('adendas');
        }
        if ($extras['desbloqueo'] ?? false) {
            $this->desbloqueo($dia, $consulta);
        }
    }

    /** Lo que el médico deja escrito al finalizar, validado como en la pantalla (FormularioConsulta, reglas finales). */
    private function datosFinales(Consulta $consulta, array $caso): array
    {
        $codigo = in_array($caso['codigo'], ['URGENCIA', 'ENTRE'], true) ? 'CONS-001' : $caso['codigo'];
        $indicaciones = [['uid' => 'i1', 'tipo_indicacion_id' => (string) TipoIndicacion::where('nombre', 'Control')->value('id'),
            'descripcion' => 'Indicación de prueba (demo): volver a control.']];
        if ($caso['extras']['dieta'] ?? false) {
            $dieta = Dieta::activos()->orderBy('nombre')->firstOrFail();
            // "Insertar dieta": el texto de la dieta se copia al final de la indicación, en una línea aparte.
            $indicaciones[] = ['uid' => 'i2', 'tipo_indicacion_id' => (string) TipoIndicacion::where('nombre', 'Dieta')->value('id'),
                'descripcion' => "Indicación de prueba (demo) con dieta:\n".$dieta->texto];
        }
        $informes = isset($caso['extras']['informe']) ? [['uid' => 'e1',
            'procedimiento_id' => (string) Procedimiento::where('codigo', $caso['extras']['informe'])->value('id'),
            'solicitante' => null, 'texto' => 'Informe de prueba (demo), sin valor clínico.']] : [];

        $pedido = Request::create('/', 'POST', [
            'motivo_consulta' => 'Motivo de prueba (demo).',
            'examen' => ['hallazgos' => 'Hallazgos de prueba (demo).'],
            'con_diagnosticos' => '1', 'diagnostico_principal' => '0',
            'diagnosticos' => [['uid' => 'd1', 'codigo_cie10' => self::DIAGNOSTICO[$codigo], 'tipo' => TipoDiagnostico::PRESUNTIVO->value, 'descripcion_adicional' => null]],
            'con_indicaciones' => '1', 'indicaciones' => $indicaciones,
            'con_informes' => $informes ? '1' : null, 'informes' => $informes,
        ]);
        [$datos] = FormularioConsulta::validar($pedido, $consulta, [FormularioConsulta::PREPARACION, FormularioConsulta::CLINICO], final: true);

        return $datos;
    }

    /** Crea y emite una receta de un renglón, como RecetaController (store y emitir): número, fecha y snapshot. */
    private function receta(Consulta $consulta, string $medicamento): Receta
    {
        return DB::transaction(function () use ($consulta, $medicamento) {
            $receta = $consulta->recetas()->create(['observaciones' => null]);
            $receta->auditarRelacion('detalles', fn () => $receta->detalles()->create([
                'medicamento' => $medicamento, 'cantidad' => '1 caja', 'indicacion_uso' => 'Indicación de uso de prueba (demo).', 'orden' => 1,
            ]));
            $receta = $receta->fresh();
            $hoja = HojaReceta::datos($receta);
            if (HojaReceta::faltaIndicacion($hoja) || HojaReceta::excede($hoja)) {
                throw new RuntimeException('La receta demo no entra en la hoja.');
            }
            $numero = Receta::siguienteNumero();
            $receta->forceFill([
                'numero' => $numero, 'fecha' => Fecha::hoy()->format('Y-m-d'), 'emitida_en' => now(),
                'estado_id' => Estado::idDe(Estado::EMITIDO), 'snapshot' => [...$hoja, 'numero' => $numero],
            ])->save();
            $this->contar('recetas emitidas');

            return $receta;
        });
    }

    private function anularReceta(Receta $receta): void
    {
        $receta->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_en' => now(), 'motivo_anulacion' => 'Medicamento equivocado (demo).'])->save();
        $this->contar('recetas anuladas');
    }

    /** Recepción: la firma recibida de cada prestación de aseguradora y el ticket de la consulta. */
    private function firmaYTicket(Carbon $dia, string $hora, Consulta $consulta): void
    {
        $prestaciones = TicketVisacion::prestaciones($consulta);
        if ($prestaciones->isEmpty()) {
            return;
        }
        $this->momento($dia, $hora, 20);
        $this->como($this->correo('recepcion'), function () use ($consulta, $prestaciones) {
            TicketVisacion::ejecutar($consulta);
            foreach ($prestaciones as $prestacion) {
                FirmaRecibida::marcar($this->usuario('recepcion'), $prestacion);
                $this->contar('firmas recibidas');
            }
        });
        $this->contar('tickets');
    }

    /** Supervisión médica desbloquea la consulta y corrige el motivo y los hallazgos (un solo guardado, con versión previa). */
    private function desbloqueo(Carbon $dia, Consulta $consulta): void
    {
        $supervision = $this->usuario('supervision');
        $this->como($supervision->email, function () use ($dia, $consulta, $supervision) {
            $this->momento($dia, '17:00');
            $desbloqueo = Desbloquear::abrir($supervision, $consulta, 'Corrección del motivo pedida por la profesional (demo).');
            $this->momento($dia, '17:05');
            [$datos] = FormularioConsulta::validar(Request::create('/', 'POST', [
                'motivo_consulta' => 'Motivo de prueba corregido (demo).', 'examen' => ['hallazgos' => 'Hallazgos de prueba corregidos (demo).'],
            ]), $consulta, CorregirConsulta::GRUPOS, final: true);
            CorregirConsulta::ejecutar($supervision, $desbloqueo, $datos);
        });
        $this->contar('desbloqueos');
    }

    /** Caja 1 cobra a cada paciente del día lo que tenga para cobrar (solo su parte), rotando los medios de pago. */
    private function cobrarDelDia(int $indice, array $casos): void
    {
        $ultimo = $indice === self::DIAS - 1;
        $this->como($this->correo('caja1'), function () use ($indice, $casos, $ultimo) {
            foreach (collect($casos)->pluck('paciente')->unique('id') as $paciente) {
                if ($ultimo && in_array($this->pacientes->search(fn ($p) => $p->id === $paciente->id), self::PARA_HOY, true)) {
                    continue;
                }
                if ($cobro = $this->cobrar('caja1', $paciente)) {
                    $this->cobros[$indice][] = $cobro;
                }
            }
        });
    }

    /** Un cobro con todo lo pendiente de la paciente: efectivo, transferencia, tarjeta o mixto, por turno. */
    private function cobrar(string $cajero, Paciente $paciente, ?string $medio = null): ?Cobro
    {
        $prestaciones = Cobrar::pendientes()->where('paciente_id', $paciente->id)->get();
        if ($prestaciones->isEmpty()) {
            return null;
        }
        $total = $prestaciones->sum(fn (Prestacion $prestacion) => $prestacion->montoPaciente());
        $medio ??= ['EFECTIVO', 'TRANSFERENCIA', 'TARJETA', 'MIXTO'][$this->paso++ % 4];
        $referencia = fn () => 'REF-DEMO-'.str_pad((string) ++$this->paso, 5, '0', STR_PAD_LEFT);
        $lineas = match ($medio) {
            'MIXTO' => [
                ['medio_pago_id' => $this->medios['EFECTIVO'], 'monto' => intdiv($total, 2), 'referencia' => null],
                ['medio_pago_id' => $this->medios['TARJETA'], 'monto' => $total - intdiv($total, 2), 'referencia' => $referencia()],
            ],
            'EFECTIVO' => [['medio_pago_id' => $this->medios['EFECTIVO'], 'monto' => $total, 'referencia' => null]],
            default => [['medio_pago_id' => $this->medios[$medio], 'monto' => $total, 'referencia' => $referencia()]],
        };
        $cobro = Cobrar::ejecutar($this->usuario($cajero), $paciente->id, $prestaciones->modelKeys(), $lineas);
        $this->contar('cobros');

        return $cobro;
    }

    /** Día 4: una reversión pedida y aprobada (y se cobra de nuevo). Día 6: una pedida y rechazada. */
    private function reversiones(int $indice, Carbon $dia): void
    {
        $caja1 = $this->usuario('caja1');
        $gerencia = $this->usuario('gerencia');
        if ($indice === 3) {
            $cobro = $this->cobros[1][0];
            $this->momento($dia, '17:45');
            $solicitud = $this->como($caja1->email, fn () => RevertirCobro::solicitar($caja1, $cobro, 'Cobro duplicado por error (demo).'));
            $this->momento($dia, '17:50');
            $this->como($gerencia->email, fn () => RevertirCobro::aprobar($gerencia, $solicitud, 'Verificado con el comprobante (demo).'));
            $this->momento($dia, '17:55');
            $this->como($caja1->email, fn () => $this->cobrar('caja1', Paciente::findOrFail($cobro->paciente_id), 'TRANSFERENCIA'));
            $this->contar('reversiones aprobadas');
        }
        if ($indice === 5) {
            $cobro = $this->cobros[4][0];
            $this->momento($dia, '17:45');
            $solicitud = $this->como($caja1->email, fn () => RevertirCobro::solicitar($caja1, $cobro, 'La paciente pide la devolución (demo).'));
            $this->momento($dia, '17:50');
            $this->como($gerencia->email, fn () => RevertirCobro::rechazar($gerencia, $solicitud, 'El cobro es correcto: no corresponde devolverlo (demo).'));
            $this->contar('reversiones rechazadas');
        }
    }

    /** Caja 1 cierra con lo esperado (día 3: con un faltante y su motivo) y Gerencia aprueba (día 5: primero la devuelve). */
    private function cierreDeCaja(int $indice, Carbon $dia): void
    {
        $caja1 = $this->usuario('caja1');
        $gerencia = $this->usuario('gerencia');
        $caja = Caja::query()->abiertaDe($caja1)->firstOrFail();
        $contado = fn () => collect(CerrarCaja::mediosADeclarar($caja))->mapWithKeys(fn (MedioPago $medio) => [$medio->id => $caja->esperadoPorMedio()[$medio->id] ?? 0])->all();

        $this->momento($dia, '18:30');
        $declarado = $contado();
        $motivo = null;
        if ($indice === 2) {
            $declarado[$this->medios['EFECTIVO']] -= 10000;
            $motivo = 'Faltante de cambio en el cajón (demo).';
        }
        $this->como($caja1->email, fn () => CerrarCaja::ejecutar($caja1, $caja, $declarado, $motivo));

        $this->momento($dia, '19:00');
        if ($indice === 4) {
            $this->como($gerencia->email, fn () => RevisarCierre::devolver($gerencia, $caja, 'Revise lo contado en tarjeta (demo).'));
            $this->momento($dia, '19:15');
            $this->como($caja1->email, fn () => CerrarCaja::ejecutar($caja1, $caja->fresh(), $contado(), null));
            $this->momento($dia, '19:30');
            $this->contar('cierres devueltos');
        }
        $this->como($gerencia->email, fn () => RevisarCierre::aprobar($gerencia, $caja->fresh(), $indice === 2 ? 'Diferencia revisada (demo).' : null));
        $this->contar('cierres aprobados');
    }

    /** Hoy: la Caja 2 abre y cobra lo que quedó de ayer, revierte uno directo y pide la reversión de un cobro de la Caja 1. */
    private function hoy(): void
    {
        $caja2 = $this->usuario('caja2');
        $this->como($caja2->email, function () use ($caja2) {
            AbrirCaja::ejecutar($caja2, 300000);
            $this->contar('cajas abiertas');
            [$primera, $segunda] = array_map(fn (int $orden) => $this->pacientes[$orden], self::PARA_HOY);
            $this->cobrar('caja2', $primera, 'MIXTO');
            $cobro = $this->cobrar('caja2', $segunda, 'TARJETA');
            RevertirCobro::ejecutar($caja2, $cobro, 'Se pasó la tarjeta equivocada (demo).');
            $this->contar('reversiones directas');
            $this->cobrar('caja2', $segunda, 'EFECTIVO');
            RevertirCobro::solicitar($caja2, $this->cobros[self::DIAS - 2][0], 'La paciente reclama un cobro de más (demo).');
            $this->contar('solicitudes de reversión pendientes');
        });
    }
}
