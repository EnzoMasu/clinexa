<?php

/*
 * Escapado en entre pacientes y urgencias: HTML y JS en los nombres (paciente y profesional) y en la observación del
 * triaje se muestran siempre escapados en los popups, el cartel (JSON con < > codificados, puesto con x-text) y las
 * listas (Turnos, Urgencias de hoy, Cupo, Triaje, Consulta, Cerrar jornada, Registrar urgencia).
 *
 * Mutación: se rompe a propósito el escapado en tres lugares ({{ }} -> {!! !!}) sobre una COPIA de las vistas
 * (conVistaMutada, escenario.php): el test lo detecta y los archivos del proyecto no se tocan. Datos inventados.
 */

use App\Enums\TipoTurno;

const EP_XSS_NOMBRE = '<img src=x onerror=alert(1)>';
const EP_XSS_NOMBRE_ESCAPADO = '&lt;img src=x onerror=alert(1)&gt;';
const EP_XSS_OBSERVACION = '"><script>alert(2)</script>';
const EP_XSS_OBSERVACION_ESCAPADA = '&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;';

beforeEach(function () {
    epEscenario();
    $this->paciente->persona->update(['apellidos' => 'Duarte '.EP_XSS_NOMBRE]);
    $this->profesional->persona->update(['apellidos' => 'Benítez '.EP_XSS_NOMBRE]);

    epCupo(3);
    $this->entre = epTurno();
    $this->urgencia = epTurno(TipoTurno::URGENCIA);
    epTriaje($this->urgencia, urgente: true, observacion: 'Dolor '.EP_XSS_OBSERVACION, alterados: true);
    $this->enfermera = \App\Models\User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->personalDeSalud()->create();
    $this->ajax = ['X-Requested-With' => 'XMLHttpRequest'];
});

afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Las pantallas a revisar: nombre => HTML, cada una como el usuario que corresponde. */
function escapadoUrgenciasPantallas(): array
{
    $t = test();
    $t->actingAs($t->medico);
    $pantallas = [
        'consulta' => $t->get(route('admin.atencion.index'))->assertOk()->getContent(),
        'popup entre pacientes' => $t->get(route('admin.atencion.entre-pacientes'), $t->ajax)->assertOk()->getContent(),
        'popup urgencias' => $t->get(route('admin.atencion.urgencias'), $t->ajax)->assertOk()->getContent(),
        'cerrar jornada' => $t->get(route('admin.atencion.cerrar-jornada'))->assertOk()->getContent(),
    ];
    $t->actingAs($t->recepcion);
    $pantallas['turnos'] = $t->get(route('admin.turnos.index'))->assertOk()->getContent();
    $pantallas['urgencias de hoy'] = $t->get(route('admin.turnos.urgencias'))->assertOk()->getContent();
    $pantallas['cupo'] = $t->get(route('admin.turnos.cupos'))->assertOk()->getContent();
    $pantallas['registrar urgencia'] = $t->get(route('admin.turnos.urgencias.create'))->assertOk()->getContent();
    $t->actingAs($t->enfermera);
    $pantallas['triaje'] = $t->get(route('admin.preparacion.index'))->assertOk()->getContent();

    return $pantallas;
}

test('nombres con HTML: escapados en todas las pantallas', function () {
    foreach (escapadoUrgenciasPantallas() as $nombre => $html) {
        expect(str_contains($html, EP_XSS_NOMBRE))->toBeFalse("HTML crudo en: {$nombre}")
            ->and(str_contains($html, EP_XSS_NOMBRE_ESCAPADO))->toBeTrue("Falta el nombre escapado en: {$nombre}");
    }
});

test('la observación con HTML y JS: escapada en el popup de urgencias y ausente en todo lo demás', function () {
    $pantallas = escapadoUrgenciasPantallas();

    expect($pantallas['popup urgencias'])->toContain('Dolor '.EP_XSS_OBSERVACION_ESCAPADA)->not->toContain(EP_XSS_OBSERVACION);
    foreach (collect($pantallas)->except('popup urgencias') as $nombre => $html) {
        expect(str_contains($html, 'alert(2)'))->toBeFalse("La observación no debe estar en: {$nombre}");
    }
});

test('el cartel: el nombre llega como texto JSON con < > codificados y se pone con x-text, nunca como HTML', function () {
    $this->actingAs($this->medico);
    $respuesta = $this->getJson(route('admin.atencion.contadores'), $this->ajax)->assertOk();

    expect($respuesta->getContent())->not->toContain('<img')->toContain('\u003Cimg')
        ->and($respuesta->json('ultima.nombre'))->toBe('Duarte '.EP_XSS_NOMBRE.', '.$this->paciente->persona->nombres);

    $html = $this->get(route('admin.atencion.index'))->getContent();
    expect($html)->toContain('x-text="aviso?.nombre"')->not->toContain('x-html');
    expect(file_get_contents(resource_path('js/pendientes-profesional.js')))->not->toContain('innerHTML')->not->toContain('insertAdjacentHTML')
        ->toContain("import fragmentoInerte from './fragmento-inerte'");
});

test('mutación: si se rompe el escapado, el test lo detecta (y el proyecto queda intacto)', function (string $vista, string $buscar, string $reemplazo, Closure $pantalla, string $crudo) {
    $originalEnProyecto = file_get_contents(resource_path("views/{$vista}"));

    expect($pantalla($this))->not->toContain($crudo);
    expect(conVistaMutada($vista, $buscar, $reemplazo, fn () => $pantalla($this)))->toContain($crudo);

    expect(file_get_contents(resource_path("views/{$vista}")))->toBe($originalEnProyecto);
    expect($pantalla($this))->not->toContain($crudo);
})->with([
    'popup de urgencias (observación)' => [
        'admin/atencion/_urgencias.blade.php',
        '{{ $turno->consulta->triaje_observacion }}', '{!! $turno->consulta->triaje_observacion !!}',
        fn ($t) => $t->actingAs($t->medico)->get(route('admin.atencion.urgencias'), $t->ajax)->getContent(),
        EP_XSS_OBSERVACION,
    ],
    'popup de entre pacientes (paciente)' => [
        'admin/atencion/_paciente.blade.php',
        '{{ $paciente->persona->nombre_completo }}', '{!! $paciente->persona->nombre_completo !!}',
        fn ($t) => $t->actingAs($t->medico)->get(route('admin.atencion.entre-pacientes'), $t->ajax)->getContent(),
        EP_XSS_NOMBRE,
    ],
    'urgencias de hoy de recepción (paciente)' => [
        'admin/urgencias/index.blade.php',
        '{{ $turno->paciente->persona->nombre_completo }}', '{!! $turno->paciente->persona->nombre_completo !!}',
        fn ($t) => $t->actingAs($t->recepcion)->get(route('admin.turnos.urgencias'))->getContent(),
        EP_XSS_NOMBRE,
    ],
])->group('lento'); // docs/pruebas.md: no corre en el comando rápido
