<?php

/*
 * Cuántos eventos deja una consulta típica de 10 minutos, simulada con el ritmo real del autoguardado
 * (como mucho un envío cada 10 s mientras se escribe):
 * - enfermería: Preparar, 3 minutos escribiendo anamnesis y signos (18 guardados), Marcar como lista;
 * - profesional: Atender, 10 minutos escribiendo motivo, hallazgos y diagnóstico (60 guardados), Finalizar.
 * Es el peor caso (un guardado cada 10 s, siempre con cambios). Cada guardado efectivo deja UN evento con
 * todas las secciones que cambiaron (y solo sus filas): 78 autoguardados + Preparar, Marcar como lista,
 * Atender y Finalizar (eventos propios) + 2 del turno, y ninguna lectura (VER). El "Historial de cambios"
 * muestra cada racha de autoguardados del mismo usuario como una entrada y como mucho las últimas 50. El
 * log guarda todo. (La medición realista, con pausas, está en el resumen del cambio: 23 autoguardados.)
 */

use App\Http\Controllers\Admin\ConsultaController;
use App\Models\Consulta;
use App\Models\LogAuditoria;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    hcEscenario();
    $this->enfermera = User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->create();
});

afterEach(fn () => Carbon::setTestNow());

/** Simula la consulta típica y devuelve la consulta finalizada. */
function consultaDeDiezMinutos(): Consulta
{
    $t = test();
    $turno = hcTurno();
    $bloque = fn ($uid, $texto, $id = '') => ['uid' => $uid, 'id' => (string) $id, 'activo' => '1', 'tipo_bloque_anamnesis_id' => $t->enfermedadActual->id, 'contenido' => $texto];

    // Enfermería: 3 minutos, un guardado cada 10 s.
    $t->actingAs($t->enfermera)->post(route('admin.preparacion.preparar', $turno));
    $consulta = Consulta::sole();
    [$id, $texto] = [null, 'Refiere'];
    foreach (range(1, 18) as $k) {
        Carbon::setTestNow(now()->addSeconds(10));
        $texto .= " palabra{$k}";
        $respuesta = hcAutoguardar($consulta, ['con_anamnesis' => '1', 'anamnesis' => [$bloque($id ? "g-{$id}" : 'n-1', $texto, $id ?? '')],
            'examen' => ['peso' => '60', 'temperatura' => $k > 12 ? '37,5' : '']])->assertOk();
        $id ??= $respuesta->json('ids.anamnesis.n-1');
    }
    $t->post(route('admin.preparacion.lista', $consulta));

    // Profesional: 10 minutos, un guardado cada 10 s.
    $t->actingAs($t->medico)->post(route('admin.atencion.atender', $turno));
    [$motivo, $hallazgos] = ['Dolor', 'Faringe'];
    foreach (range(1, 60) as $k) {
        Carbon::setTestNow(now()->addSeconds(10));
        if ($k <= 20) {
            $motivo .= " m{$k}";
        } elseif ($k <= 40) {
            $hallazgos .= " h{$k}";
        }
        $datos = ['motivo_consulta' => $motivo, 'examen' => ['peso' => '60', 'temperatura' => '37,5', 'hallazgos' => $hallazgos]];
        if ($k > 40) {
            $datos += ['con_diagnosticos' => '1', 'diagnostico_principal' => '0', 'diagnosticos' => [['uid' => 'd', 'id' => (string) ($consulta->diagnosticos()->value('id') ?? ''),
                'activo' => '1', 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => 'Detalle '.str_repeat('x', $k - 40)]]];
        }
        hcAutoguardar($consulta, $datos)->assertOk();
    }

    hcFinalizar($consulta, ['motivo_consulta' => $motivo, 'examen' => ['peso' => '60', 'temperatura' => '37,5', 'hallazgos' => $hallazgos],
        'con_anamnesis' => '1', 'anamnesis' => [$bloque("g-{$id}", $texto, $id)],
        'con_diagnosticos' => '1', 'diagnostico_principal' => '0', 'diagnosticos' => [['id' => (string) $consulta->diagnosticos()->value('id'), 'activo' => '1',
            'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => 'Detalle '.str_repeat('x', 20)]]])->assertSessionHasNoErrors();

    return $consulta->fresh();
}

test('una consulta típica de 10 minutos: cuántos eventos deja y de qué tipo', function () {
    $consulta = consultaDeDiezMinutos();

    $eventos = LogAuditoria::where('accion', '!=', 'VER')->get();
    expect($eventos->countBy(fn ($e) => "{$e->tabla_afectada} / {$e->detalle}")->sortKeys()->all())->toBe([
        'consultas / Atender' => 1,
        'consultas / Borrador (autoguardado)' => 78, // = guardados efectivos (18 + 60)
        'consultas / Finalizar (turno ATENDIDO)' => 1,
        'consultas / Marcar como lista' => 1,
        'consultas / Preparar' => 1,
        'turnos / Atender' => 1,
        'turnos / Finalizar (turno ATENDIDO)' => 1,
    ])->and(LogAuditoria::where('accion', 'VER')->count())->toBe(0);

    // El actor es quien escribió: los 18 borradores y los hitos de la preparación, la enfermera; el resto, el profesional.
    $porUsuario = fn (int $id) => $eventos->where('tabla_afectada', 'consultas')->where('usuario_id', $id)->countBy('detalle')->sortKeys()->all();
    expect($porUsuario($this->enfermera->id))->toBe(['Borrador (autoguardado)' => 18, 'Marcar como lista' => 1, 'Preparar' => 1])
        ->and($porUsuario($this->medico->id))->toBe(['Atender' => 1, 'Borrador (autoguardado)' => 60, 'Finalizar (turno ATENDIDO)' => 1]);
});

test('el historial de cambios de esa consulta la resume: cada racha de autoguardados, una entrada con sus secciones; los hitos, aparte', function () {
    $consulta = consultaDeDiezMinutos();
    $eventos = LogAuditoria::where('tabla_afectada', 'consultas')->where('registro_afectado_id', (string) $consulta->id)->where('accion', '!=', 'VER')->orderBy('id')->get();

    $resumen = ConsultaController::agruparHistorial($eventos)
        ->map(fn ($e) => [$e['evento']->detalle, implode(',', $e['secciones']), $e['cantidad']])->all();

    expect($resumen)->toBe([
        ['Preparar', 'historia_clinica_id,turno_id,profesional_id,estado_id,iniciada_en,fecha_hora,id', 1],
        ['Borrador (autoguardado)', 'bloquesAnamnesis,examenFisico', 18],
        ['Marcar como lista', 'preparada_en', 1],
        ['Atender', 'estado_id,iniciada_en', 1],
        ['Borrador (autoguardado)', 'motivo_consulta,examenFisico,diagnosticos', 60],
        ['Finalizar (turno ATENDIDO)', 'estado_id,finalizada_en', 1],
    ]);

    [$auditor] = hcMedico(['apellidos' => 'Paredes', 'nombres' => 'Juan'], 'MP-9', ['HISTORIA_CLINICA' => ['VER'], 'AUDITORIA' => ['VER']]);
    $this->actingAs($auditor)->get(route('admin.consultas.show', $consulta))->assertOk()
        ->assertSee('Editar · Borrador (autoguardado)')->assertSee('(60 guardados)')->assertSee('(18 guardados)')
        ->assertSee('motivo, examen físico, diagnósticos')->assertSee('Editar · Finalizar (turno ATENDIDO)')
        ->assertDontSee('Se muestran las últimas');
});

test('los eventos con el formato anterior (uno por sección, listas enteras) se siguen leyendo en el historial y en Auditoría', function () {
    $consulta = hcConsulta();
    $viejo = fn (array $anterior, array $nuevo, int $minuto) => LogAuditoria::create(['usuario_id' => $this->medico->id, 'tabla_afectada' => 'consultas',
        'registro_afectado_id' => (string) $consulta->id, 'accion' => 'EDITAR', 'detalle' => 'Borrador (autoguardado)',
        'valor_anterior' => $anterior, 'valor_nuevo' => $nuevo, 'fecha_hora' => now()->addMinutes($minuto)]);
    $viejo(['bloquesAnamnesis' => []], ['bloquesAnamnesis' => ['Alergias: Penicilina.']], 1);
    $evento = $viejo(['examenFisico' => []], ['examenFisico' => ['Peso: 60 kg', 'Talla: 160 cm']], 1);
    $viejo(['bloquesAnamnesis' => ['Alergias: Penicilina.']], ['bloquesAnamnesis' => ['Alergias: Penicilina y látex.']], 2);

    [$auditor] = hcMedico(['apellidos' => 'Paredes', 'nombres' => 'Juan'], 'MP-9', ['HISTORIA_CLINICA' => ['VER'], 'AUDITORIA' => ['VER']]);
    $this->actingAs($auditor);
    $this->get(route('admin.consultas.show', $consulta))->assertOk()->assertSee('(3 guardados)')->assertSee('anamnesis, examen físico');
    $this->get(route('admin.auditoria.show', $evento))->assertOk()
        ->assertSee('(ninguno)')->assertSee("Peso: 60 kg\nTalla: 160 cm", false)->assertDontSee('(no existía)');
});

test('si aun agrupado pasa de 50 entradas, se muestran las últimas 50 con el aviso', function () {
    $consulta = hcConsulta();
    foreach (range(1, 60) as $n) {
        LogAuditoria::create(['usuario_id' => $this->medico->id, 'tabla_afectada' => 'consultas', 'registro_afectado_id' => (string) $consulta->id,
            'accion' => 'EDITAR', 'detalle' => "Cambio {$n}", 'valor_anterior' => ['motivo_consulta' => 'a'], 'valor_nuevo' => ['motivo_consulta' => 'b'], 'fecha_hora' => now()->addMinutes($n)]);
    }
    [$auditor] = hcMedico(['apellidos' => 'Paredes', 'nombres' => 'Juan'], 'MP-9', ['HISTORIA_CLINICA' => ['VER'], 'AUDITORIA' => ['VER']]);

    $this->actingAs($auditor)->get(route('admin.consultas.show', $consulta))->assertOk()
        ->assertSee('Se muestran las últimas 50 de 62 entradas. El resto está en Auditoría.') // 60 + Atender sin turno + Finalizar
        ->assertSee('Cambio 60')->assertSee('Cambio 11')->assertDontSee('Cambio 10<', false)
        ->assertViewHas('historial', fn ($historial) => $historial->count() === ConsultaController::MAXIMO_HISTORIAL);
});
