<?php

/*
 * EvaluadorSignos: cada nivel (alto y bajo), los bordes de cada banda, la presión en sus dos parámetros, los
 * negativos y los parámetros PENDIENTES (no marcan; lo imposible rige igual). Rangos inventados para la prueba:
 * los reales los define y aprueba un médico.
 */

use App\Models\Estado;
use App\Models\ParametroClinico;
use App\Support\Atencion\EvaluadorSignos;

/** Fija las bandas de un parámetro sembrado y lo deja aprobado (o pendiente). */
function evaluadorSignosParametro(string $codigo, ?array $muy, ?array $normal, bool $aprobado = true, ?array $imposible = null): void
{
    $parametro = ParametroClinico::where('codigo', $codigo)->sole();
    $parametro->forceFill([
        ...($imposible ? ['imposible_min' => $imposible[0], 'imposible_max' => $imposible[1]] : []),
        'muy_alterado_min' => $muy[0] ?? null, 'muy_alterado_max' => $muy[1] ?? null,
        'normal_min' => $normal[0] ?? null, 'normal_max' => $normal[1] ?? null,
        'estado_id' => Estado::idDe($aprobado ? Estado::APROBADO : Estado::PENDIENTE), 'aprobado_en' => $aprobado ? now() : null,
    ])->save();
}

function evaluadorSignosNivel(string $campo, mixed $valor): array
{
    return (new EvaluadorSignos)->evaluar([$campo => $valor])[$campo];
}

beforeEach(function () {
    // Temperatura: imposible 30-45 (sembrado), muy alterado 35-40, normal 36-37,5.
    evaluadorSignosParametro('TEMPERATURA', [35, 40], [36, 37.5]);
});

test('el sembrado trae solo los límites imposibles, sin normal ni muy alterado, y todo PENDIENTE', function () {
    // Lo sembrado por la migración (TEMPERATURA la cambió el beforeEach: se mira aparte, solo su límite imposible).
    $sembrados = ParametroClinico::orderBy('id')->get();

    expect($sembrados->pluck('codigo')->all())->toBe(['TEMPERATURA', 'PRESION_SISTOLICA', 'PRESION_DIASTOLICA', 'FRECUENCIA_CARDIACA',
        'FRECUENCIA_RESPIRATORIA', 'SATURACION_OXIGENO', 'PESO', 'TALLA']);
    foreach ($sembrados->where('codigo', '!=', 'TEMPERATURA') as $parametro) {
        expect($parametro->estado->codigo)->toBe(Estado::PENDIENTE, $parametro->codigo)
            ->and([$parametro->muy_alterado_min, $parametro->muy_alterado_max, $parametro->normal_min, $parametro->normal_max])->toBe([null, null, null, null]);
    }
    expect($sembrados->mapWithKeys(fn ($p) => [$p->codigo => [$p->imposible_min, $p->imposible_max]])->all())->toBe([
        'TEMPERATURA' => [30.0, 45.0], 'PRESION_SISTOLICA' => [null, null], 'PRESION_DIASTOLICA' => [null, null],
        'FRECUENCIA_CARDIACA' => [20.0, 300.0], 'FRECUENCIA_RESPIRATORIA' => [5.0, 80.0], 'SATURACION_OXIGENO' => [0.0, 100.0],
        'PESO' => [0.0, 500.0], 'TALLA' => [20.0, 250.0],
    ]);
});

test('cada nivel, alto y bajo, con los bordes de cada banda incluidos', function (string $valor, string $nivel, ?string $direccion) {
    expect(evaluadorSignosNivel('temperatura', $valor))->nivel->toBe($nivel)->direccion->toBe($direccion);
})->with([
    'normal, borde de abajo' => ['36', 'NORMAL', null],
    'normal' => ['36.8', 'NORMAL', null],
    'normal, borde de arriba' => ['37.5', 'NORMAL', null],
    'fuera de lo normal, alto' => ['37.6', 'FUERA_DE_RANGO', 'alto'],
    'fuera de lo normal, bajo' => ['35.9', 'FUERA_DE_RANGO', 'bajo'],
    'fuera de lo normal, borde de muy alterado' => ['40', 'FUERA_DE_RANGO', 'alto'],
    'muy alterado, alto' => ['40.1', 'MUY_ALTERADO', 'alto'],
    'muy alterado, bajo' => ['34.9', 'MUY_ALTERADO', 'bajo'],
    'muy alterado, borde de imposible' => ['45', 'MUY_ALTERADO', 'alto'],
    'imposible, alto' => ['45.1', 'IMPOSIBLE', 'alto'],
    'imposible, bajo' => ['29.9', 'IMPOSIBLE', 'bajo'],
]);

test('el mensaje de un valor imposible está en usted y nombra el signo', function () {
    expect(evaluadorSignosNivel('temperatura', '46')['mensaje'])->toBe('El valor de TEMPERATURA no es posible.')
        ->and(evaluadorSignosNivel('frecuencia_cardiaca', 500)['mensaje'])->toBe('El valor de FRECUENCIA CARDÍACA no es posible.');
});

test('todo valor negativo es imposible, aunque el parámetro no tenga límite', function () {
    expect(evaluadorSignosNivel('saturacion_oxigeno', -1)['nivel'])->toBe('IMPOSIBLE')
        ->and(evaluadorSignosNivel('frecuencia_respiratoria', -5)['nivel'])->toBe('IMPOSIBLE');
});

test('un parámetro PENDIENTE no marca (sin alerta) pero lo imposible rige igual', function () {
    evaluadorSignosParametro('TEMPERATURA', [35, 40], [36, 37.5], aprobado: false);

    expect(evaluadorSignosNivel('temperatura', '41'))->nivel->toBe('NORMAL')->aprobado->toBeFalse()
        ->and(evaluadorSignosNivel('temperatura', '46')['nivel'])->toBe('IMPOSIBLE');
});

test('un parámetro aprobado sin bandas normal ni muy alterado no marca', function () {
    evaluadorSignosParametro('FRECUENCIA_CARDIACA', null, null);

    expect(evaluadorSignosNivel('frecuencia_cardiaca', 160))->nivel->toBe('NORMAL')->aprobado->toBeTrue();
});

describe('presión arterial', function () {
    beforeEach(function () {
        evaluadorSignosParametro('PRESION_SISTOLICA', [80, 180], [90, 130], imposible: [50, 260]);
        evaluadorSignosParametro('PRESION_DIASTOLICA', [40, 110], [60, 85], imposible: [20, 160]);
    });

    test('se evalúa en sus dos parámetros y queda el peor nivel', function (string $presion, string $nivel, ?string $direccion) {
        expect(evaluadorSignosNivel('presion_arterial', $presion))->nivel->toBe($nivel)->direccion->toBe($direccion);
    })->with([
        'normal' => ['120/80', 'NORMAL', null],
        'sistólica fuera de lo normal' => ['140/80', 'FUERA_DE_RANGO', 'alto'],
        'diastólica muy alterada (el peor)' => ['140/115', 'MUY_ALTERADO', 'alto'],
        'diastólica baja' => ['100/55', 'FUERA_DE_RANGO', 'bajo'],
        'sistólica imposible' => ['270/90', 'IMPOSIBLE', 'alto'],
    ]);

    test('regla nueva: la sistólica tiene que ser mayor que la diastólica', function (string $presion) {
        expect(evaluadorSignosNivel('presion_arterial', $presion))->nivel->toBe('IMPOSIBLE')
            ->mensaje->toContain('la sistólica tiene que ser mayor que la diastólica');
    })->with(['80/80', '70/90']);

    test('sin parámetros aprobados de presión, solo rigen el formato y la regla de sistólica y diastólica', function () {
        evaluadorSignosParametro('PRESION_SISTOLICA', null, null, aprobado: false, imposible: [null, null]);
        evaluadorSignosParametro('PRESION_DIASTOLICA', null, null, aprobado: false, imposible: [null, null]);

        expect(evaluadorSignosNivel('presion_arterial', '230/140')['nivel'])->toBe('NORMAL')
            ->and(evaluadorSignosNivel('presion_arterial', '90/120')['nivel'])->toBe('IMPOSIBLE');
    });
});

test('solo evalúa los signos cargados (los vacíos no aparecen)', function () {
    expect(array_keys((new EvaluadorSignos)->evaluar(['temperatura' => '38', 'peso' => null, 'talla' => ''])))->toBe(['temperatura']);
});
