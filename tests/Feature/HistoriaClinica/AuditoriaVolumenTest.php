<?php

/*
 * Cuántos eventos deja una consulta típica de 10 minutos, simulada con el ritmo real del autoguardado
 * (como mucho un envío cada 10 s mientras se escribe):
 * - enfermería: Preparar, 3 minutos escribiendo anamnesis y signos (18 guardados), Marcar como lista;
 * - profesional: Atender, 10 minutos escribiendo motivo, hallazgos y diagnóstico (60 guardados), Finalizar.
 * Medido: 85 eventos de la consulta (81 de autoguardado) + 2 del turno, y ninguna lectura (VER).
 * Como eso no se puede leer en la consulta, el "Historial de cambios" agrupa los autoguardados seguidos
 * por sección y muestra como mucho las últimas 50 entradas. El log de auditoría guarda todo, sin cambios.
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
        'consultas / Borrador (autoguardado)' => 81,
        'consultas / Finalizar' => 1,
        'consultas / Marcar como lista' => 1,
        'consultas / Preparar' => 1,
        'turnos / Atender' => 1,
        'turnos / Finalizar' => 1,
    ])->and(LogAuditoria::where('accion', 'VER')->count())->toBe(0)
        ->and($eventos->where('tabla_afectada', 'consultas')->pluck('usuario_id')->unique()->sort()->values()->all())
        ->toBe(collect([$this->medico->id, $this->enfermera->id])->sort()->values()->all());
});

test('el historial de cambios de esa consulta la resume: los autoguardados seguidos, una entrada por sección', function () {
    $consulta = consultaDeDiezMinutos();
    $eventos = LogAuditoria::where('tabla_afectada', 'consultas')->where('registro_afectado_id', (string) $consulta->id)->where('accion', '!=', 'VER')->orderBy('id')->get();

    $resumen = ConsultaController::agruparHistorial($eventos)
        ->map(fn ($e) => [$e['evento']->detalle, implode(',', array_keys($e['evento']->valor_nuevo ?? [])), $e['cantidad']])->all();

    expect($resumen)->toBe([
        ['Preparar', 'historia_clinica_id,turno_id,profesional_id,estado_id,iniciada_en,fecha_hora,id', 1],
        ['Borrador (autoguardado)', 'bloquesAnamnesis', 18],
        ['Borrador (autoguardado)', 'examenFisico', 2],
        ['Marcar como lista', 'preparada_en', 1],
        ['Atender', 'estado_id,iniciada_en', 1],
        ['Borrador (autoguardado)', 'motivo_consulta', 20],
        ['Borrador (autoguardado)', 'examenFisico', 21],
        ['Borrador (autoguardado)', 'diagnosticos', 20],
        ['Finalizar', 'estado_id,finalizada_en', 1],
    ]);

    [$auditor] = hcMedico(['apellidos' => 'Paredes', 'nombres' => 'Juan'], 'MP-9', ['HISTORIA_CLINICA' => ['VER'], 'AUDITORIA' => ['VER']]);
    $this->actingAs($auditor)->get(route('admin.consultas.show', $consulta))->assertOk()
        ->assertSee('Editar · Borrador (autoguardado)')->assertSee('(20 guardados)')->assertSee('(18 guardados)')
        ->assertDontSee('Se muestran las últimas');
});

test('si aun agrupado pasa de 50 entradas, se muestran las últimas 50 con el aviso', function () {
    $consulta = hcConsulta();
    foreach (range(1, 60) as $n) {
        LogAuditoria::create(['usuario_id' => $this->medico->id, 'tabla_afectada' => 'consultas', 'registro_afectado_id' => (string) $consulta->id,
            'accion' => 'EDITAR', 'detalle' => "Cambio {$n}", 'valor_anterior' => ['motivo_consulta' => 'a'], 'valor_nuevo' => ['motivo_consulta' => 'b'], 'fecha_hora' => now()->addMinutes($n)]);
    }
    [$auditor] = hcMedico(['apellidos' => 'Paredes', 'nombres' => 'Juan'], 'MP-9', ['HISTORIA_CLINICA' => ['VER'], 'AUDITORIA' => ['VER']]);

    $this->actingAs($auditor)->get(route('admin.consultas.show', $consulta))->assertOk()
        ->assertSee('Se muestran las últimas 50 de 66 entradas. El resto está en Auditoría.')
        ->assertSee('Cambio 60')->assertSee('Cambio 11')->assertDontSee('Cambio 10<', false)
        ->assertViewHas('historial', fn ($historial) => $historial->count() === ConsultaController::MAXIMO_HISTORIAL);
});
