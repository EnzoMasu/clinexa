<?php

/*
 * Escapado en las pantallas del flujo de atención: nombres y contenido clínico con HTML y JS se muestran
 * siempre escapados ({{ }}), en la pantalla Consulta, la lista y el formulario de Preparación, la pantalla
 * de atención (barra y panel de historial) y la vista previa de Cerrar jornada.
 *
 * Mutación: para comprobar que estos tests detectan un descuido de verdad, se rompe a propósito el escapado
 * en tres lugares ({{ }} -> {!! !!}) sobre una COPIA de las vistas (en una carpeta temporal antepuesta al
 * buscador de vistas): los archivos del proyecto nunca se tocan.
 */

use App\Models\Consulta;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Support\Facades\File;

const XSS_IMG = '<img src=x onerror=alert(1)>';
const XSS_IMG_ESCAPADO = '&lt;img src=x onerror=alert(1)&gt;';

beforeEach(function () {
    hcEscenario();
    $this->medico = hcDarPermisos($this->medico, ['TURNOS' => ['VER', 'EDITAR']]);
    $this->actingAs($this->medico);

    // Paciente y profesional con HTML en el nombre; una consulta anterior con HTML en el motivo.
    $this->paciente->persona->update(['apellidos' => 'Duarte '.XSS_IMG]);
    $this->otroProfesional->persona->update(['apellidos' => 'Insfrán '.XSS_IMG]);
    hcConsulta(['motivo_consulta' => 'Anterior '.XSS_IMG]);
    $this->turno = hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30']);
    hcTurno(['hora_inicio' => '12:00', 'hora_fin' => '12:30']); // queda en la agenda y en la vista previa de Cerrar jornada
    $otroPaciente = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Gómez', 'nombres' => 'Ana'])->id, 'nro_ficha' => 'FP-0000002']);
    hcTurno(['profesional_id' => $this->otroProfesional->id, 'paciente_id' => $otroPaciente->id, 'hora_inicio' => '11:00', 'hora_fin' => '11:30']);
    $this->enfermera = User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->create();
});

afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Las pantallas a revisar: nombre => respuesta (ya como el usuario que corresponde). */
function pantallasDelFlujo(): array
{
    $t = test();
    $t->actingAs($t->medico);
    $consulta = hcEnCurso($t->turno);
    $pantallas = [
        'consulta' => $t->get(route('admin.atencion.index')),
        'consulta (actualización)' => $t->get(route('admin.atencion.index'), ['X-Requested-With' => 'XMLHttpRequest']),
        'cerrar jornada' => $t->get(route('admin.atencion.cerrar-jornada')),
        'atención' => $t->get(route('admin.consultas.atencion', $consulta)),
    ];
    $t->actingAs($t->enfermera);
    $pantallas['preparación (lista)'] = $t->get(route('admin.preparacion.index'));
    $pantallas['preparación (formulario)'] = $t->get(route('admin.preparacion.formulario', $consulta));

    return $pantallas;
}

test('nombres y motivo con HTML: escapados en todas las pantallas del flujo', function () {
    foreach (pantallasDelFlujo() as $nombre => $respuesta) {
        $html = $respuesta->assertOk()->getContent();
        expect(str_contains($html, XSS_IMG))->toBeFalse("HTML crudo en: {$nombre}")
            ->and(str_contains($html, XSS_IMG_ESCAPADO))->toBeTrue("Falta el texto escapado en: {$nombre}");
    }
});

test('el buscador de pacientes de "Atender sin turno" devuelve el nombre como texto JSON, con < > codificados', function () {
    $respuesta = $this->getJson(route('admin.atencion.pacientes', ['q' => 'Duarte']))->assertOk();

    expect($respuesta->getContent())->not->toContain('<img')->toContain('\u003Cimg')
        ->and($respuesta->json('0.texto'))->toContain(XSS_IMG); // el JS lo pone con textContent / x-text, nunca como HTML
});

test('el panel de historial muestra el motivo anterior escapado', function () {
    $html = $this->get(route('admin.consultas.atencion', hcEnCurso($this->turno)))->assertOk()->getContent();

    expect($html)->toContain('Anterior '.XSS_IMG_ESCAPADO)->not->toContain('Anterior '.XSS_IMG);
});

/**
 * Renderiza con una copia de las vistas donde $vista tiene $buscar reemplazado por $reemplazo.
 * Devuelve lo que devuelva $pedido. La copia se borra al terminar, pase lo que pase.
 */
function conVistaMutada(string $vista, string $buscar, string $reemplazo, Closure $pedido): mixed
{
    $copia = sys_get_temp_dir().'/clinexa-mutacion-'.bin2hex(random_bytes(6));
    File::copyDirectory(resource_path('views'), $copia);
    try {
        $archivo = "{$copia}/{$vista}";
        $original = file_get_contents($archivo);
        expect(substr_count($original, $buscar))->toBe(1, "La mutación tiene que aplicarse en un solo lugar de {$vista}");
        file_put_contents($archivo, str_replace($buscar, $reemplazo, $original));

        $finder = app('view')->getFinder();
        $rutas = $finder->getPaths();
        $finder->setPaths([$copia, ...$rutas]);
        $finder->flush();
        try {
            return $pedido();
        } finally {
            $finder->setPaths($rutas);
            $finder->flush();
        }
    } finally {
        File::deleteDirectory($copia);
    }
}

test('mutación: si se rompe el escapado, el test lo detecta (y el proyecto queda intacto)', function (string $vista, string $buscar, string $reemplazo, Closure $pantalla) {
    $originalEnProyecto = file_get_contents(resource_path("views/{$vista}"));

    // Sin mutar: escapado.
    expect($pantalla($this))->not->toContain(XSS_IMG)->toContain(XSS_IMG_ESCAPADO);

    // Mutado: el HTML crudo aparece, que es justo lo que el test de escapado prohíbe.
    $mutado = conVistaMutada($vista, $buscar, $reemplazo, fn () => $pantalla($this));
    expect($mutado)->toContain(XSS_IMG);

    // Y el archivo real no cambió.
    expect(file_get_contents(resource_path("views/{$vista}")))->toBe($originalEnProyecto);
    expect($pantalla($this))->not->toContain(XSS_IMG);
})->with([
    'pantalla de atención (barra del paciente)' => [
        'admin/consultas/_barra-paciente.blade.php',
        '{{ $persona->nombre_completo }}', '{!! $persona->nombre_completo !!}',
        fn ($t) => $t->actingAs($t->medico)->get(route('admin.consultas.atencion', Consulta::where('turno_id', $t->turno->id)->first() ?? hcEnCurso($t->turno)))->getContent(),
    ],
    'panel de historial (motivo anterior)' => [
        'admin/atencion/pantalla.blade.php',
        '{{ Str::limit($anterior->motivo_consulta, 60) }}', '{!! Str::limit($anterior->motivo_consulta, 60) !!}',
        fn ($t) => $t->actingAs($t->medico)->get(route('admin.consultas.atencion', Consulta::where('turno_id', $t->turno->id)->first() ?? hcEnCurso($t->turno)))->getContent(),
    ],
    'lista de Preparación (profesional)' => [
        'admin/preparacion/_tabla.blade.php',
        '{{ $turno->profesional->persona->nombre_completo }}', '{!! $turno->profesional->persona->nombre_completo !!}',
        fn ($t) => $t->actingAs($t->enfermera)->get(route('admin.preparacion.index', ['profesional' => $t->otroProfesional->id]))->getContent(),
    ],
]);
