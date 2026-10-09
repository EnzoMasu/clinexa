<?php

/*
 * Rendimiento del flujo de atención: la pantalla Consulta (y su actualización automática), la lista de
 * Preparación y la pantalla de atención (con su historial) hacen una cantidad fija de consultas SQL,
 * tengan 3 o 12 filas por sección (sin una consulta por fila, N+1).
 */

use App\Models\Consulta;
use App\Models\Diagnostico;
use App\Models\Estado;
use App\Models\ExamenFisico;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    hcEscenario();
    $this->medico = hcDarPermisos($this->medico, ['TURNOS' => ['VER', 'EDITAR'], 'PREPARACION' => ['VER', 'CREAR', 'EDITAR']]);
    $this->actingAs($this->medico);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * $n filas más en cada sección de hoy: agenda (la mitad preparada), por llamar de nuevo, ausentes,
 * atendidos con 4 diagnósticos, y $n consultas finalizadas anteriores de Carmen Duarte (para el historial).
 * Se puede llamar varias veces: suma filas (horarios y fichas siguen desde donde quedaron).
 */
function cargarDia(int $n): void
{
    static $i = 0;
    static $dias = 0;
    $t = test();
    $hora = fn (int $i) => sprintf('%02d:%02d', 6 + intdiv($i * 5, 60), ($i * 5) % 60);
    foreach (['CONFIRMADO', 'PENDIENTE', 'SALTADO', 'AUSENTE', 'ATENDIDO'] as $estado) {
        foreach (range(1, $n) as $k) {
            $paciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => Paciente::nroFicha(1000 + $i)]);
            $turno = Turno::create(['paciente_id' => $paciente->id, 'profesional_id' => $t->profesional->id, 'consultorio_id' => $t->consultorio->id,
                'fecha' => '2026-10-06', 'hora_inicio' => $hora($i), 'hora_fin' => $hora($i + 1), 'estado_id' => Estado::idDe($estado)]);
            $i++;
            if ($estado === 'CONFIRMADO' && $k % 2) {
                $consulta = new Consulta(['historia_clinica_id' => $paciente->historiaClinica->id, 'turno_id' => $turno->id, 'profesional_id' => $t->profesional->id]);
                $consulta->forceFill(['estado_id' => Estado::idDe(Estado::EN_PREPARACION), 'preparada_en' => $k % 4 === 1 ? now() : null])->save();
                ExamenFisico::create(['consulta_id' => $consulta->id, 'peso' => 60]);
            }
            if ($estado === 'ATENDIDO') {
                $consulta = new Consulta(['historia_clinica_id' => $paciente->historiaClinica->id, 'turno_id' => $turno->id, 'profesional_id' => $t->profesional->id, 'motivo_consulta' => 'Control']);
                $consulta->forceFill(['estado_id' => Estado::idDe(Estado::FINALIZADO), 'iniciada_en' => now(), 'finalizada_en' => now()])->save();
                foreach (['J06.9', 'R51', 'Z00.0', 'J06.9'] as $j => $codigo) {
                    Diagnostico::create(['consulta_id' => $consulta->id, 'codigo_cie10' => $codigo, 'tipo' => 'PRESUNTIVO', 'principal' => $j === 0, 'activo' => $j < 3]);
                }
            }
        }
    }

    // Historial de Carmen Duarte: $n consultas finalizadas anteriores, con diagnósticos.
    foreach (range(1, $n) as $k) {
        $dias++;
        $consulta = new Consulta(['historia_clinica_id' => $t->historia->id, 'profesional_id' => $k % 2 ? $t->profesional->id : $t->otroProfesional->id, 'motivo_consulta' => "Anterior {$dias}"]);
        $consulta->forceFill(['estado_id' => Estado::idDe(Estado::FINALIZADO), 'fecha_hora' => now()->subDays($dias), 'iniciada_en' => now()->subDays($dias), 'finalizada_en' => now()->subDays($dias)])->save();
        Diagnostico::create(['consulta_id' => $consulta->id, 'codigo_cie10' => 'R51', 'tipo' => 'PRESUNTIVO', 'principal' => true]);
    }
}

/** Las consultas SQL de cada pantalla, con lo que haya cargado. */
function medirPantallas(Consulta $atencion): array
{
    return [
        'consulta' => consultasSql(route('admin.atencion.index')),
        'consulta (actualización)' => consultasSql(route('admin.atencion.index'), ['X-Requested-With' => 'XMLHttpRequest']),
        'preparación' => consultasSql(route('admin.preparacion.index')),
        'atención' => consultasSql(route('admin.consultas.atencion', $atencion)),
        'cerrar jornada' => consultasSql(route('admin.atencion.cerrar-jornada')),
    ];
}

test('con 3 y con 12 filas por sección: las mismas consultas SQL en cada pantalla', function () {
    cargarDia(3);
    $atencion = hcEnCurso(); // Carmen Duarte, sin turno: su historial son las anteriores
    $con3 = medirPantallas($atencion);

    cargarDia(9);
    $con12 = medirPantallas($atencion);

    expect($con12)->toBe($con3);
    foreach ($con3 as $pantalla => $cantidad) {
        expect($cantidad)->toBeLessThan(40, "{$pantalla}: {$cantidad} consultas SQL");
    }
    // Para el resumen: cuántas hace cada una.
    fwrite(STDERR, 'Consultas SQL por pantalla: '.json_encode($con3, JSON_UNESCAPED_UNICODE).PHP_EOL);
});
