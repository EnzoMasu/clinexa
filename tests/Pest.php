<?php

use App\Models\Estado;
use App\Support\Fecha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * id del estado con ese código (tabla estados, sembrada por migración), p. ej. estadoId('INACTIVO').
 */
function estadoId(string $codigo): int
{
    return Estado::idDe($codigo);
}

/**
 * Datos de prueba -> como los manda el formulario: las fechas ISO (fecha_nacimiento, fecha_desde)
 * pasan a dd/mm/aaaa, que es el formato que se carga en pantalla.
 */
function enFormulario(array $datos): array
{
    foreach ($datos as $clave => $valor) {
        if (is_array($valor)) {
            $datos[$clave] = enFormulario($valor);
        } elseif (in_array($clave, ['fecha_nacimiento', 'fecha_desde'], true) && is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            $datos[$clave] = Fecha::mostrar($valor);
        }
    }

    return $datos;
}
