<?php

/*
 * Barra "Documentos de la consulta" (atención y consulta cerrada): cada botón sale solo con el permiso que ya exige su
 * función, Recepción no ve nada clínico y los textos nuevos ("Pedir estudio", "Informe de estudio"). Datos inventados.
 * Funciones con el prefijo "bd".
 */

use App\Models\Receta;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

const BD_TODOS = ['HISTORIA_CLINICA' => ['VER', 'CREAR', 'EDITAR'], 'TURNOS' => ['VER'], 'RECETAS' => ['VER', 'CREAR', 'EDITAR'],
    'ORDENES_ESTUDIO' => ['VER', 'CREAR'], 'REPOSO_MEDICO' => ['VER', 'CREAR'], 'PDF_CLINICO' => ['EXPORTAR']];

beforeEach(fn () => hcEscenario());
afterEach(fn () => Carbon::setTestNow());

/** Las marcas data-documento que salen en la respuesta. */
function bdDocumentos($respuesta): array
{
    preg_match_all('/data-documento="([a-z-]+)"/', $respuesta->getContent(), $m);

    return array_values(array_unique($m[1]));
}

test('con todos los permisos, la consulta abierta muestra todos los botones y los textos nuevos', function () {
    $this->medico = hcConPerfil($this->medico, BD_TODOS);
    $consulta = hcConsultaEnCurso();

    $respuesta = $this->actingAs($this->medico)->get(route('admin.consultas.atencion', $consulta))->assertOk()
        ->assertSee('Documentos de la consulta')->assertSee('Pedir estudio')->assertSee('Informe de estudio')
        ->assertSee('Reposo médico')->assertSee('PDF de triaje')->assertSee('PDF de indicaciones')
        ->assertDontSee('Órdenes de estudio');

    expect(bdDocumentos($respuesta))->toEqualCanonicalizing(['receta', 'pedir-estudio', 'informe-estudio', 'reposo', 'indicaciones']);
});

test('cada botón depende de su permiso', function (string $modulo, string $marca, string $texto) {
    $consulta = hcConsultaEnCurso();

    $con = hcConPerfil($this->medico, BD_TODOS);
    $this->actingAs($con)->get(route('admin.consultas.atencion', $consulta))->assertOk()->assertSee($texto, false);
    expect(bdDocumentos($this->get(route('admin.consultas.atencion', $consulta))))->toContain($marca);

    $sin = hcConPerfil($this->medico, array_diff_key(BD_TODOS, [$modulo => 1]));
    $this->actingAs($sin)->get(route('admin.consultas.atencion', $consulta))->assertOk();
    expect(bdDocumentos($this->get(route('admin.consultas.atencion', $consulta))))->not->toContain($marca);
})->with([
    'receta' => ['RECETAS', 'receta', 'data-documento="receta"'],
    'pedir estudio' => ['ORDENES_ESTUDIO', 'pedir-estudio', 'data-documento="pedir-estudio"'],
    'reposo médico' => ['REPOSO_MEDICO', 'reposo', 'data-documento="reposo"'],
]);

test('los PDF salen solo con PDF_CLINICO', function () {
    $consulta = hcConsultaEnCurso();

    $this->actingAs(hcConPerfil($this->medico, BD_TODOS))->get(route('admin.consultas.atencion', $consulta))->assertSee('data-pdf="consulta"', false);
    $this->actingAs(hcConPerfil($this->medico, array_diff_key(BD_TODOS, ['PDF_CLINICO' => 1])))
        ->get(route('admin.consultas.atencion', $consulta))->assertOk()->assertDontSee('data-pdf="consulta"', false)->assertDontSee('data-pdf="triaje"', false);
});

test('Recepción no ve ningún botón clínico', function () {
    $recepcion = User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'PACIENTES' => ['VER']])
        ->conPersona(['apellidos' => 'Acosta', 'nombres' => 'Marta'])->create();
    $abierta = hcConsultaEnCurso();
    $cerrada = hcConsulta();

    foreach ([route('admin.consultas.atencion', $abierta), route('admin.consultas.show', $cerrada)] as $url) {
        $respuesta = $this->actingAs($recepcion)->get($url);
        expect($respuesta->status())->toBeIn([302, 403, 404]);
        $respuesta->assertDontSee('Documentos de la consulta');
    }
});

test('la consulta cerrada sigue las reglas de hoy: sin paneles, y los botones de las políticas', function () {
    $this->medico = hcConPerfil($this->medico, BD_TODOS);
    $cerrada = hcConsulta();

    $respuesta = $this->actingAs($this->medico)->get(route('admin.consultas.show', $cerrada))->assertOk()
        ->assertSee('Documentos de la consulta')->assertSee('Pedir estudio')->assertSee('Reposo médico')
        ->assertDontSee('Órdenes de estudio');
    $esperado = ['pedir-estudio', 'reposo'];
    if (Gate::forUser($this->medico)->allows('create', [Receta::class, $cerrada])) {
        $esperado[] = 'receta';
    }
    expect(bdDocumentos($respuesta))->toEqualCanonicalizing($esperado);
});

test('no se muestran montos en la barra', function () {
    $this->medico = hcConPerfil($this->medico, BD_TODOS);
    $html = $this->actingAs($this->medico)->get(route('admin.consultas.atencion', hcConsultaEnCurso()))->getContent();
    preg_match('#<nav class="basis-full.*?</nav>#s', $html, $barra);

    expect($barra[0] ?? '')->not->toMatch('/Gs\.?|\$|\d{1,3}(\.\d{3})+/');
});
