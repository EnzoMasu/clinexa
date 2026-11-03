<?php

use App\Models\User;
use App\Support\Grilla\Columna;
use App\Support\Grilla\Grilla;

/*
 * Todas las grillas (docs/grillas.md): cada columna ordenable ordena asc y desc, y cada filtro se aplica,
 * sin errores de SQL. La lista sale de las rutas: cualquier listado con grilla nuevo entra solo.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->conPersona(['apellidos' => 'Pruebas', 'nombres' => 'Grillas'])->create());
});

/** Rutas GET de /admin que dibujan una grilla. */
function grillasTodasRutas(): array
{
    return [
        'acuerdos-reparto.index', 'auditoria.index', 'cajas.index', 'cargos-salud.index', 'categorias-gasto.index',
        'categorias-proveedor.index', 'cie10.index', 'ciudades.index', 'cobros.index', 'consultorios.index',
        'convenios.index', 'convenios.tarifas', 'departamentos.index', 'dietas.index', 'disponibilidades.index',
        'equipos.index', 'especialidades.index', 'estudios.index', 'facturas.index', 'facturas.timbrados',
        'facturas.tasas-iva', 'historias-clinicas.index', 'liquidaciones.index', 'medios-pago.index',
        'facturas.motivos', 'origenes-turno.index', 'pacientes.index', 'paises.index', 'perfiles-acceso.index',
        'personal-salud.index', 'personas.index', 'prestaciones.index', 'procedimientos.index', 'profesionales.index',
        'proveedores.index', 'responsables-pago.index', 'sucursales.index', 'tarifas.index', 'tipos-bloque-anamnesis.index',
        'tipos-documento.index', 'tipos-equipo.index', 'tipos-indicacion.index', 'tipos-red-social.index', 'turnos.index',
        'usuarios.index',
    ];
}

dataset('grillas', fn () => array_map(fn ($ruta) => [$ruta], grillasTodasRutas()));

/** Un valor de prueba para el filtro de la columna. */
function grillasTodasValor(Columna $columna): string|array
{
    return match ($columna->tipo) {
        Columna::FECHA => ['desde' => '01/01/2020', 'hasta' => '31/12/2030'],
        Columna::NUMERO => '1',
        Columna::OPCIONES => (string) array_key_first($columna->opciones),
        default => 'zz',
    };
}

test('la grilla ordena por cada columna y filtra por cada filtro', function (string $ruta) {
    $grilla = $this->get(route("admin.{$ruta}"))->assertOk()->viewData('grilla');
    expect($grilla)->toBeInstanceOf(Grilla::class);

    $filtros = [];
    foreach ($grilla->columnas() as $columna) {
        if ($columna->ordenable()) {
            foreach (['asc', 'desc'] as $dir) {
                $this->get(route("admin.{$ruta}", ['orden' => $columna->clave, 'dir' => $dir]))->assertOk();
            }
        }
        if ($columna->filtrable()) {
            $filtros[$columna->clave] = grillasTodasValor($columna);
            $this->get(route("admin.{$ruta}", ['f' => [$columna->clave => $filtros[$columna->clave]]]))->assertOk()
                ->assertSee('name="f['.$columna->clave.']', false);
        }
    }

    // Todos los filtros juntos, en la búsqueda en vivo.
    $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route("admin.{$ruta}", ['f' => $filtros]))->assertOk();
})->with('grillas');
