<?php

/*
 * clinexa:datos-demo: solo desarrollo, idempotente, sin tocar lo que no es demo, y todo se reconoce como demo.
 * Datos inventados; la contraseña de la prueba no es de nadie.
 */

use App\Models\Caja;
use App\Models\CatalogoCIE10;
use App\Models\Disponibilidad;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Procedimiento;
use App\Models\Turno;
use App\Models\TipoDocumento;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatosRealesClinicaSeeder;
use Database\Seeders\Demo\BaseClinica;
use Database\Seeders\Demo\DiasDemo;
use Database\Seeders\Demo\EconomiaDemo;
use App\Support\Ruc;
use Database\Seeders\GeografiaSeeder;
use Database\Seeders\ModuloSistemaSeeder;
use Database\Seeders\PerfilAdministradorSeeder;
use Database\Seeders\PerfilesPredefinidosSeeder;

const DATOS_DEMO_CLAVE = 'Clave-De-Prueba-9876';

beforeEach(function () {
    // Martes 27/10/2026 09:00 en Paraguay: los 10 días siguientes incluyen dos fines de semana.
    Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.zona_horaria_local')));
    $this->seed([ModuloSistemaSeeder::class, PerfilAdministradorSeeder::class, PerfilesPredefinidosSeeder::class, GeografiaSeeder::class, DatosRealesClinicaSeeder::class]);
    // Solo los códigos CIE-10 que usan los días simulados (el catálogo completo es lento de sembrar).
    foreach (DiasDemo::CODIGOS_CIE10 as $codigo) {
        CatalogoCIE10::create(['codigo' => $codigo, 'descripcion' => "Diagnóstico de prueba {$codigo}", 'capitulo' => 'XXI']);
    }
});

afterEach(fn () => Carbon::setTestNow());

$correr = fn () => test()->artisan('clinexa:datos-demo', ['--confirmo-desarrollo' => true, '--password' => DATOS_DEMO_CLAVE]);

test('se niega sin --confirmo-desarrollo y en producción, y no crea nada', function () {
    $this->artisan('clinexa:datos-demo', ['--password' => DATOS_DEMO_CLAVE])->assertFailed();
    expect(User::count())->toBe(0);

    $this->app['env'] = 'production';
    $this->artisan('clinexa:datos-demo', ['--confirmo-desarrollo' => true, '--password' => DATOS_DEMO_CLAVE])->assertFailed();
    expect(User::count())->toBe(0)->and(Persona::where('email', 'like', '%@prueba.test')->count())->toBe(0);
});

test('exige una contraseña fuerte y no la guarda en claro', function () use ($correr) {
    $this->artisan('clinexa:datos-demo', ['--confirmo-desarrollo' => true, '--password' => 'corta'])->assertFailed();
    expect(User::count())->toBe(0);

    $correr()->assertSuccessful();
    $usuario = User::where('email', 'gerencia@prueba.test')->sole();
    expect($usuario->password)->not->toBe(DATOS_DEMO_CLAVE)->and(Hash::check(DATOS_DEMO_CLAVE, $usuario->password))->toBeTrue();
});

test('crea un usuario por perfil con un solo perfil, todo reconocible como demo', function () use ($correr) {
    $correr()->assertSuccessful();

    $usuarios = User::with('perfiles', 'persona')->get();
    expect($usuarios)->toHaveCount(11)
        ->and($usuarios->every(fn ($u) => str_ends_with($u->email, '@prueba.test') && $u->perfiles->count() === 1))->toBeTrue()
        ->and($usuarios->pluck('persona.nro_documento')->sort()->values()->all())->toBe(array_map('strval', range(9000001, 9000011)))
        ->and($usuarios->firstWhere('email', 'medica1@prueba.test')->perfiles->sole()->codigo)->toBe('MEDICO')
        ->and($usuarios->firstWhere('email', 'caja2@prueba.test')->perfiles->sole()->codigo)->toBe('CAJA')
        ->and($usuarios->firstWhere('email', 'supervision@prueba.test')->perfiles->sole()->codigo)->toBe('SUPERVISION_MEDICA');

    $pacientes = Paciente::with('persona')->get();
    expect($pacientes)->toHaveCount(12)
        ->and($pacientes->every(fn ($p) => $p->persona->sexo === 'F' && $p->persona->edad() >= 18 && str_starts_with($p->persona->nro_documento, '9000')))->toBeTrue()
        ->and($pacientes->pluck('persona.ciudad_id')->filter()->unique()->count())->toBeGreaterThan(5);
});

test('la agenda tiene disponibilidades de lunes a viernes y turnos de todos los tipos y duraciones', function () use ($correr) {
    $correr()->assertSuccessful();

    expect(Disponibilidad::count())->toBe(20)
        ->and(Disponibilidad::pluck('dia_semana')->unique()->sort()->values()->all())->toBe(['JUE', 'LUN', 'MAR', 'MIE', 'VIE'])
        ->and(Disponibilidad::pluck('duracion_turno_minutos')->unique()->all())->toBe([20]);

    $turnos = Turno::with('procedimiento')->get();
    $hoy = '2026-10-27';
    expect($turnos->where('tipo.value', 'URGENCIA')->filter(fn ($t) => $t->fecha->format('Y-m-d') === '2026-10-27'))->toHaveCount(1)
        ->and($turnos->where('tipo.value', 'ENTRE_PACIENTES')->count())->toBeGreaterThan(0)
        ->and($turnos->where('tipo.value', 'NORMAL')->every(fn ($t) => $t->fecha->format('Y-m-d') !== $hoy && $t->fecha->dayOfWeekIso <= 5))->toBeTrue()
        ->and($turnos->where('tipo.value', 'NORMAL')->map(fn ($t) => $t->procedimiento->duracion_estimada_minutos)->unique()->sort()->values()->all())
        ->toBe([20, 30, 40, 90, 120]);
});

test('es idempotente: correrlo dos veces no duplica nada', function () use ($correr) {
    $correr()->assertSuccessful();
    $conteos = fn () => collect(['personas', 'users', 'pacientes', 'profesionales', 'disponibilidades', 'cupos_entre_pacientes', 'turnos', 'procedimientos', 'consultorios', 'medios_pago',
        'tarifas', 'acuerdos_reparto', 'convenios', 'coberturas_paciente', 'visaciones', 'consultas', 'prestaciones', 'cajas', 'cobros',
        'liquidaciones', 'lineas_liquidacion', 'pagos_aseguradora', 'logs_auditoria'])
        ->mapWithKeys(fn ($tabla) => [$tabla => DB::table($tabla)->count()])->all();
    $antes = $conteos();
    $hash = User::where('email', 'gerencia@prueba.test')->value('password');

    test()->artisan('clinexa:datos-demo', ['--confirmo-desarrollo' => true, '--password' => 'Otra-Clave-Distinta-123'])->assertSuccessful();

    expect($conteos())->toBe($antes)
        ->and(User::where('email', 'gerencia@prueba.test')->value('password'))->toBe($hash);
});

test('el RUC de lo demo va en su campo: aseguradoras sin documento, la Dra. Benítez con su dígito; sobre una base ya cargada se completa sin duplicar', function () use ($correr) {
    $correr()->assertSuccessful();
    $aseguradoras = fn () => Persona::where('tipo_persona', 'JURIDICA')->orderBy('ruc')->get();
    expect($aseguradoras()->pluck('ruc')->all())->toBe([Ruc::conDigito('80900001'), Ruc::conDigito('80900002')])
        ->and($aseguradoras()->every(fn ($p) => $p->tipo_documento_id === null && $p->nro_documento === null))->toBeTrue()
        ->and(Ruc::conDigito('9000007'))->toBe('9000007-2')
        ->and(Persona::where('nro_documento', '9000007')->sole()->ruc)->toBe('9000007-2')
        ->and(Persona::whereNotNull('ruc')->get()->every(fn ($p) => Ruc::esValido($p->ruc)))->toBeTrue();

    // Una base cargada antes del campo RUC: el RUC como documento (la migración ya lo había copiado), la Dra. Benítez sin RUC.
    $ruc = TipoDocumento::where('codigo', 'RUC')->value('id');
    $personas = Persona::count();
    foreach ($aseguradoras() as $aseguradora) {
        DB::table('personas')->where('id', $aseguradora->id)->update(['tipo_documento_id' => $ruc, 'nro_documento' => $aseguradora->ruc, 'ruc' => null]);
    }
    DB::table('personas')->where('nro_documento', '9000007')->update(['ruc' => null]);

    $correr()->assertSuccessful();

    expect(Persona::count())->toBe($personas)
        ->and($aseguradoras()->pluck('ruc')->all())->toBe([Ruc::conDigito('80900001'), Ruc::conDigito('80900002')])
        ->and(Persona::where('tipo_documento_id', $ruc)->where('tipo_persona', 'JURIDICA')->count())->toBe(2) // el documento viejo no se toca
        ->and(Persona::where('nro_documento', '9000007')->sole()->ruc)->toBe('9000007-2');
});

test('no toca lo que no es demo', function () use ($correr) {
    $ajena = Persona::where('nro_documento', '4441089-1')->sole();
    $antes = $ajena->only(['apellidos', 'nombres', 'email', 'telefono', 'updated_at']);
    $personasAjenas = Persona::count();

    $correr()->assertSuccessful();

    expect(Persona::where('nro_documento', '4441089-1')->sole()->only(['apellidos', 'nombres', 'email', 'telefono', 'updated_at']))->toEqual($antes)
        ->and(Persona::where('email', 'not like', '%@prueba.test')->count())->toBe($personasAjenas);
});

test('la auditoría deja los cambios a nombre de los usuarios demo', function () use ($correr) {
    $correr()->assertSuccessful();

    $autores = DB::table('logs_auditoria')->join('users', 'users.id', '=', 'logs_auditoria.usuario_id')->pluck('users.email')->unique()->sort()->values()->all();
    expect($autores)->toBe(['caja1@prueba.test', 'caja2@prueba.test', 'compras@prueba.test', 'enfermeria@prueba.test', 'facturacion@prueba.test',
        'gerencia@prueba.test', 'medica1@prueba.test', 'medico2@prueba.test', 'recepcion@prueba.test', 'supervision@prueba.test']);
});

test('la base de la clínica agrega lo que falta sin renombrar ni duplicar lo existente', function () {
    Procedimiento::where('codigo', 'ECO-TV')->update(['nombre' => 'Nombre cambiado en el panel']);

    (new BaseClinica)->run();
    (new BaseClinica)->run();

    expect(Procedimiento::where('codigo', 'ECO-MORFO')->sole())
        ->nombre->toBe('Ecografía Morfológica')->duracion_estimada_minutos->toBe(120)
        ->and(Procedimiento::where('codigo', 'ECO-TV')->value('nombre'))->toBe('Nombre cambiado en el panel')
        ->and(Procedimiento::count())->toBe(8)
        ->and(DB::table('consultorios')->pluck('nombre')->sort()->values()->all())->toBe(['101', '102'])
        ->and(DB::table('medios_pago')->count())->toBe(3);
});

test('el dígito verificador del RUC sigue el módulo 11 de la DNIT', function () {
    expect(Ruc::conDigito('44444401'))->toBe('44444401-7')
        ->and(Ruc::esValido('44444401-7'))->toBeTrue()
        ->and(Ruc::esValido('44444401-3'))->toBeFalse()
        ->and(Ruc::digito('1'))->toBe(9)  // 1*2 = 2: resto 2 -> 11 - 2
        ->and(Ruc::digito('0'))->toBe(0); // resto 0 -> 0
});

test('la etapa 2 simula los días pasados con todos los casos económicos y clínicos', function () use ($correr) {
    $correr()->assertSuccessful();
    $estado = fn (string $tabla) => DB::table($tabla)->join('estados', 'estados.id', '=', "{$tabla}.estado_id")->pluck('estados.codigo');

    // Los hitos, en la víspera del primer día simulado (lunes 12/10/2026 a medianoche en Paraguay).
    expect(DB::table('hitos_sistema')->pluck('fecha_hora')->unique()->values()->all())->toBe(['2026-10-12 03:00:00']);

    $prestaciones = DB::table('prestaciones')->join('procedimientos', 'procedimientos.id', '=', 'prestaciones.procedimiento_id')
        ->leftJoin('lineas_visacion', 'lineas_visacion.id', '=', 'prestaciones.linea_visacion_id')
        ->select('prestaciones.*', 'procedimientos.codigo', 'lineas_visacion.resultado', 'lineas_visacion.copago')->get();
    expect($prestaciones->pluck('fecha_servicio')->unique()->count())->toBe(10)
        ->and($prestaciones->where('codigo', 'PAP-COLPO')->every(fn ($p) => $p->monto === null))->toBeTrue()
        ->and($prestaciones->where('pagador', 'ASEGURADORA')->pluck('copago')->unique()->sort()->values()->all())->toBe(['MONTO_FIJO', 'NINGUNO', 'PORCENTAJE'])
        ->and($prestaciones->where('resultado', 'NO_CUBIERTO')->sole()->pagador)->toBe('PACIENTE')
        ->and($prestaciones->filter(fn ($p) => str_contains((string) $p->aviso_cobertura, 'no incluye este procedimiento')))->toHaveCount(1)
        ->and($prestaciones->filter(fn ($p) => str_contains((string) $p->aviso_cobertura, 'venció')))->not->toBeEmpty()
        ->and($prestaciones->whereNotNull('informe_estudio_id')->count())->toBeGreaterThan(0)
        ->and($prestaciones->where('pagador', 'ASEGURADORA')->every(fn ($p) => $p->firma_recibida_en !== null))->toBeTrue();

    // Reparto: el ecógrafo cobra su parte; el Dr. Duarte, sin acuerdo, queda pendiente.
    $duarte = DB::table('profesionales')->join('personas', 'personas.id', '=', 'profesionales.persona_id')->where('personas.email', 'medico2@prueba.test')->value('profesionales.id');
    $repartos = DB::table('repartos_prestacion')->join('prestaciones', 'prestaciones.id', '=', 'repartos_prestacion.prestacion_id')
        ->join('estados', 'estados.id', '=', 'repartos_prestacion.estado_id')->select('estados.codigo', 'prestaciones.profesional_id')->get();
    expect($repartos->where('profesional_id', $duarte)->pluck('codigo')->unique()->all())->toBe(['PENDIENTE'])
        ->and($repartos->where('profesional_id', '!=', $duarte)->pluck('codigo')->unique()->all())->toBe(['REPARTIDO'])
        ->and(DB::table('lineas_reparto')->where('beneficiario', 'EQUIPO')->count())->toBeGreaterThan(0);

    // Clínica: recetas, reposos y órdenes, una anulada de cada (receta y reposo), adenda, desbloqueo usado, retiro con cargo y rechazo.
    expect($estado('recetas')->countBy()->all())->toMatchArray(['ANULADO' => 1])
        ->and($estado('reposos_medicos')->countBy()->all())->toMatchArray(['ANULADO' => 1])
        ->and(DB::table('ordenes_estudio')->count())->toBeGreaterThan(0)
        ->and(DB::table('adendas_consulta')->count())->toBe(1)
        ->and($estado('desbloqueos_consulta')->all())->toBe(['REALIZADO'])
        ->and(DB::table('consulta_versiones')->count())->toBe(1)
        ->and(DB::table('salidas_sin_atencion')->pluck('cargo_pendiente', 'tipo')->all())->toEqual(['RETIRO' => true, 'RECHAZO' => false])
        ->and(DB::table('indicaciones')->get()->filter(fn ($i) => str_ends_with($i->descripcion, "\n".EconomiaDemo::DIETAS['Dieta de prueba A (demo)'])))->not->toBeEmpty();

    // Caja: Caja 1 con los 10 días aprobados (uno con diferencia y uno devuelto antes), Caja 2 abierta hoy.
    $cajas = Caja::with('cajero')->get();
    expect($cajas->filter(fn ($c) => $c->cajero->email === 'caja1@prueba.test')->every(fn ($c) => $c->estado->codigo === 'APROBADO'))->toBeTrue()
        ->and($cajas->filter(fn ($c) => $c->cajero->email === 'caja2@prueba.test')->sole())->fecha->format('Y-m-d')->toBe('2026-10-27')
        ->and($estado('cierres_caja')->countBy()->all())->toMatchArray(['DEVUELTO' => 1, 'APROBADO' => 10])
        ->and(DB::table('cierre_caja_medios')->where('diferencia', '!=', 0)->count())->toBe(1)
        ->and(DB::table('lineas_cobro')->join('medios_pago', 'medios_pago.id', '=', 'lineas_cobro.medio_pago_id')->distinct()->pluck('medios_pago.codigo')->sort()->values()->all())
        ->toBe(['EFECTIVO', 'TARJETA', 'TRANSFERENCIA'])
        ->and($estado('solicitudes_reversion_cobro')->sort()->values()->all())->toBe(['APROBADO', 'PENDIENTE', 'RECHAZADO'])
        ->and($estado('cobros')->countBy()->all())->toMatchArray(['REVERTIDO' => 2]);
})->group('lento'); // corre el comando completo (docs/pruebas.md)

test('las liquidaciones: la primera aseguradora aprobada con un pago parcial, la segunda en preparación, con corte hoy; también sobre una base ya cargada', function () use ($correr) {
    $correr()->assertSuccessful();
    $liquidaciones = fn () => DB::table('liquidaciones')->join('estados', 'estados.id', '=', 'liquidaciones.estado_id')
        ->join('responsables_pago', 'responsables_pago.id', '=', 'liquidaciones.responsable_pago_id')->join('personas', 'personas.id', '=', 'responsables_pago.persona_id')
        ->orderBy('personas.ruc')->get(['liquidaciones.*', 'estados.codigo', 'personas.ruc']);
    $verificar = function () use ($liquidaciones) {
        [$primera, $segunda] = $liquidaciones()->all();
        $lineas = DB::table('lineas_liquidacion')->whereNull('liberada_en');
        $pagos = DB::table('pagos_aseguradora')->where('liquidacion_id', $primera->id)->get();
        expect($primera->ruc)->toBe(Ruc::conDigito('80900001'))->and($primera->codigo)->toBe('PARCIAL')->and(substr($primera->fecha_corte, 0, 10))->toBe('2026-10-27')
            ->and($primera->facturacion)->toBe('PENDIENTE')
            ->and($segunda->ruc)->toBe(Ruc::conDigito('80900002'))->and($segunda->codigo)->toBe('EN_PREPARACION')
            ->and((int) $primera->total)->toBe((int) (clone $lineas)->where('liquidacion_id', $primera->id)->sum('monto'))
            ->and((clone $lineas)->where('liquidacion_id', $segunda->id)->count())->toBeGreaterThan(0)
            // Entraron todas las de aseguradora pendientes de liquidar (en el demo, todas firmadas).
            ->and((clone $lineas)->count())->toBe(DB::table('prestaciones')->where('liquidacion', 'PENDIENTE')->count())
            ->and($pagos)->toHaveCount(1)->and((int) $pagos->sole()->monto)->toBeLessThan((int) $primera->total)->toBeGreaterThan(0);
    };
    $verificar();

    // Sobre una base ya cargada (los días simulados no se repiten), la etapa las vuelve a armar.
    DB::table('pagos_aseguradora')->delete();
    DB::table('lineas_liquidacion')->delete();
    DB::table('liquidaciones')->delete();
    $correr()->assertSuccessful();
    $verificar();
})->group('lento'); // corre el comando completo (docs/pruebas.md)
