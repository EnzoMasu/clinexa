<?php

use App\Models\BloqueAnamnesis;
use App\Models\Consulta;
use App\Models\Diagnostico;
use App\Models\Estado;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => hcEscenario());

afterEach(fn () => Carbon::setTestNow());

function enlaceAtender(Turno $turno): string
{
    return route('admin.consultas.create', [test()->historia, 'turno' => $turno->id]);
}

test('"Atender" aparece solo para el turno CONFIRMADO, de hoy, sin consulta y del profesional del usuario', function () {
    $siAtiende = hcTurno();
    $pendiente = hcTurno(['hora_inicio' => '08:30', 'hora_fin' => '09:00', 'estado_id' => Estado::idDe(Estado::PENDIENTE)]);
    $manana = hcTurno(['fecha' => '2026-10-07']);
    $ayer = hcTurno(['fecha' => '2026-10-05']);
    $deOtro = hcTurno(['hora_inicio' => '09:00', 'hora_fin' => '09:30', 'profesional_id' => $this->otroProfesional->id]);
    $atendidoSinConsulta = hcTurno(['hora_inicio' => '09:30', 'hora_fin' => '10:00', 'estado_id' => Estado::idDe(Estado::ATENDIDO)]);

    $html = $this->get(route('admin.turnos.index'))->assertOk()->getContent();

    expect($html)->toContain(e(enlaceAtender($siAtiende)));
    foreach ([$pendiente, $manana, $ayer, $deOtro, $atendidoSinConsulta] as $turno) {
        expect($html)->not->toContain(e(enlaceAtender($turno)));
    }
    // Ya no existe el botón de cambio de estado "atender".
    expect($html)->not->toContain('value="atender"');

    // El otro profesional ve "Atender" solo en el suyo.
    $html = $this->actingAs($this->otroMedico)->get(route('admin.turnos.index'))->getContent();
    expect($html)->toContain(e(enlaceAtender($deOtro)))->not->toContain(e(enlaceAtender($siAtiende)));

    // Quien no es profesional (aunque sea Administrador) no ve ninguno.
    $html = $this->actingAs(User::factory()->administrador()->create())->get(route('admin.turnos.index'))->getContent();
    expect($html)->not->toContain('consultas/create');

    // Los turnos ATENDIDOS sin consulta (de pruebas anteriores) quedan como estaban.
    expect($atendidoSinConsulta->fresh()->estado->codigo)->toBe('ATENDIDO');
});

test('"Atender" con un turno que no corresponde: 403 con el motivo', function (Closure $turno, string $motivo) {
    $this->get(enlaceAtender($turno()))->assertForbidden()->assertSee($motivo);
})->with([
    'pendiente' => [fn () => hcTurno(['estado_id' => Estado::idDe(Estado::PENDIENTE)]), 'Solo se puede atender un turno confirmado.'],
    'cancelado' => [fn () => hcTurno(['estado_id' => Estado::idDe(Estado::CANCELADO)]), 'Solo se puede atender un turno confirmado.'],
    'de mañana' => [fn () => hcTurno(['fecha' => '2026-10-07']), 'Solo se pueden atender los turnos de hoy.'],
    'de otro profesional' => [fn () => hcTurno(['profesional_id' => test()->otroProfesional->id]), 'Solo el profesional del turno puede atenderlo.'],
    'de otro paciente' => [fn () => hcTurno(['paciente_id' => Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000002'])->id]), 'El turno no es de este paciente.'],
]);

test('"de hoy" es en hora de Paraguay: a las 22:00 del lunes (01:00 UTC del martes) el turno del martes todavía no es de hoy', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 01:00:00', 'UTC'));

    $this->get(enlaceAtender(hcTurno()))->assertForbidden()->assertSee('Solo se pueden atender los turnos de hoy.');
});

test('el formulario muestra paciente, profesional y turno fijos', function () {
    $turno = hcTurno();

    $this->get(enlaceAtender($turno))->assertOk()
        ->assertSee('Duarte, Carmen')->assertSee('Benítez, Rosa')->assertSee('06/10/2026, 08:00 – 08:30')
        ->assertSee('name="turno_id" value="'.$turno->id.'"', false)
        ->assertDontSee('name="paciente_id"', false)->assertDontSee('name="profesional_id"', false);
});

test('guardar crea la consulta del turno y pasa el turno a ATENDIDO', function () {
    $turno = hcTurno();

    hcGuardarNueva([], $turno)->assertSessionHasNoErrors()->assertRedirect();

    $consulta = Consulta::sole();
    expect($consulta->turno_id)->toBe($turno->id)
        ->and($consulta->profesional_id)->toBe($this->profesional->id)
        ->and($consulta->historia_clinica_id)->toBe($this->historia->id)
        ->and($consulta->fecha_hora->utc()->format('Y-m-d H:i'))->toBe('2026-10-06 12:00')
        ->and($turno->fresh()->estado->codigo)->toBe('ATENDIDO');
});

test('consulta y turno ATENDIDO van en la misma transacción: si falla el turno, no queda la consulta', function () {
    $turno = hcTurno();
    Turno::updating(fn () => throw new RuntimeException('falla simulada'));

    $this->withoutExceptionHandling();
    expect(fn () => hcGuardarNueva([], $turno))->toThrow(RuntimeException::class, 'falla simulada');

    expect(Consulta::count())->toBe(0)
        ->and(BloqueAnamnesis::count())->toBe(0)
        ->and(Diagnostico::count())->toBe(0)
        ->and($turno->fresh()->estado->codigo)->toBe('CONFIRMADO');
});

test('si el turno se cancela mientras se cargaba la consulta, no se guarda y se conserva lo escrito', function () {
    $turno = hcTurno();
    $this->get(enlaceAtender($turno))->assertOk();

    $turno->update(['estado_id' => Estado::idDe(Estado::CANCELADO)]); // desde otra ventana

    $this->from(enlaceAtender($turno))->post(route('admin.consultas.store', $this->historia), [...hcDatos(), 'turno_id' => $turno->id])
        ->assertForbidden(); // la autorización ya lo ve cancelado
    expect(Consulta::count())->toBe(0)->and($turno->fresh()->estado->codigo)->toBe('CANCELADO');
});

test('si el turno cambia entre la autorización y el guardado, la revalidación dentro de la transacción lo frena', function () {
    $turno = hcTurno();
    // Simula otra ventana que cancela el turno justo cuando empieza la transacción (después de autorizar).
    $cancelado = false;
    Event::listen(TransactionBeginning::class, function () use ($turno, &$cancelado) {
        if (! $cancelado) {
            $cancelado = true;
            Turno::whereKey($turno->id)->toBase()->update(['estado_id' => Estado::idDe(Estado::CANCELADO)]);
        }
    });

    $this->from(enlaceAtender($turno))->post(route('admin.consultas.store', $this->historia), [...hcDatos(), 'turno_id' => $turno->id])
        ->assertRedirect(enlaceAtender($turno))
        ->assertSessionHas('error', fn ($error) => str_contains($error, 'No se guardó la consulta'))
        ->assertSessionHasInput('motivo_consulta', 'Dolor de garganta y fiebre desde ayer.');

    expect(Consulta::count())->toBe(0)->and($turno->fresh()->estado->codigo)->toBe('CANCELADO');
});

test('no hay dos consultas por turno: el segundo envío se rechaza, y la base lo impide aunque se saltee la aplicación', function () {
    $turno = hcTurno();
    hcGuardarNueva([], $turno)->assertRedirect();

    hcGuardarNueva([], $turno)->assertForbidden()->assertSee('Solo se puede atender un turno confirmado.');
    expect(Consulta::where('turno_id', $turno->id)->count())->toBe(1);

    // Carrera de dos envíos: el unique de turno_id frena el segundo INSERT.
    expect(fn () => Consulta::create(['historia_clinica_id' => $this->historia->id, 'turno_id' => $turno->id, 'profesional_id' => $this->profesional->id, 'motivo_consulta' => 'x']))
        ->toThrow(QueryException::class);
});

test('urgencia: "Atender sin turno" desde la historia crea una consulta sin turno', function () {
    $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()
        ->assertSee('Atender sin turno')->assertSee(route('admin.consultas.create', $this->historia));
    $this->get(route('admin.consultas.create', $this->historia))->assertOk()->assertSee('Sin turno (urgencia)');

    hcGuardarNueva()->assertSessionHasNoErrors();

    expect(Consulta::sole()->turno_id)->toBeNull();
});

test('un turno inexistente en la URL da 404', function () {
    $this->get(route('admin.consultas.create', [$this->historia, 'turno' => 999]))->assertNotFound();
    $this->get(route('admin.consultas.create', [$this->historia, 'turno' => 'abc']))->assertNotFound();
});
