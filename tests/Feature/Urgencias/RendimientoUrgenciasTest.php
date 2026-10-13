<?php

/*
 * Rendimiento con entre pacientes y urgencias: las listas, los popups y los contadores hacen una cantidad FIJA de
 * consultas SQL, haya 1 o 30 turnos de cada tipo (sin una consulta por fila, N+1). Datos inventados.
 */

use App\Enums\TipoTurno;
use App\Models\Estado;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Support\Carbon;

// Grupo "lento" (docs/pruebas.md): no corre en el comando rápido.
uses()->group('lento');

beforeEach(function () {
    epEscenario();
    $this->medico = hcDarPermisos($this->medico, ['TURNOS' => ['VER', 'EDITAR']]);
    $this->enfermera = User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->personalDeSalud()->create();
    epCupo(30);
});

afterEach(fn () => Carbon::setTestNow());

/** Suma $n turnos de cada tipo (con horario, entre pacientes y urgencia) de hoy, con triaje en la mitad (urgente y con signos alterados). */
function rendimientoUrgenciasCargar(int $n): void
{
    static $i = 0;
    $hora = fn (int $i) => sprintf('%02d:%02d', 6 + intdiv($i * 5, 60), ($i * 5) % 60);
    foreach ([TipoTurno::NORMAL, TipoTurno::ENTRE_PACIENTES, TipoTurno::URGENCIA] as $tipo) {
        foreach (range(1, $n) as $k) {
            $paciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => Paciente::nroFicha(5000 + $i)]);
            $turno = Turno::create([
                'tipo' => $tipo, 'paciente_id' => $paciente->id, 'profesional_id' => test()->profesional->id, 'fecha' => '2026-10-06',
                'estado_id' => Estado::idDe($k % 3 === 0 ? Estado::SALTADO : Estado::PENDIENTE),
                ...($tipo === TipoTurno::NORMAL ? ['consultorio_id' => test()->consultorio->id, 'hora_inicio' => $hora($i), 'hora_fin' => $hora($i + 1)] : []),
                ...($tipo === TipoTurno::URGENCIA ? ['llegada_en' => now()] : []),
            ]);
            $i++;
            if ($k % 2) {
                epTriaje($turno, urgente: true, observacion: "Observación {$i}.", alterados: true);
            }
        }
    }
}

test('cantidad fija de consultas SQL con 1 y con 30 turnos de cada tipo', function () {
    $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
    $medir = function () use ($ajax) {
        $t = test();
        $t->actingAs($t->medico);
        $medidas = [
            'consulta' => consultasSql(route('admin.atencion.index')),
            'consulta (actualización)' => consultasSql(route('admin.atencion.index'), $ajax),
            'contadores' => consultasSql(route('admin.atencion.contadores'), $ajax),
            'popup entre pacientes' => consultasSql(route('admin.atencion.entre-pacientes'), $ajax),
            'popup urgencias' => consultasSql(route('admin.atencion.urgencias'), $ajax),
            'cerrar jornada' => consultasSql(route('admin.atencion.cerrar-jornada')),
        ];
        $t->actingAs($t->recepcion);
        $medidas['turnos'] = consultasSql(route('admin.turnos.index'));
        $medidas['urgencias de hoy'] = consultasSql(route('admin.turnos.urgencias'));
        $medidas['cupo'] = consultasSql(route('admin.turnos.cupos'));
        $t->actingAs($t->enfermera);
        $medidas['triaje'] = consultasSql(route('admin.preparacion.index'));

        return $medidas;
    };

    rendimientoUrgenciasCargar(1);
    $conUno = $medir();
    rendimientoUrgenciasCargar(29);
    expect(Turno::count())->toBe(90);
    $conTreinta = $medir();

    foreach ($conUno as $pantalla => $cantidad) {
        // No crece con las filas. (En Turnos puede bajar: con 30 de cada tipo la primera página son solo urgencias, sin
        // consultorio, y no se cargan esas relaciones.)
        expect($conTreinta[$pantalla])->toBeLessThanOrEqual($cantidad, "{$pantalla}: {$cantidad} consultas con 1 de cada tipo y {$conTreinta[$pantalla]} con 30");
    }
});
