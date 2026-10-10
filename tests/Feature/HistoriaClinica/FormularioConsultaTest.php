<?php

use App\Models\BloqueAnamnesis;
use App\Models\CatalogoCIE10;
use App\Models\Consulta;
use App\Models\Diagnostico;
use App\Models\Estado;
use App\Models\ExamenFisico;
use App\Models\TipoBloqueAnamnesis;
use App\Models\User;
use App\Support\Atencion\Autoguardado;
use App\Support\Atencion\FormularioConsulta;
use Illuminate\Support\Carbon;

beforeEach(fn () => hcEscenario());

afterEach(fn () => Carbon::setTestNow());

/** n diagnósticos con códigos distintos (crea los que falten en el catálogo). */
function diagnosticosDistintos(int $cantidad): array
{
    return collect(range(1, $cantidad))->map(function (int $n) {
        CatalogoCIE10::firstOrCreate(['codigo' => "A0{$n}"], ['descripcion' => "Código {$n}", 'capitulo' => 'I']);

        return ['id' => '', 'activo' => '1', 'codigo_cie10' => "A0{$n}", 'tipo' => 'CONFIRMADO', 'descripcion_adicional' => ''];
    })->all();
}

describe('alta', function () {
    test('guarda motivo, anamnesis en orden, examen (coma decimal) y diagnóstico principal', function () {
        hcGuardarNueva()->assertSessionHasNoErrors();

        $consulta = Consulta::sole();
        expect($consulta->bloquesAnamnesis()->orderBy('orden')->get()->map(fn ($b) => [$b->orden, $b->tipoBloqueAnamnesis->nombre, $b->contenido, $b->activo])->all())
            ->toBe([[1, 'Enfermedad actual', 'Odinofagia de 24 horas.', true], [2, 'Alergias', 'Penicilina.', true]])
            ->and($consulta->examenFisico->only(['presion_arterial', 'frecuencia_cardiaca', 'temperatura', 'peso', 'talla']))
            ->toBe(['presion_arterial' => '120/80', 'frecuencia_cardiaca' => 88, 'temperatura' => '38.2', 'peso' => '61.50', 'talla' => '165.0'])
            ->and($consulta->diagnosticos->map(fn ($d) => [$d->codigo_cie10, $d->tipo->value, $d->principal, $d->activo])->all())
            ->toBe([['J06.9', 'PRESUNTIVO', true, true]]);
    });

    test('sin ningún campo del examen físico no se crea la fila', function () {
        hcGuardarNueva(['examen' => ['presion_arterial' => '', 'temperatura' => '  ', 'hallazgos' => '']])->assertSessionHasNoErrors();

        expect(ExamenFisico::count())->toBe(0);
    });

    test('la pantalla de atención lleva novalidate, va a Finalizar y ofrece solo los tipos de bloque activos', function () {
        TipoBloqueAnamnesis::create(['nombre' => 'Hábitos', 'estado_id' => Estado::idDe(Estado::INACTIVO)]);
        $consulta = hcEnCurso();

        $html = $this->get(route('admin.consultas.atencion', $consulta))->assertOk()
            ->assertViewHas('datos', fn ($datos) => collect($datos['tiposBloque'])->pluck('nombre')->all() === ['Alergias', 'Enfermedad actual'])
            ->getContent();
        expect($html)->toMatch('#<form id="form-consulta"[^>]*novalidate#')
            ->toContain('action="'.route('admin.consultas.finalizar', $consulta).'"');
    });
});

describe('validaciones', function () {
    test('motivo obligatorio y de hasta 500 caracteres', function (string $motivo) {
        hcGuardarNueva(['motivo_consulta' => $motivo])->assertSessionHasErrors('motivo_consulta');
        expect(Consulta::sole())->enCurso()->toBeTrue()->motivo_consulta->toBeNull();
    })->with(['vacío' => ['   '], 'largo' => [str_repeat('a', 501)]]);

    test('examen físico: rangos y formatos', function (string $campo, string $valor) {
        hcGuardarNueva(['examen' => [$campo => $valor]])->assertSessionHasErrors("examen.{$campo}");
        expect(Consulta::sole())->enCurso()->toBeTrue()->motivo_consulta->toBeNull();
    })->with([
        'temperatura baja' => ['temperatura', '29,9'],
        'temperatura alta' => ['temperatura', '45,1'],
        'temperatura con dos decimales' => ['temperatura', '36,55'],
        'frecuencia cardíaca baja' => ['frecuencia_cardiaca', '19'],
        'frecuencia cardíaca alta' => ['frecuencia_cardiaca', '301'],
        'frecuencia cardíaca con decimales' => ['frecuencia_cardiaca', '80,5'],
        'frecuencia respiratoria baja' => ['frecuencia_respiratoria', '4'],
        'frecuencia respiratoria alta' => ['frecuencia_respiratoria', '81'],
        'saturación mayor a 100' => ['saturacion_oxigeno', '101'],
        'saturación negativa' => ['saturacion_oxigeno', '-1'],
        'peso cero' => ['peso', '0'],
        'peso mayor a 500' => ['peso', '500,01'],
        'talla baja' => ['talla', '19'],
        'talla alta' => ['talla', '251'],
        'presión sin barra' => ['presion_arterial', '12080'],
        'presión con letras' => ['presion_arterial', '12/ocho'],
        'hallazgos largos' => ['hallazgos', str_repeat('a', 2001)],
        'temperatura con letras' => ['temperatura', 'alta'],
    ]);

    test('examen físico: los extremos válidos y el punto decimal también se aceptan', function () {
        hcGuardarNueva(['examen' => ['temperatura' => '30', 'frecuencia_cardiaca' => '300', 'frecuencia_respiratoria' => '5', 'saturacion_oxigeno' => '0',
            'peso' => '0.5', 'talla' => '250', 'presion_arterial' => '90/60']])->assertSessionHasNoErrors();

        expect(ExamenFisico::sole()->only(['temperatura', 'peso', 'saturacion_oxigeno']))->toBe(['temperatura' => '30.0', 'peso' => '0.50', 'saturacion_oxigeno' => 0]);
    });

    test('anamnesis: tipo y contenido obligatorios, contenido de hasta 5000', function (array $fila, string $campo) {
        hcGuardarNueva(['anamnesis' => [['id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'x', ...$fila]]])
            ->assertSessionHasErrors("anamnesis.0.{$campo}");
    })->with([
        'sin tipo' => [['tipo_bloque_anamnesis_id' => ''], 'tipo_bloque_anamnesis_id'],
        'sin contenido' => [['contenido' => ' '], 'contenido'],
        'contenido largo' => [['contenido' => str_repeat('a', 5001)], 'contenido'],
        'tipo inexistente' => [['tipo_bloque_anamnesis_id' => '999'], 'tipo_bloque_anamnesis_id'],
    ]);

    test('anamnesis: un tipo inactivo no se puede elegir en una fila nueva', function () {
        $inactivo = TipoBloqueAnamnesis::create(['nombre' => 'Hábitos', 'estado_id' => Estado::idDe(Estado::INACTIVO)]);

        hcGuardarNueva(['anamnesis' => [['id' => '', 'tipo_bloque_anamnesis_id' => $inactivo->id, 'contenido' => 'Fuma.']]])
            ->assertSessionHasErrors(['anamnesis.0.tipo_bloque_anamnesis_id' => 'Elija un tipo de bloque activo.']);
    });

    test('anamnesis: hasta 30 bloques vigentes', function () {
        $fila = ['id' => '', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'x'];

        hcGuardarNueva(['anamnesis' => array_fill(0, FormularioConsulta::MAXIMO_BLOQUES + 1, $fila)])->assertSessionHasErrors('anamnesis');
        hcGuardarNueva(['anamnesis' => array_fill(0, FormularioConsulta::MAXIMO_BLOQUES, $fila)])->assertSessionHasNoErrors();
    });

    test('diagnósticos: hasta 10 vigentes', function () {
        hcGuardarNueva(['diagnosticos' => diagnosticosDistintos(FormularioConsulta::MAXIMO_DIAGNOSTICOS + 1)])->assertSessionHasErrors('diagnosticos');
        hcGuardarNueva(['diagnosticos' => diagnosticosDistintos(FormularioConsulta::MAXIMO_DIAGNOSTICOS)])->assertSessionHasNoErrors();
    });

    test('diagnósticos: sin repetir el código entre los vigentes', function () {
        $fila = ['id' => '', 'activo' => '1', 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO'];

        hcGuardarNueva(['diagnosticos' => [$fila, [...$fila, 'tipo' => 'CONFIRMADO']]])
            ->assertSessionHasErrors(['diagnosticos' => 'El código J06.9 está repetido entre los diagnósticos vigentes.']);
    });

    test('diagnósticos: exactamente un principal entre los vigentes', function (string $principal) {
        hcGuardarNueva(['diagnosticos' => diagnosticosDistintos(2), 'diagnostico_principal' => $principal])
            ->assertSessionHasErrors(['diagnosticos' => 'Marque cuál de los diagnósticos vigentes es el principal.']);
    })->with(['ninguno' => [''], 'una fila que no existe' => ['5']]);

    test('diagnósticos: el código tiene que existir y estar activo; tipo PRESUNTIVO o CONFIRMADO; descripción de hasta 500', function (array $fila, string $campo) {
        CatalogoCIE10::create(['codigo' => 'B99', 'descripcion' => 'Inactivo', 'capitulo' => 'I', 'estado_id' => Estado::idDe(Estado::INACTIVO)]);

        hcGuardarNueva(['diagnosticos' => [['id' => '', 'activo' => '1', 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO', ...$fila]]])
            ->assertSessionHasErrors("diagnosticos.0.{$campo}");
    })->with([
        'código inexistente' => [['codigo_cie10' => 'ZZZ'], 'codigo_cie10'],
        'código inactivo' => [['codigo_cie10' => 'B99'], 'codigo_cie10'],
        'sin código' => [['codigo_cie10' => ''], 'codigo_cie10'],
        'tipo inválido' => [['tipo' => 'DEFINITIVO'], 'tipo'],
        'descripción larga' => [['descripcion_adicional' => str_repeat('a', 501)], 'descripcion_adicional'],
    ]);

    test('las filas de otra consulta no se pueden tocar', function () {
        $ajena = hcConsulta();
        $consulta = hcConsultaEnCurso();
        $bloqueAjeno = $ajena->bloquesAnamnesis()->first();

        hcActualizar($consulta, [...hcFilasGuardadas($consulta), 'anamnesis' => [['id' => (string) $bloqueAjeno->id, 'activo' => '1', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'Pisado']]])
            ->assertStatus(422)->assertJsonValidationErrors('anamnesis.0.id');
        expect($bloqueAjeno->fresh()->contenido)->toBe('Odinofagia de 24 horas.');
    });
});

describe('retirar y reponer', function () {
    test('un bloque y un diagnóstico guardados se retiran (no se borran) y se reponen', function () {
        $consulta = hcConsultaEnCurso(['diagnosticos' => diagnosticosDistintos(2), 'diagnostico_principal' => '0']);
        $datos = hcFilasGuardadas($consulta);

        // Retira el primer bloque y el diagnóstico principal; el principal pasa al otro.
        $datos['anamnesis'][0]['activo'] = '0';
        $datos['diagnosticos'][0]['activo'] = '0';
        $datos['diagnostico_principal'] = '1';
        hcActualizar($consulta, $datos)->assertSessionHasNoErrors();

        expect(BloqueAnamnesis::count())->toBe(2)
            ->and($consulta->bloquesAnamnesis()->orderBy('orden')->pluck('activo')->all())->toBe([false, true])
            ->and(Diagnostico::orderBy('id')->get()->map(fn ($d) => [$d->activo, $d->principal])->all())->toBe([[false, false], [true, true]]);

        // La vista de lectura los muestra atenuados con "Retirado".
        $this->get(route('admin.consultas.show', $consulta))->assertOk()->assertSee('Retirado')->assertSee('Odinofagia de 24 horas.');

        // Reponer.
        $datos = hcFilasGuardadas($consulta);
        $datos['anamnesis'][0]['activo'] = '1';
        $datos['diagnosticos'][0]['activo'] = '1';
        hcActualizar($consulta, $datos)->assertSessionHasNoErrors();
        expect($consulta->bloquesAnamnesis()->pluck('activo')->unique()->all())->toBe([true])
            ->and(Diagnostico::where('activo', true)->count())->toBe(2);
    });

    test('los retirados no cuentan: ni para los repetidos, ni para el máximo, ni para el principal', function () {
        $consulta = hcConsultaEnCurso();
        $datos = hcFilasGuardadas($consulta);
        $datos['diagnosticos'][0]['activo'] = '0'; // J06.9 retirado
        $datos['diagnosticos'][] = ['id' => '', 'activo' => '1', 'codigo_cie10' => 'J06.9', 'tipo' => 'CONFIRMADO', 'descripcion_adicional' => ''];
        $datos['diagnostico_principal'] = '1';

        hcActualizar($consulta, $datos)->assertSessionHasNoErrors();
        expect(Diagnostico::orderBy('id')->get()->map(fn ($d) => [$d->codigo_cie10, $d->tipo->value, $d->activo, $d->principal])->all())
            ->toBe([['J06.9', 'PRESUNTIVO', false, false], ['J06.9', 'CONFIRMADO', true, true]]);

        // Con todos los diagnósticos retirados no hace falta principal.
        $datos = hcFilasGuardadas($consulta);
        $datos['diagnosticos'][1]['activo'] = '0';
        $datos['diagnostico_principal'] = '';
        hcActualizar($consulta, $datos)->assertSessionHasNoErrors();
        expect(Diagnostico::where('activo', true)->count())->toBe(0);
    });

    test('una fila nueva nace vigente aunque llegue marcada como retirada', function () {
        hcGuardarNueva(['anamnesis' => [['id' => '', 'activo' => '0', 'tipo_bloque_anamnesis_id' => $this->alergias->id, 'contenido' => 'Polen.']]])
            ->assertSessionHasNoErrors();

        expect(BloqueAnamnesis::sole()->activo)->toBeTrue();
    });

    test('una fila guardada que no viene en el envío queda como estaba', function () {
        $consulta = hcConsultaEnCurso();
        $datos = hcFilasGuardadas($consulta);
        array_shift($datos['anamnesis']);

        hcActualizar($consulta, $datos)->assertSessionHasNoErrors();
        expect(BloqueAnamnesis::count())->toBe(2)->and(BloqueAnamnesis::pluck('activo')->unique()->all())->toBe([true]);
    });

    test('un tipo de bloque y un código CIE-10 que se desactivaron siguen valiendo en la fila que ya los tenía', function () {
        $consulta = hcConsultaEnCurso();
        $this->alergias->desactivar();
        CatalogoCIE10::whereKey('J06.9')->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);

        // El formulario los ofrece en su fila, marcados como inactivos.
        $this->get(route('admin.consultas.atencion', $consulta))->assertOk()->assertSee('Alergias (inactivo)');

        hcActualizar($consulta, [...hcFilasGuardadas($consulta), 'motivo_consulta' => 'Editado.'])->assertSessionHasNoErrors();

        // Pero no en una fila nueva.
        $datos = hcFilasGuardadas($consulta);
        $datos['diagnosticos'][] = ['id' => '', 'activo' => '1', 'codigo_cie10' => 'J06.9', 'tipo' => 'CONFIRMADO'];
        hcActualizar($consulta, $datos)->assertStatus(422)->assertJsonValidationErrors('diagnosticos.1.codigo_cie10');
    });
});

describe('sin JavaScript', function () {
    test('sin las marcas de las listas no se pierden ni se tocan los bloques ni los diagnósticos', function () {
        $consulta = hcConsultaEnCurso();

        // Sin JS el formulario no dibuja las filas: llega solo lo que no depende de Alpine.
        hcActualizar($consulta, ['motivo_consulta' => 'Sin JS.', 'examen' => ['temperatura' => '37']])->assertSessionHasNoErrors();

        expect($consulta->fresh()->motivo_consulta)->toBe('Sin JS.')
            ->and(BloqueAnamnesis::where('activo', true)->count())->toBe(2)
            ->and(Diagnostico::where('activo', true)->where('principal', true)->count())->toBe(1)
            ->and(ExamenFisico::sole()->temperatura)->toBe('37.0');
    });

    test('las filas se dibujan con la marca dentro de un template x-if (solo con JS)', function () {
        $html = $this->get(route('admin.consultas.atencion', hcEnCurso()))->getContent();

        expect($html)->toContain('<template x-if="true"><input type="hidden" name="con_anamnesis" value="1"></template>')
            ->toContain('<template x-if="true"><input type="hidden" name="con_diagnosticos" value="1"></template>');
    });
});

describe('concurrencia', function () {
    test('el segundo guardado desde una pestaña vieja se rechaza', function () {
        $consulta = hcConsultaEnCurso();
        $vieja = $consulta->fresh()->version(); // las dos pestañas se abren con esta versión

        // Pestaña 1 guarda (un segundo después).
        Carbon::setTestNow(now()->addSecond());
        hcActualizar($consulta, [...hcFilasGuardadas($consulta), 'motivo_consulta' => 'Pestaña 1.'], $vieja)->assertSessionHasNoErrors();

        // Pestaña 2, con la versión vieja.
        hcActualizar($consulta, [...hcFilasGuardadas($consulta), 'motivo_consulta' => 'Pestaña 2.'], $vieja)
            ->assertStatus(409)->assertJson(['message' => Autoguardado::VERSION_VIEJA]);

        expect($consulta->fresh()->motivo_consulta)->toBe('Pestaña 1.');
    });

    test('también si solo cambiaron las secciones (la consulta se marca como modificada igual)', function () {
        $consulta = hcConsultaEnCurso();
        $vieja = $consulta->fresh()->version();

        $datos = hcFilasGuardadas($consulta);
        $datos['anamnesis'][0]['contenido'] = 'Cambió solo esto.';
        Carbon::setTestNow(now()->addSecond());
        hcActualizar($consulta, $datos, $vieja)->assertSessionHasNoErrors();

        hcActualizar($consulta, hcFilasGuardadas($consulta), $vieja)->assertStatus(409)->assertJson(['message' => Autoguardado::VERSION_VIEJA]);
    });

    test('dos guardados en el mismo segundo no se confunden (la versión tiene microsegundos)', function () {
        $consulta = hcConsultaEnCurso();
        $vieja = $consulta->fresh()->version();

        Carbon::setTestNow(now()->addMicroseconds(5));
        hcActualizar($consulta, hcFilasGuardadas($consulta), $vieja)->assertSessionHasNoErrors();
        hcActualizar($consulta, hcFilasGuardadas($consulta), $vieja)->assertStatus(409)->assertJson(['message' => Autoguardado::VERSION_VIEJA]);
    });
});

describe('buscador de CIE-10', function () {
    test('busca por código o descripción, solo activos, mínimo 2 caracteres', function () {
        CatalogoCIE10::create(['codigo' => 'J06.8', 'descripcion' => 'Otras infecciones agudas', 'capitulo' => 'X', 'estado_id' => Estado::idDe(Estado::INACTIVO)]);

        $this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'J06']))->assertOk()->assertExactJson([['codigo' => 'J06.9', 'descripcion' => 'Rinofaringitis aguda']]);
        $this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'cefa']))->assertExactJson([['codigo' => 'R51', 'descripcion' => 'Cefalea']]);
        $this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'J']))->assertExactJson([]);
    });

    test('devuelve como máximo 15', function () {
        foreach (range(10, 40) as $n) {
            CatalogoCIE10::create(['codigo' => "K{$n}", 'descripcion' => "Digestivo {$n}", 'capitulo' => 'XI']);
        }

        expect($this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'Digestivo']))->json())->toHaveCount(15);
    });

    test('exige CREAR o EDITAR sobre la historia clínica, no permisos sobre el catálogo CIE-10', function () {
        $this->actingAs(User::factory()->conPermisos(['HISTORIA_CLINICA' => ['VER', 'EDITAR']])->create());
        $this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'J06']))->assertOk()->assertJsonCount(1);

        $this->actingAs(User::factory()->conPermisos(['CIE10' => ['VER', 'CREAR', 'EDITAR']])->create());
        $this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'J06']))->assertForbidden();
    });
});
