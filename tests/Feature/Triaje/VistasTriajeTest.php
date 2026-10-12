<?php

/*
 * Vistas del triaje: bloque de urgente y observación, banner sin parámetros aprobados, etiquetas de nivel junto a
 * cada signo (texto y color), franja de la pantalla de atención, pastillas URGENTE y "Signos alterados" con el orden
 * de las listas (los urgentes primero; los signos alterados no ordenan), el JavaScript de confirmación y el escapado.
 * Rangos de prueba inventados. Escenario: escenario.php.
 */

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Paciente;
use App\Models\ParametroClinico;
use App\Models\Persona;
use App\Models\User;

beforeEach(function () {
    hcEscenario();
    $this->enfermera = User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->personalDeSalud()->create();
});

/** Aprueba los rangos de prueba de temperatura (normal 36-37,5; muy alterado fuera de 35-40). */
function vistasTriajeAprobarTemperatura(): void
{
    ParametroClinico::where('codigo', 'TEMPERATURA')->sole()->forceFill([
        'muy_alterado_min' => 35, 'muy_alterado_max' => 40, 'normal_min' => 36, 'normal_max' => 37.5,
        'estado_id' => Estado::idDe(Estado::APROBADO), 'aprobado_en' => now(),
    ])->save();
}

/** Turno de hoy con triaje iniciado por la enfermera, con lo que se autoguarde. */
function vistasTriajeConsulta(array $datos = [], array $turno = []): Consulta
{
    test()->actingAs(test()->enfermera)->post(route('admin.preparacion.preparar', $t = hcTurno($turno)));
    $consulta = Consulta::where('turno_id', $t->id)->sole();
    if ($datos !== []) {
        hcAutoguardar($consulta, $datos)->assertSuccessful();
    }

    return $consulta->fresh();
}

test('el formulario de triaje: bloque de urgente y observación al comienzo, y el banner sin parámetros aprobados', function () {
    $consulta = vistasTriajeConsulta();

    $this->get(route('admin.preparacion.formulario', $consulta))->assertOk()
        ->assertSee('Los valores de referencia todavía no fueron aprobados por un médico: el sistema no marca alertas.')
        ->assertSeeInOrder(['data-bloque="triaje"', 'Urgente', 'Observación del triaje', 'Anamnesis', 'Signos vitales'], false)
        ->assertSee('name="con_triaje" value="1"', false);

    ParametroClinico::query()->update(['estado_id' => Estado::idDe(Estado::APROBADO)]);
    $this->get(route('admin.preparacion.formulario', $consulta))->assertDontSee('todavía no fueron aprobados');
});

test('marcado urgente: dice quién y cuándo', function () {
    $consulta = vistasTriajeConsulta(['con_triaje' => '1', 'triaje_urgente' => '1']);

    $this->get(route('admin.preparacion.formulario', $consulta))->assertSee('Marcado como urgente por')->assertSee('06/10/2026 09:00');
});

test('etiquetas de nivel junto a cada signo, con texto: en el formulario y en la lectura', function () {
    vistasTriajeAprobarTemperatura();
    $consulta = vistasTriajeConsulta(['examen' => ['temperatura' => '38']]);

    $this->get(route('admin.preparacion.formulario', $consulta))->assertSee('data-nivel-signo="FUERA_DE_RANGO"', false)->assertSee('Fuera de lo normal');

    hcAutoguardar($consulta->fresh(), ['examen' => ['temperatura' => '41'], 'confirmaciones' => ['temperatura' => '41']])->assertOk();
    $this->actingAs($this->medico)->post(route('admin.atencion.atender', $consulta->turno));
    $this->get(route('admin.consultas.atencion', $consulta))->assertOk()
        ->assertSee('Muy alterado')->assertSee('confirmado')
        // La franja: URGENTE no (no está marcada) y los signos alterados con su texto.
        ->assertSee('data-franja-triaje', false)->assertSee('Signos alterados:')->assertSee('Temperatura 41 °C (muy alterado)');

    hcFinalizar($consulta->fresh(), hcDatos(['examen' => ['temperatura' => '41']]))->assertSessionHasNoErrors();
    $this->get(route('admin.consultas.show', $consulta))->assertSee('data-nivel-signo="MUY_ALTERADO"', false);
});

test('pantalla de atención: el bloque de urgente y observación (editable) y la franja URGENTE', function () {
    $consulta = vistasTriajeConsulta(['con_triaje' => '1', 'triaje_urgente' => '1', 'triaje_observacion' => 'Dolor torácico.']);
    $this->actingAs($this->medico)->post(route('admin.atencion.atender', $consulta->turno));

    $this->get(route('admin.consultas.atencion', $consulta))->assertOk()
        ->assertSee('URGENTE')->assertSee('data-bloque="triaje"', false)->assertSee('Dolor torácico.');
});

describe('listas', function () {
    beforeEach(function () {
        vistasTriajeAprobarTemperatura();
        $paciente = fn (string $apellidos, string $ficha) => Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => $apellidos, 'nombres' => 'Ana'])->id, 'nro_ficha' => $ficha]);
        // 08:00 sin nada; 08:30 con signos alterados; 09:00 urgente (con observación que no debe verse en las listas).
        vistasTriajeConsulta([], ['hora_inicio' => '08:00', 'hora_fin' => '08:30']);
        vistasTriajeConsulta(['examen' => ['temperatura' => '38']], ['paciente_id' => $paciente('Benítez', 'FP-0000010')->id, 'hora_inicio' => '08:30', 'hora_fin' => '09:00']);
        vistasTriajeConsulta(['con_triaje' => '1', 'triaje_urgente' => '1', 'triaje_observacion' => 'OBSERVACION-SECRETA'],
            ['paciente_id' => $paciente('Cáceres', 'FP-0000011')->id, 'hora_inicio' => '09:00', 'hora_fin' => '09:30']);
    });

    test('Triaje: URGENTE primero; "Signos alterados" se marca pero no ordena; sin la observación', function () {
        $this->get(route('admin.preparacion.index'))->assertOk()
            ->assertSeeInOrder(['Cáceres, Ana', 'URGENTE', 'Duarte, Carmen', 'Benítez, Ana', 'Signos alterados'])
            ->assertDontSee('OBSERVACION-SECRETA');
    });

    test('Consulta (el profesional): lo mismo en la agenda de hoy', function () {
        $this->actingAs($this->medico)->get(route('admin.atencion.index'))->assertOk()
            ->assertSeeInOrder(['Agenda de hoy', 'Cáceres, Ana', 'URGENTE', 'Duarte, Carmen', 'Benítez, Ana', 'Signos alterados'])
            ->assertDontSee('OBSERVACION-SECRETA');
    });

    test('la agenda (Turnos) no muestra las pastillas clínicas', function () {
        $this->actingAs(User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR', 'EDITAR']])->create())
            ->get(route('admin.turnos.index'))->assertOk()
            ->assertSee('Cáceres, Ana')->assertDontSee('URGENTE')->assertDontSee('Signos alterados')->assertDontSee('OBSERVACION-SECRETA');
    });
});

test('el JavaScript maneja requiere_confirmacion con "Sí, es correcto" y "Corregir", sin insertar HTML', function () {
    $js = file_get_contents(resource_path('js/consulta-autoguardado.js'));
    $signos = file_get_contents(resource_path('views/admin/consultas/secciones/_signos.blade.php'));

    expect($js)->toContain("cuerpo.codigo === 'requiere_confirmacion'")->toContain('confirmaciones[${campo}]')->toContain('confirmar(campo)')->toContain('corregir(campo)')
        ->not->toContain('innerHTML')
        ->and($signos)->toContain('Pendiente de confirmar')->toContain('Sí, es correcto')->toContain('Corregir')
        ->toContain("x-text=\"pendientes['{{ \$campo }}'].mensaje\"");
});

test('escapado: HTML y JS en el nombre del paciente y en la observación, en el formulario, la pantalla y las listas', function () {
    $malo = '<img src=x onerror=alert(1)><script>alert(2)</script>';
    $paciente = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => $malo, 'nombres' => 'Ana'])->id, 'nro_ficha' => 'FP-0000020']);
    $consulta = vistasTriajeConsulta(['con_triaje' => '1', 'triaje_urgente' => '1', 'triaje_observacion' => $malo], ['paciente_id' => $paciente->id]);

    $paginas = [
        $this->get(route('admin.preparacion.formulario', $consulta))->getContent(),
        $this->get(route('admin.preparacion.index'))->getContent(),
    ];
    $this->actingAs($this->medico);
    $paginas[] = $this->get(route('admin.atencion.index'))->getContent();
    $this->post(route('admin.atencion.atender', $consulta->turno));
    $paginas[] = $this->get(route('admin.consultas.atencion', $consulta))->getContent();

    foreach ($paginas as $html) {
        expect($html)->not->toContain('<img src=x')->not->toContain('<script>alert(2)')->toContain('&lt;img src=x onerror=alert(1)&gt;');
    }
});
