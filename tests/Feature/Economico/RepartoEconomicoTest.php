<?php

/*
 * Reparto económico (docs/reparto-economico.md): el acuerdo del profesional (general o por procedimiento), el del equipo, la suma
 * exacta con redondeo, el profesional dueño del equipo, las pendientes (sin acuerdo o más de 100 %), "Repartir" sin duplicar,
 * tarifar una pendiente, el reparto fijo ante cambios, la anulación, la adenda, la falla que no frena el cierre, los equipos,
 * los permisos y la auditoría. Datos, precios y porcentajes inventados.
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\AcuerdoReparto;
use App\Models\Consulta;
use App\Models\Equipo;
use App\Models\Estado;
use App\Models\LineaReparto;
use App\Models\LogAuditoria;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Proveedor;
use App\Models\RepartoPrestacion;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\AltaAcuerdo;
use App\Support\Repartos\CambiarAcuerdo;
use App\Support\Repartos\Porcentaje;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    hcEscenario(); // "hoy": martes 06/10/2026, 09:00 en Paraguay
    PerfilesPredefinidos::asegurar();
    $this->consultaProc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->eco = Procedimiento::create(['codigo' => 'ECO-PRUEBA', 'nombre' => 'Ecografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    $this->gerente = repartoEconomicoUsuario(PerfilesPredefinidos::GERENCIA, ['apellidos' => 'Gómez', 'nombres' => 'Ana']);
    // Las migraciones guardaron la hora real en que corrieron; el "hoy" de estos tests es anterior: los módulos empiezan el 01/10/2026.
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, RepartirPrestacion::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
});

afterEach(fn () => Carbon::setTestNow());

function repartoEconomicoUsuario(string $codigo, array $persona = []): User
{
    $factory = User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()]);

    return ($persona ? $factory->conPersona($persona) : $factory)->create();
}

function repartoEconomicoTarifa(int $monto, ?Procedimiento $procedimiento = null): void
{
    AltaTarifa::ejecutar(test()->gerente, ($procedimiento ?? test()->consultaProc)->id, null, $monto, Carbon::parse('2026-10-01'));
}

/** Acuerdo del profesional (por defecto, el general de la Dra. Benítez). */
function repartoEconomicoAcuerdo(string $porcentaje, ?Procedimiento $procedimiento = null, ?Profesional $profesional = null): AcuerdoReparto
{
    return AltaAcuerdo::ejecutar(test()->gerente, AcuerdoReparto::PROFESIONAL, ($profesional ?? test()->profesional)->id, $procedimiento?->id, null, Porcentaje::desdeTexto($porcentaje), Carbon::parse('2026-10-01'));
}

/** Un equipo activo que usa la ecografía, con su dueño (un proveedor de esa persona, o uno nuevo). */
function repartoEconomicoEquipo(?int $personaId = null): Equipo
{
    $proveedor = Proveedor::create(['persona_id' => $personaId ?? Persona::factory()->create(['apellidos' => 'Equipos', 'nombres' => 'Dueño'])->id]);
    $equipo = Equipo::create(['nombre' => 'Ecógrafo de prueba', 'tipo_equipo_id' => TipoEquipo::create(['nombre' => 'Ecógrafo'])->id, 'proveedor_id' => $proveedor->id]);
    $equipo->procedimientos()->sync([test()->eco->id]);

    return $equipo;
}

function repartoEconomicoAcuerdoEquipo(Equipo $equipo, string $porcentaje): AcuerdoReparto
{
    return AltaAcuerdo::ejecutar(test()->gerente, AcuerdoReparto::EQUIPO, null, null, $equipo->id, Porcentaje::desdeTexto($porcentaje), Carbon::parse('2026-10-01'));
}

/** Una consulta finalizada de la Dra. Benítez con un turno de ecografía. */
function repartoEconomicoConsultaEco(): Consulta
{
    hcGuardarNueva([], hcTurno(['procedimiento_id' => test()->eco->id]))->assertSessionHasNoErrors();

    return Consulta::latest('id')->firstOrFail();
}

function repartoEconomicoDe(Consulta $consulta): Prestacion
{
    return Prestacion::where('consulta_id', $consulta->id)->sole();
}

/** Las líneas del reparto de la prestación: beneficiario => [porcentaje, monto]. */
function repartoEconomicoLineas(Prestacion $prestacion): array
{
    return LineaReparto::whereHas('reparto', fn ($query) => $query->where('prestacion_id', $prestacion->id))->orderBy('id')->get()
        ->mapWithKeys(fn (LineaReparto $linea) => [$linea->beneficiario => [$linea->porcentaje, (int) $linea->monto]])->all();
}

test('el acuerdo del profesional para un procedimiento pisa al general', function () {
    repartoEconomicoTarifa(100000);
    repartoEconomicoTarifa(300000, $this->eco);
    $general = repartoEconomicoAcuerdo('40');
    $excepcion = repartoEconomicoAcuerdo('50', $this->eco);

    $consulta = repartoEconomicoDe(hcConsulta());
    expect($consulta->reparto->repartido())->toBeTrue()
        ->and(repartoEconomicoLineas($consulta))->toBe(['CLINICA' => ['60.00', 60000], 'PROFESIONAL' => ['40.00', 40000]])
        ->and(LineaReparto::where('beneficiario', 'PROFESIONAL')->sole()->acuerdo_profesional_id)->toBe($general->id);

    $ecografia = repartoEconomicoDe(repartoEconomicoConsultaEco());
    expect(repartoEconomicoLineas($ecografia))->toBe(['CLINICA' => ['50.00', 150000], 'PROFESIONAL' => ['50.00', 150000]])
        ->and($ecografia->reparto->lineas->firstWhere('beneficiario', 'PROFESIONAL')->acuerdo_profesional_id)->toBe($excepcion->id)
        ->and($ecografia->reparto->lineas->firstWhere('beneficiario', 'PROFESIONAL')->profesional_id)->toBe($this->profesional->id);
});

test('con equipo: el dueño recibe su porcentaje y la clínica el resto; sin el acuerdo del equipo queda pendiente', function () {
    repartoEconomicoTarifa(300000, $this->eco);
    repartoEconomicoAcuerdo('40');
    $equipo = repartoEconomicoEquipo();

    $sinAcuerdo = repartoEconomicoDe(repartoEconomicoConsultaEco());
    expect($sinAcuerdo->reparto->pendiente())->toBeTrue()
        ->and($sinAcuerdo->reparto->motivo_pendiente)->toBe('Falta el acuerdo del equipo Ecógrafo de prueba para el 06/10/2026.')
        ->and(repartoEconomicoLineas($sinAcuerdo))->toBe([]);

    $acuerdoEquipo = repartoEconomicoAcuerdoEquipo($equipo, '25,5');
    $conAcuerdo = repartoEconomicoDe(repartoEconomicoConsultaEco());
    $lineaEquipo = $conAcuerdo->reparto->lineas->firstWhere('beneficiario', 'EQUIPO');
    expect(repartoEconomicoLineas($conAcuerdo))->toBe(['CLINICA' => ['34.50', 103500], 'PROFESIONAL' => ['40.00', 120000], 'EQUIPO' => ['25.50', 76500]])
        ->and($lineaEquipo->proveedor_id)->toBe($equipo->proveedor_id)->and($lineaEquipo->equipo_id)->toBe($equipo->id)
        ->and($lineaEquipo->acuerdo_equipo_id)->toBe($acuerdoEquipo->id)
        ->and($conAcuerdo->reparto->equipo_id)->toBe($equipo->id);

    // Un equipo inactivo ya no reparte: solo profesional y clínica.
    $equipo->desactivar();
    expect(repartoEconomicoLineas(repartoEconomicoDe(repartoEconomicoConsultaEco())))->toBe(['CLINICA' => ['60.00', 180000], 'PROFESIONAL' => ['40.00', 120000]]);
});

test('la suma es exacta: profesional y equipo se redondean al guaraní y la clínica recibe el resto', function () {
    repartoEconomicoTarifa(100001, $this->eco);
    repartoEconomicoAcuerdo('33,33');
    repartoEconomicoAcuerdoEquipo(repartoEconomicoEquipo(), '33.33');

    $prestacion = repartoEconomicoDe(repartoEconomicoConsultaEco());
    $lineas = repartoEconomicoLineas($prestacion);
    expect($lineas)->toBe(['CLINICA' => ['33.34', 33341], 'PROFESIONAL' => ['33.33', 33330], 'EQUIPO' => ['33.33', 33330]])
        ->and(array_sum(array_column($lineas, 1)))->toBe(100001);
});

test('con los porcentajes en 100 justo, el redondeo no se pasa del monto', function () {
    repartoEconomicoTarifa(3, $this->eco);
    repartoEconomicoAcuerdo('50');
    repartoEconomicoAcuerdoEquipo(repartoEconomicoEquipo(), '50');

    $lineas = repartoEconomicoLineas(repartoEconomicoDe(repartoEconomicoConsultaEco()));
    expect($lineas)->toBe(['CLINICA' => ['0.00', 0], 'PROFESIONAL' => ['50.00', 2], 'EQUIPO' => ['50.00', 1]])
        ->and(array_sum(array_column($lineas, 1)))->toBe(3);
});

test('el profesional que además es dueño del equipo recibe dos líneas separadas', function () {
    repartoEconomicoTarifa(200000, $this->eco);
    repartoEconomicoAcuerdo('30');
    $equipo = repartoEconomicoEquipo($this->profesional->persona_id);
    repartoEconomicoAcuerdoEquipo($equipo, '20');

    $reparto = repartoEconomicoDe(repartoEconomicoConsultaEco())->reparto;
    expect($reparto->lineas)->toHaveCount(3)
        ->and($reparto->lineas->firstWhere('beneficiario', 'PROFESIONAL')->profesional->persona_id)->toBe($this->profesional->persona_id)
        ->and($reparto->lineas->firstWhere('beneficiario', 'EQUIPO')->proveedor->persona_id)->toBe($this->profesional->persona_id)
        ->and($reparto->lineas->pluck('monto')->map(fn ($monto) => (int) $monto)->all())->toBe([100000, 60000, 40000]);
});

test('sin acuerdo del profesional la consulta se finaliza igual y queda pendiente de reparto con el motivo', function () {
    repartoEconomicoTarifa(100000);

    $consulta = hcConsulta();
    $prestacion = repartoEconomicoDe($consulta);
    expect($consulta->fresh()->finalizada())->toBeTrue()->and($prestacion->tieneEstado(Estado::TARIFADO))->toBeTrue()
        ->and($prestacion->reparto->pendiente())->toBeTrue()
        ->and($prestacion->reparto->motivo_pendiente)->toBe('Falta el acuerdo del profesional para el 06/10/2026.')
        ->and(RepartirPrestacion::pendientes()->pluck('prestaciones.id')->all())->toBe([$prestacion->id]);

    $this->actingAs($this->gerente)->get(route('admin.prestaciones.index'))->assertOk()
        ->assertSee('Hay 1 prestación pendiente de reparto.')->assertSee(route('admin.prestaciones.repartir', $prestacion));
    $this->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee('Falta el acuerdo del profesional para el 06/10/2026.');
});

test('si los porcentajes superan 100 queda pendiente y no se reparte', function () {
    repartoEconomicoTarifa(100000, $this->eco);
    repartoEconomicoAcuerdo('70');
    repartoEconomicoAcuerdoEquipo(repartoEconomicoEquipo(), '40');

    $reparto = repartoEconomicoDe(repartoEconomicoConsultaEco())->reparto;
    expect($reparto->pendiente())->toBeTrue()->and($reparto->motivo_pendiente)->toBe('Los porcentajes suman 110 %: superan el 100 %.')
        ->and($reparto->lineas)->toHaveCount(0);
});

test('"Repartir" usa los acuerdos de la fecha del servicio y dos veces no duplica', function () {
    repartoEconomicoTarifa(100000);
    $prestacion = repartoEconomicoDe(hcConsulta()); // pendiente: sin acuerdo
    $this->actingAs($this->gerente);

    // Sigue pendiente mientras no haya acuerdo: el motivo se informa.
    $this->post(route('admin.prestaciones.repartir', $prestacion))->assertSessionHas('error', 'Sigue pendiente de reparto: Falta el acuerdo del profesional para el 06/10/2026.');

    $vigenteEseDia = repartoEconomicoAcuerdo('40');
    CambiarAcuerdo::ejecutar($this->gerente, $vigenteEseDia, '45.00', Carbon::parse('2026-10-07')); // todavía no rige el 06/10

    $this->post(route('admin.prestaciones.repartir', $prestacion))->assertSessionHasNoErrors()->assertSessionHas('status', 'Prestación repartida.');
    $this->post(route('admin.prestaciones.repartir', $prestacion))->assertSessionHas('error', RepartirPrestacion::YA_REPARTIDA);

    expect(repartoEconomicoLineas($prestacion))->toBe(['CLINICA' => ['60.00', 60000], 'PROFESIONAL' => ['40.00', 40000]])
        ->and(RepartoPrestacion::where('prestacion_id', $prestacion->id)->count())->toBe(1)
        ->and(LineaReparto::count())->toBe(2)
        ->and($prestacion->reparto()->sole()->repartido_por)->toBe($this->gerente->id)
        ->and(RepartirPrestacion::pendientes()->count())->toBe(0);
    expect(fn () => RepartirPrestacion::repartir($this->gerente, $prestacion))->toThrow(\App\Exceptions\AccionRechazada::class);
});

test('tarifar una prestación pendiente de tarifar la reparte; sin monto no se reparte', function () {
    repartoEconomicoAcuerdo('40');
    $prestacion = repartoEconomicoDe(hcConsulta()); // sin tarifa: pendiente de tarifar

    expect($prestacion->pendiente())->toBeTrue()->and($prestacion->reparto)->toBeNull()
        ->and(RepartirPrestacion::pendientes()->count())->toBe(0);
    $this->actingAs($this->gerente)->post(route('admin.prestaciones.repartir', $prestacion))->assertSessionHas('error', RepartirPrestacion::NO_CORRESPONDE);

    repartoEconomicoTarifa(150000);
    $this->post(route('admin.prestaciones.tarifar', $prestacion))->assertSessionHasNoErrors();

    expect($prestacion->fresh()->reparto->repartido())->toBeTrue()
        ->and(repartoEconomicoLineas($prestacion))->toBe(['CLINICA' => ['60.00', 90000], 'PROFESIONAL' => ['40.00', 60000]]);
});

test('un reparto hecho no cambia aunque cambien los acuerdos; las prestaciones nuevas usan el nuevo', function () {
    repartoEconomicoTarifa(100000);
    $acuerdo = repartoEconomicoAcuerdo('40');
    $antes = repartoEconomicoDe(hcConsulta());

    $this->actingAs($this->gerente)->post(route('admin.acuerdos-reparto.guardar-cambio', $acuerdo), ['porcentaje' => '50', 'vigente_desde' => '06/10/2026'])
        ->assertSessionHasNoErrors();
    expect($acuerdo->fresh()->tieneEstado(Estado::HISTORICO))->toBeTrue()->and($acuerdo->fresh()->vigente_hasta->format('Y-m-d'))->toBe('2026-10-05');

    $this->actingAs($this->medico);
    $despues = repartoEconomicoDe(hcConsulta());
    expect(repartoEconomicoLineas($antes))->toBe(['CLINICA' => ['60.00', 60000], 'PROFESIONAL' => ['40.00', 40000]])
        ->and(repartoEconomicoLineas($despues))->toBe(['CLINICA' => ['50.00', 50000], 'PROFESIONAL' => ['50.00', 50000]]);

    // Ni las líneas ni el reparto se editan o borran desde el modelo.
    expect(fn () => LineaReparto::first()->forceFill(['monto' => 1])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => LineaReparto::first()->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $antes->reparto->forceFill(['monto_base' => 1])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $antes->reparto->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $acuerdo->fresh()->forceFill(['porcentaje' => 1])->save())->toThrow(RegistroEconomicoInmutable::class);
});

test('anular la prestación anula su reparto con el motivo y conserva las líneas', function () {
    repartoEconomicoTarifa(100000);
    repartoEconomicoAcuerdo('40');
    $prestacion = repartoEconomicoDe(hcConsulta());

    $this->actingAs($this->gerente)->post(route('admin.prestaciones.anular', $prestacion), ['motivo' => 'Cargada por error en la prueba.'])->assertSessionHasNoErrors();

    $reparto = $prestacion->reparto()->sole();
    expect($reparto->anulado())->toBeTrue()->and($reparto->motivo_anulacion)->toBe('Prestación anulada: Cargada por error en la prueba.')
        ->and($reparto->anulado_por)->toBe($this->gerente->id)->and($reparto->lineas)->toHaveCount(2)
        ->and(LogAuditoria::where('tabla_afectada', 'repartos_prestacion')->where('accion', AccionAuditoria::ANULAR->value)->sole()->detalle)
        ->toBe('Motivo: Prestación anulada: Cargada por error en la prueba.');
    $this->post(route('admin.prestaciones.repartir', $prestacion))->assertSessionHas('error', RepartirPrestacion::NO_CORRESPONDE);
    $this->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee('Reparto anulado el');
});

test('la adenda no crea otra prestación ni otro reparto', function () {
    repartoEconomicoTarifa(100000);
    repartoEconomicoAcuerdo('40');
    $consulta = hcConsulta();

    $this->post(route('admin.correcciones.adenda.store', $consulta), ['motivo' => 'Aclaración de prueba.', 'texto' => 'Texto de la adenda de prueba.'])
        ->assertSessionHasNoErrors();

    expect($consulta->adendas()->count())->toBe(1)->and(Prestacion::count())->toBe(1)
        ->and(RepartoPrestacion::count())->toBe(1)->and(LineaReparto::count())->toBe(2);
});

test('si el cálculo falla, la consulta se finaliza igual, queda un FALLO sin contenido clínico y después se reparte', function () {
    repartoEconomicoTarifa(100000);
    repartoEconomicoAcuerdo('40');
    $this->romper = true;
    LineaReparto::creating(function () {
        if (test()->romper) {
            throw new RuntimeException('Falla de prueba');
        }
    });

    $consulta = hcConsulta();

    $prestacion = repartoEconomicoDe($consulta);
    expect($consulta->fresh()->finalizada())->toBeTrue()->and($prestacion->tieneEstado(Estado::TARIFADO))->toBeTrue()
        ->and(RepartoPrestacion::count())->toBe(0)->and(LineaReparto::count())->toBe(0);
    $evento = LogAuditoria::where('accion', AccionAuditoria::FALLO->value)->sole();
    expect($evento->tabla_afectada)->toBe('repartos_prestacion')->and($evento->usuario_id)->toBe($this->medico->id)
        ->and($evento->valor_nuevo)->toBe(['prestacion_id' => $prestacion->id, 'profesional_id' => $this->profesional->id, 'fecha_servicio' => '2026-10-06'])
        ->and($evento->detalle)->toContain("prestación {$prestacion->id}")->not->toContain('garganta')
        ->and(RepartirPrestacion::pendientes()->pluck('prestaciones.id')->all())->toBe([$prestacion->id]);

    $this->romper = false;
    $this->actingAs($this->gerente)->post(route('admin.prestaciones.repartir', $prestacion))->assertSessionHas('status', 'Prestación repartida.');
    expect(repartoEconomicoLineas($prestacion))->toBe(['CLINICA' => ['60.00', 60000], 'PROFESIONAL' => ['40.00', 40000]]);
});

test('las prestaciones anteriores a los repartos no se reparten ni aparecen como pendientes', function () {
    repartoEconomicoTarifa(100000);
    repartoEconomicoAcuerdo('40');
    DB::table('hitos_sistema')->where('codigo', RepartirPrestacion::HITO)->update(['fecha_hora' => '2026-10-06 13:00:00']);
    $prestacion = repartoEconomicoDe(hcConsulta()); // creada el 06/10/2026 a las 12:00 UTC, antes del inicio de los repartos

    expect(RepartirPrestacion::pendientes()->count())->toBe(0);
    $this->actingAs($this->gerente)->post(route('admin.prestaciones.repartir', $prestacion))->assertSessionHas('error', RepartirPrestacion::NO_CORRESPONDE);
    $this->get(route('admin.prestaciones.index'))->assertOk()->assertDontSee('pendiente de reparto');
    expect(RepartoPrestacion::count())->toBe(0);
});

test('alta y cambio de acuerdos: porcentaje de 0 a 100 con dos decimales, uno por clave, cambio desde hoy', function () {
    $this->actingAs($this->gerente);
    $datos = ['tipo' => 'PROFESIONAL', 'profesional_id' => $this->profesional->id, 'procedimiento_id' => '', 'vigente_desde' => '06/10/2026'];

    foreach (['100,01', '101', '-5', '35,555', 'abc', ''] as $malo) {
        $this->post(route('admin.acuerdos-reparto.store'), [...$datos, 'porcentaje' => $malo])->assertSessionHasErrors('porcentaje');
    }
    $this->post(route('admin.acuerdos-reparto.store'), [...$datos, 'porcentaje' => '35,5'])->assertSessionHasNoErrors();
    $this->post(route('admin.acuerdos-reparto.store'), [...$datos, 'porcentaje' => '36'])->assertSessionHas('error', AltaAcuerdo::YA_EXISTE);
    $this->post(route('admin.acuerdos-reparto.store'), [...$datos, 'procedimiento_id' => $this->eco->id, 'porcentaje' => '0'])->assertSessionHasNoErrors();
    $this->post(route('admin.acuerdos-reparto.store'), ['tipo' => 'EQUIPO', 'equipo_id' => '', 'porcentaje' => '20', 'vigente_desde' => '06/10/2026'])->assertSessionHasErrors('equipo_id');
    $this->post(route('admin.acuerdos-reparto.store'), ['tipo' => 'EQUIPO', 'equipo_id' => repartoEconomicoEquipo()->id, 'porcentaje' => '100', 'vigente_desde' => '06/10/2026'])->assertSessionHasNoErrors();

    $general = AcuerdoReparto::whereNull('procedimiento_id')->where('tipo', 'PROFESIONAL')->sole();
    expect($general->porcentaje)->toBe('35.50')->and(AcuerdoReparto::count())->toBe(3);

    $this->post(route('admin.acuerdos-reparto.guardar-cambio', $general), ['porcentaje' => '35,50', 'vigente_desde' => '06/10/2026'])->assertSessionHas('error', CambiarAcuerdo::MISMO_PORCENTAJE);
    $this->post(route('admin.acuerdos-reparto.guardar-cambio', $general), ['porcentaje' => '40', 'vigente_desde' => '05/10/2026'])->assertSessionHas('error');
    $this->post(route('admin.acuerdos-reparto.guardar-cambio', $general), ['porcentaje' => '40', 'vigente_desde' => '06/10/2026'])->assertSessionHasNoErrors();
    $this->post(route('admin.acuerdos-reparto.guardar-cambio', $general), ['porcentaje' => '45', 'vigente_desde' => '07/10/2026'])->assertSessionHas('error', CambiarAcuerdo::YA_NO_VIGENTE);

    $nuevo = AcuerdoReparto::where('reemplaza_a_id', $general->id)->sole();
    $this->get(route('admin.acuerdos-reparto.show', $nuevo))->assertOk()->assertSee('40 %')->assertSee('35,5 %')
        ->assertSee('Desde el 06/10/2026')->assertSee('Sin vigencia (reemplazado el mismo día)');
    $this->get(route('admin.acuerdos-reparto.index', ['estado' => 'TODOS']))->assertOk()->assertSee('Dueño del equipo')->assertSee('Ecografía de prueba (ECO-PRUEBA)');
});

test('un procedimiento usa como máximo un equipo activo; los procedimientos se auditan como lista', function () {
    $tipo = TipoEquipo::create(['nombre' => 'Ecógrafo']);
    $dueno = Proveedor::create(['persona_id' => Persona::factory()->create()->id]);
    $this->actingAs($this->gerente);
    $datos = ['nombre' => 'Ecógrafo 1', 'tipo_equipo_id' => $tipo->id, 'proveedor_id' => $dueno->id, 'procedimientos' => [$this->eco->id]];

    $this->post(route('admin.equipos.store'), $datos)->assertSessionHasNoErrors();
    $this->post(route('admin.equipos.store'), [...$datos, 'nombre' => 'Ecógrafo 2'])
        ->assertSessionHasErrors(['procedimientos' => 'El procedimiento Ecografía de prueba ya usa el equipo Ecógrafo 1. Un procedimiento usa como máximo un equipo activo.']);
    $this->post(route('admin.equipos.store'), [...$datos, 'nombre' => 'Ecógrafo 2', 'procedimientos' => []])->assertSessionHasNoErrors();

    $primero = Equipo::where('nombre', 'Ecógrafo 1')->sole();
    $segundo = Equipo::where('nombre', 'Ecógrafo 2')->sole();
    // Con el primero inactivo, el segundo puede usarlo; reactivar el primero ya no.
    $this->patch(route('admin.equipos.desactivar', $primero))->assertSessionHasNoErrors();
    $this->put(route('admin.equipos.update', $segundo), [...$datos, 'nombre' => 'Ecógrafo 2', 'estado_id' => Estado::idDe(Estado::ACTIVO)])->assertSessionHasNoErrors();
    $this->put(route('admin.equipos.update', $primero), [...$datos, 'estado_id' => Estado::idDe(Estado::ACTIVO)])->assertSessionHasErrors('procedimientos');

    expect(Equipo::delProcedimiento($this->eco->id)->id)->toBe($segundo->id)
        ->and(LogAuditoria::where('tabla_afectada', 'equipos')->where('registro_afectado_id', (string) $segundo->id)->where('accion', 'EDITAR')->get()
            ->pluck('valor_nuevo')->filter(fn ($valor) => isset($valor['procedimientos']))->first()['procedimientos'])->toBe(['Ecografía de prueba (ECO-PRUEBA)']);
    $this->get(route('admin.equipos.index'))->assertOk()->assertSee('Ecógrafo 2')->assertSee('Ecografía de prueba');
});

test('Médico, Enfermería, Recepción y Caja no ven acuerdos, equipos, porcentajes ni repartos', function () {
    repartoEconomicoTarifa(123456);
    $acuerdo = repartoEconomicoAcuerdo('37');
    $equipo = repartoEconomicoEquipo();
    $prestacion = repartoEconomicoDe(hcConsulta());
    $this->actingAs($this->otroMedico);
    $pendiente = repartoEconomicoDe(hcConsulta()); // el Dr. Insfrán no tiene acuerdo: pendiente de reparto
    $this->actingAs($this->medico);

    // El médico que finaliza no ve el reparto.
    expect($this->get(route('admin.consultas.show', $prestacion->consulta_id))->getContent())->not->toContain('37 %');

    $rutas = [route('admin.acuerdos-reparto.index'), route('admin.acuerdos-reparto.show', $acuerdo), route('admin.acuerdos-reparto.create'),
        route('admin.equipos.index'), route('admin.equipos.create'), route('admin.tipos-equipo.index')];
    foreach ([PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA, PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA] as $codigo) {
        $this->actingAs(repartoEconomicoUsuario($codigo));
        foreach ($rutas as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('admin.prestaciones.repartir', $pendiente))->assertForbidden();
        $this->post(route('admin.acuerdos-reparto.guardar-cambio', $acuerdo), ['porcentaje' => '1', 'vigente_desde' => '06/10/2026'])->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee(route('admin.acuerdos-reparto.index'))->assertDontSee(route('admin.equipos.index'));
    }
    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA] as $codigo) {
        $this->actingAs(repartoEconomicoUsuario($codigo));
        $this->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee('₲ 123.456')->assertDontSee('37 %')->assertDontSee('Reparto');
        $this->get(route('admin.prestaciones.index', ['reparto' => 'PENDIENTE']))->assertOk()->assertDontSee('pendiente de reparto')
            ->assertDontSee('Repartir')->assertSee(route('admin.prestaciones.show', $prestacion));
    }
    expect(RepartoPrestacion::where('prestacion_id', $pendiente->id)->sole()->pendiente())->toBeTrue();

    $this->actingAs($this->gerente);
    $this->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee('37 %')->assertSee('₲ 77.777')->assertSee('₲ 45.679');
    $this->get(route('admin.prestaciones.index', ['reparto' => 'PENDIENTE']))->assertOk()->assertSee('Hay 1 prestación pendiente de reparto.')
        ->assertSee(route('admin.prestaciones.show', $pendiente))->assertDontSee(route('admin.prestaciones.show', $prestacion));
    $this->get('/dashboard')->assertOk()->assertSee(route('admin.acuerdos-reparto.index'))->assertSee(route('admin.equipos.index'))->assertSee(route('admin.tipos-equipo.index'));
    $this->get(route('admin.equipos.edit', $equipo))->assertOk()->assertSee('Procedimientos que lo usan');
    foreach ([route('admin.acuerdos-reparto.create'), route('admin.acuerdos-reparto.create', ['tipo' => 'EQUIPO']), route('admin.acuerdos-reparto.cambiar', $acuerdo),
        route('admin.equipos.create'), route('admin.tipos-equipo.create'), route('admin.tipos-equipo.index'), route('admin.acuerdos-reparto.index')] as $url) {
        $this->get($url)->assertOk();
    }
});

test('auditoría de acuerdos y repartos; sin el módulo no se ven porcentajes ni montos', function () {
    repartoEconomicoTarifa(100000);
    $this->actingAs($this->gerente)->post(route('admin.acuerdos-reparto.store'), [
        'tipo' => 'PROFESIONAL', 'profesional_id' => $this->profesional->id, 'porcentaje' => '42,75', 'vigente_desde' => '01/10/2026',
    ])->assertSessionHasNoErrors();
    $acuerdo = AcuerdoReparto::sole();

    $this->actingAs($this->medico);
    $prestacion = repartoEconomicoDe(hcConsulta());
    $reparto = $prestacion->reparto;

    $log = fn (string $tabla, int $id) => LogAuditoria::where('tabla_afectada', $tabla)->where('registro_afectado_id', (string) $id)->where('accion', AccionAuditoria::CREAR->value)->sole();
    $eventoAcuerdo = $log('acuerdos_reparto', $acuerdo->id);
    expect($log('repartos_prestacion', $reparto->id)->detalle)->toBe("Reparto de la prestación {$prestacion->id}")
        ->and($reparto->lineas->map(fn ($linea) => $log('lineas_reparto', $linea->id)->detalle)->unique()->all())->toBe(["Reparto de la prestación {$prestacion->id}"]);

    // El Auditor ve los eventos, pero no los porcentajes ni los montos.
    $auditor = repartoEconomicoUsuario(PerfilesPredefinidos::AUDITOR);
    $this->actingAs($auditor)->get(route('admin.auditoria.show', $eventoAcuerdo))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Acuerdos de reparto')->assertDontSee('42.75');
    $linea = $reparto->lineas->firstWhere('beneficiario', 'PROFESIONAL');
    $this->get(route('admin.auditoria.show', $log('lineas_reparto', $linea->id)))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Repartos de prestaciones')->assertDontSee('42750');
    $this->actingAs($this->gerente)->get(route('admin.auditoria.show', $eventoAcuerdo))->assertOk()->assertDontSee('Contenido económico')->assertSee('42.75');
});

test('formulario único de alta: un acuerdo de profesional', function () {
    $this->actingAs($this->gerente)->get(route('admin.acuerdos-reparto.create'))->assertOk()->assertSee('¿Qué acuerdo desea cargar?', false);
    $this->post(route('admin.acuerdos-reparto.store'), ['tipo' => 'PROFESIONAL', 'profesional_id' => $this->profesional->id, 'equipo_id' => repartoEconomicoEquipo()->id,
        'porcentaje' => '40', 'vigente_desde' => '06/10/2026'])->assertSessionHasNoErrors()->assertRedirect(route('admin.acuerdos-reparto.index'));
    $acuerdo = AcuerdoReparto::latest('id')->first();
    expect($acuerdo->tipo)->toBe('PROFESIONAL')->and($acuerdo->profesional_id)->toBe($this->profesional->id)->and($acuerdo->equipo_id)->toBeNull();
});

test('formulario único de alta: un acuerdo de equipo', function () {
    $equipo = repartoEconomicoEquipo();
    $this->actingAs($this->gerente)->get(route('admin.acuerdos-reparto.create', ['tipo' => 'EQUIPO']))->assertOk();
    $this->post(route('admin.acuerdos-reparto.store'), ['tipo' => 'EQUIPO', 'equipo_id' => $equipo->id, 'profesional_id' => $this->profesional->id,
        'porcentaje' => '20', 'vigente_desde' => '06/10/2026'])->assertSessionHasNoErrors();
    $acuerdo = AcuerdoReparto::latest('id')->first();
    expect($acuerdo->tipo)->toBe('EQUIPO')->and($acuerdo->equipo_id)->toBe($equipo->id)->and($acuerdo->profesional_id)->toBeNull();
});

test('formulario único de alta: tras un error se conservan el tipo y los datos', function () {
    $equipo = repartoEconomicoEquipo();
    $this->actingAs($this->gerente)->from(route('admin.acuerdos-reparto.create'))
        ->post(route('admin.acuerdos-reparto.store'), ['tipo' => 'EQUIPO', 'equipo_id' => $equipo->id, 'porcentaje' => '150', 'vigente_desde' => '06/10/2026'])
        ->assertRedirect(route('admin.acuerdos-reparto.create'))->assertSessionHasErrors('porcentaje');
    $this->get(route('admin.acuerdos-reparto.create'))->assertOk()
        ->assertSee('value="EQUIPO" selected', false)->assertSee('value="'.$equipo->id.'" selected', false)->assertSee('value="150"', false);
});
