<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\Demo\AgendaDemo;
use Database\Seeders\Demo\BaseClinica;
use Database\Seeders\Demo\DiasDemo;
use Database\Seeders\Demo\EconomiaDemo;
use Database\Seeders\Demo\EtapaDemo;
use Database\Seeders\Demo\FacturacionDemo;
use Database\Seeders\Demo\LiquidacionesDemo;
use Database\Seeders\Demo\PacientesDemo;
use Database\Seeders\Demo\SegurosDemo;
use Database\Seeders\Demo\UsuariosDemo;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Datos de DESARROLLO para recorrer todo el sistema (docs/datos-demo.md). Se niega a correr en producción y sin
 * --confirmo-desarrollo. Es idempotente: no duplica y nunca modifica ni borra lo que no sea demo.
 */
class DatosDemo extends Command
{
    protected $signature = 'clinexa:datos-demo
        {--confirmo-desarrollo : Confirma que esta es una base de DESARROLLO (obligatorio)}
        {--password= : Contraseña de los usuarios demo (si no se pasa, se pide por consola)}';

    protected $description = 'Carga datos de prueba de desarrollo (usuarios, pacientes, agenda, economía, seguros, facturación, días simulados y liquidaciones). Idempotente. Nunca en producción';

    /** Tablas cuyos conteos se muestran antes y después. */
    private const TABLAS = [
        'sucursales', 'consultorios', 'procedimientos', 'medios_pago', 'personas', 'users', 'profesionales', 'personal_salud',
        'pacientes', 'historias_clinicas', 'disponibilidades', 'cupos_entre_pacientes', 'turnos',
        'estudios', 'dietas', 'proveedores', 'tipos_equipo', 'equipos', 'tarifas', 'acuerdos_reparto',
        'responsables_pago', 'convenios', 'planes_convenio', 'tarifas_convenio', 'coberturas_paciente', 'visaciones', 'lineas_visacion',
        'consultas', 'informes_estudio', 'recetas', 'reposos_medicos', 'ordenes_estudio', 'adendas_consulta', 'desbloqueos_consulta',
        'consulta_versiones', 'salidas_sin_atencion', 'prestaciones', 'repartos_prestacion', 'lineas_reparto',
        'cajas', 'cierres_caja', 'cobros', 'lineas_cobro', 'movimientos_caja', 'solicitudes_reversion_cobro',
        'liquidaciones', 'lineas_liquidacion', 'pagos_aseguradora', 'clinicas', 'timbrados', 'facturas', 'detalles_factura',
        'solicitudes_anulacion_factura', 'logs_auditoria',
    ];

    /** Etapas que se cargan "la víspera" del primer día simulado, para que su fecha en Auditoría quede antes de usarlas. */
    private const EN_LA_VISPERA = [EconomiaDemo::class, SegurosDemo::class, FacturacionDemo::class];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Este comando es solo de desarrollo: no corre con APP_ENV=production.');

            return self::FAILURE;
        }
        if (! $this->option('confirmo-desarrollo')) {
            $this->error('Falta --confirmo-desarrollo: confirme que la base de DB_DATABASE es de desarrollo.');

            return self::FAILURE;
        }

        $clave = $this->clave();
        if ($clave === null) {
            return self::FAILURE;
        }

        $this->line('Base: '.DB::connection()->getDatabaseName().' (APP_ENV='.app()->environment().')');
        $antes = $this->conteos();

        $etapas = [
            'Base de la clínica' => app(BaseClinica::class),
            'Usuarios' => app(UsuariosDemo::class)->conClave($clave),
            'Pacientes' => app(PacientesDemo::class),
            'Agenda' => app(AgendaDemo::class),
            'Catálogos y economía' => app(EconomiaDemo::class),
            'Seguros' => app(SegurosDemo::class),
            'Facturación' => app(FacturacionDemo::class),
            'Días simulados' => app(DiasDemo::class),
            'Liquidaciones' => app(LiquidacionesDemo::class),
        ];
        $vispera = DiasDemo::vispera();
        $original = Carbon::getTestNow();

        try {
            // Todo o nada: si una etapa falla, no queda nada a medias.
            DB::transaction(function () use ($etapas, $vispera, $original) {
                foreach ($etapas as $nombre => $etapa) {
                    $etapa->setContainer($this->laravel)->setCommand($this);
                    in_array($etapa::class, self::EN_LA_VISPERA, true) && Carbon::setTestNow($vispera);
                    try {
                        $etapa->run();
                    } finally {
                        Carbon::setTestNow($original);
                    }
                    $creados = collect($etapa->creados)->map(fn ($n, $que) => "{$n} {$que}")->implode(', ');
                    $this->line("  {$nombre}: ".($creados ?: 'nada nuevo'));
                }
            });
        } catch (Throwable $e) {
            $this->error('No se cargó nada. '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine(), verbosity: 'v');

            return self::FAILURE;
        }

        $despues = $this->conteos();
        $this->table(['Tabla', 'Antes', 'Después'], collect(self::TABLAS)->map(fn ($t) => [$t, $antes[$t], $despues[$t]])->all());
        $this->table(['Usuario demo', 'Perfil'], User::with('perfiles')->where('email', 'like', '%@'.EtapaDemo::DOMINIO)->orderBy('id')->get()
            ->map(fn (User $usuario) => [$usuario->email, $usuario->perfiles->pluck('nombre')->join(', ')])->all());
        $this->info('Listo. Correrlo de nuevo no duplica nada.');

        return self::SUCCESS;
    }

    private function clave(): ?string
    {
        $clave = $this->option('password');
        if ($clave === null && $this->input->isInteractive()) {
            $clave = $this->secret('Contraseña para los usuarios demo (no se guarda en ningún archivo)');
            if ($clave !== $this->secret('Repítala')) {
                $this->error('Las contraseñas no coinciden.');

                return null;
            }
        }
        if ($clave === null || $clave === '') {
            $this->error('Falta la contraseña: use --password= o corra el comando en una terminal interactiva.');

            return null;
        }
        $validador = Validator::make(['clave' => $clave], ['clave' => ['string', Password::min(10)]]);
        if ($validador->fails()) {
            $this->error('Contraseña muy débil: '.$validador->errors()->first('clave'));

            return null;
        }

        return $clave;
    }

    /** @return array<string, int> */
    private function conteos(): array
    {
        return collect(self::TABLAS)->mapWithKeys(fn ($tabla) => [$tabla => DB::table($tabla)->count()])->all();
    }
}
