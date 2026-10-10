<?php

use App\Models\Consulta;
use App\Models\DetalleReceta;
use App\Models\Diagnostico;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Receta;
use App\Models\Turno;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Grupo "lento" (docs/pruebas.md): Rendimiento: muchos registros de prueba. No corre en el comando rápido.
uses()->group('lento');

/*
 * 50 pacientes y 200 consultas (con diagnósticos): el listado y la historia hacen una cantidad fija
 * de consultas SQL, sin una por fila (N+1).
 */

beforeEach(function () {
    hcEscenario();

    $pacientes = collect(range(2, 50))->map(fn ($n) => Paciente::create([
        'persona_id' => Persona::factory()->create()->id, 'nro_ficha' => Paciente::nroFicha($n),
    ]))->prepend($this->paciente);

    // 200 consultas: 30 de Carmen Duarte (dos páginas en su historia), el resto repartidas.
    foreach (range(1, 200) as $n) {
        $paciente = $n <= 30 ? $this->paciente : $pacientes[$n % 50];
        $consulta = Consulta::create(['historia_clinica_id' => $paciente->historiaClinica->id, 'profesional_id' => $n % 2 ? $this->profesional->id : $this->otroProfesional->id,
            'motivo_consulta' => "Consulta {$n}"]);
        // Finalizadas, como las reales (las que cuentan en la historia y en el listado).
        $consulta->forceFill(['estado_id' => \App\Models\Estado::idDe('FINALIZADO'), 'iniciada_en' => $consulta->fecha_hora, 'finalizada_en' => $consulta->fecha_hora])->save();
        Diagnostico::create(['consulta_id' => $consulta->id, 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO', 'principal' => true]);
        Diagnostico::create(['consulta_id' => $consulta->id, 'codigo_cie10' => 'R51', 'tipo' => 'CONFIRMADO', 'principal' => false]);
    }
});

afterEach(fn () => Carbon::setTestNow());

test('listado de historias: las mismas consultas SQL en la página 1 y en la 2 (20 historias cada una)', function () {
    $pagina1 = consultasSql(route('admin.historias-clinicas.index'));
    $pagina2 = consultasSql(route('admin.historias-clinicas.index', ['page' => 2]));
    $busqueda = consultasSql(route('admin.historias-clinicas.index', ['q' => 'a']), ['X-Requested-With' => 'XMLHttpRequest']);

    expect($pagina1)->toBeLessThan(25)->toBe($pagina2)
        ->and($busqueda)->toBeLessThanOrEqual($pagina1);
});

test('historia de un paciente: las mismas consultas SQL con 20 consultas en pantalla que con 10', function () {
    $completa = consultasSql(route('admin.historias-clinicas.show', $this->historia)); // 20 de 30
    $segunda = consultasSql(route('admin.historias-clinicas.show', [$this->historia, 'page' => 2])); // 10 de 30

    expect($completa)->toBeLessThan(25)->toBe($segunda);
});

test('listado de turnos: sin una consulta por fila', function () {
    // 20 turnos de 30 minutos desde las 06:00.
    foreach (range(0, 19) as $n) {
        $inicio = Carbon::parse('06:00')->addMinutes(30 * $n);
        hcTurno(['hora_inicio' => $inicio->format('H:i'), 'hora_fin' => $inicio->copy()->addMinutes(30)->format('H:i')]);
    }

    $conVeinte = consultasSql(route('admin.turnos.index'));
    Turno::query()->limit(10)->get()->each(fn ($turno) => DB::table('turnos')->where('id', $turno->id)->update(['fecha' => '2026-10-07']));

    expect($conVeinte)->toBeLessThan(30)->toBe(consultasSql(route('admin.turnos.index')));
});

test('con 3 recetas por consulta: la página de la consulta y la historia hacen las mismas consultas SQL, tengan las recetas 1 o 4 medicamentos', function () {
    $this->actingAs(hcDarPermisos($this->medico, HC_PERMISOS_RECETAS));
    $renglon = ['medicamento' => 'Amoxicilina 500 mg', 'dosis' => '1 comprimido', 'frecuencia' => 'Cada 8 horas'];
    foreach (Consulta::all() as $n => $consulta) {
        foreach (range(1, 3) as $r) {
            $receta = Receta::create(['consulta_id' => $consulta->id]);
            foreach (range(1, intdiv($n, 2) % 2 ? 4 : 1) as $orden) {
                DetalleReceta::create([...$renglon, 'receta_id' => $receta->id, 'orden' => $orden]);
            }
        }
    }
    // Dos consultas de la misma profesional (la que atiende: ve todas las acciones), con 1 y con 4 medicamentos por receta.
    $suyas = Consulta::where('profesional_id', $this->profesional->id)->withCount('recetas')->with('recetas.detalles')->orderBy('id')->get();
    $conUno = $suyas->first(fn ($c) => $c->recetas->first()->detalles->count() === 1);
    $conCuatro = $suyas->first(fn ($c) => $c->recetas->first()->detalles->count() === 4);

    $sqlUno = consultasSql(route('admin.consultas.show', $conUno));
    expect($sqlUno)->toBeLessThan(40)
        ->toBe(consultasSql(route('admin.consultas.show', $conCuatro)))
        ->and(consultasSql(route('admin.consultas.detalle', $conUno), ['X-Requested-With' => 'XMLHttpRequest']))
        ->toBe(consultasSql(route('admin.consultas.detalle', $conCuatro), ['X-Requested-With' => 'XMLHttpRequest']))
        ->and(consultasSql(route('admin.historias-clinicas.show', $this->historia)))
        ->toBe(consultasSql(route('admin.historias-clinicas.show', [$this->historia, 'page' => 2])));
});
