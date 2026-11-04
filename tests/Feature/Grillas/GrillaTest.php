<?php

use App\Models\Departamento;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\User;
use App\Support\Grilla\Grilla;
use Illuminate\Http\Request;

/*
 * Orden y filtros por columna de las grillas (docs/grillas.md), con el listado de países como ejemplo, y
 * la clase Grilla directo sobre personas (fechas). Datos inventados.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());

    foreach ([['XA', 'Arcadia'], ['XB', 'Borduria'], ['XC', 'Cascadia'], ['XD', 'Dinotopia']] as [$codigo, $nombre]) {
        Pais::create(['codigo' => $codigo, 'nombre' => $nombre]);
    }
    Pais::where('codigo', 'XD')->sole()->desactivar();
    $borduria = Pais::where('codigo', 'XB')->sole();
    Departamento::create(['nombre' => 'Szohod', 'pais_id' => $borduria->id]);
    Departamento::create(['nombre' => 'Klow', 'pais_id' => $borduria->id]);
});

function grillaPaises(array $parametros = [], array $encabezados = [])
{
    return test()->withHeaders($encabezados)->get(route('admin.paises.index', $parametros))->assertOk();
}

/** Nombres de los países inventados, en el orden en que aparecen en la tabla. */
function grillaNombres($respuesta): array
{
    preg_match_all('#<td class="px-6 py-4">(Arcadia|Borduria|Cascadia|Dinotopia)</td>#', $respuesta->getContent(), $m);

    return $m[1];
}

/** Parámetros de cada enlace a la grilla de países que hay en la página (el orden de la URL no importa). */
function grillaEnlaces(string $html): array
{
    preg_match_all('#href="[^"]*/admin/paises\?([^"]*)"#', $html, $m);

    return array_map(function (string $consulta) {
        parse_str(html_entity_decode($consulta), $parametros);

        return $parametros;
    }, $m[1]);
}

test('sin orden pedido se usa el orden habitual del listado', function () {
    expect(grillaNombres(grillaPaises()))->toBe(['Arcadia', 'Borduria', 'Cascadia', 'Dinotopia']);
});

test('cada columna ordena asc y desc en la consulta', function () {
    expect(grillaNombres(grillaPaises(['orden' => 'nombre', 'dir' => 'desc'])))->toBe(['Dinotopia', 'Cascadia', 'Borduria', 'Arcadia'])
        ->and(grillaNombres(grillaPaises(['orden' => 'codigo', 'dir' => 'asc'])))->toBe(['Arcadia', 'Borduria', 'Cascadia', 'Dinotopia'])
        // Por la cantidad de departamentos (alias de withCount): Borduria (2) al final en asc, primero en desc.
        ->and(grillaNombres(grillaPaises(['orden' => 'departamentos', 'dir' => 'desc']))[0])->toBe('Borduria')
        // Por estado: el nombre del estado (Activo antes que Inactivo).
        ->and(grillaNombres(grillaPaises(['orden' => 'estado', 'dir' => 'desc']))[0])->toBe('Dinotopia');
});

test('el título indica el orden actual y el enlace alterna asc/desc volviendo a la página 1', function () {
    $html = grillaPaises(['orden' => 'nombre', 'dir' => 'asc', 'page' => 2])->getContent();

    expect($html)->toContain('aria-sort="ascending"')
        ->and(grillaEnlaces($html))->toContain(['orden' => 'nombre', 'dir' => 'desc'], ['orden' => 'codigo', 'dir' => 'asc'])
        ->and($html)
        // Los campos ocultos llevan el orden al filtrar.
        ->toContain('name="orden" form="'.Grilla::FORMULARIO.'" value="nombre"');
});

test('lista blanca: una columna, dirección o filtro no declarados se ignoran', function () {
    // Columna inexistente o con SQL: orden habitual, sin error.
    expect(grillaNombres(grillaPaises(['orden' => 'id; DROP TABLE paises', 'dir' => 'desc'])))->toBe(['Arcadia', 'Borduria', 'Cascadia', 'Dinotopia'])
        ->and(grillaNombres(grillaPaises(['orden' => 'estado_id'])))->toBe(['Arcadia', 'Borduria', 'Cascadia', 'Dinotopia'])
        // Dirección inválida: asc.
        ->and(grillaNombres(grillaPaises(['orden' => 'nombre', 'dir' => 'cualquiera'])))->toBe(['Arcadia', 'Borduria', 'Cascadia', 'Dinotopia'])
        // Filtro sobre una columna no declarada, o con un valor que no es texto: se ignora.
        ->and(grillaNombres(grillaPaises(['f' => ['estado_id' => '999', 'nombre' => ['x' => 'y']]])))->toHaveCount(4);
    expect(Pais::where('codigo', 'XA')->exists())->toBeTrue();
});

test('filtro de texto, de número y desplegable, combinables', function () {
    expect(grillaNombres(grillaPaises(['f' => ['nombre' => 'cadi']])))->toBe(['Arcadia', 'Cascadia'])
        ->and(grillaNombres(grillaPaises(['f' => ['nombre' => 'CADI', 'codigo' => 'xc']])))->toBe(['Cascadia'])
        ->and(grillaNombres(grillaPaises(['f' => ['departamentos' => '2']])))->toBe(['Borduria'])
        ->and(grillaNombres(grillaPaises(['f' => ['departamentos' => 'dos']])))->toHaveCount(4) // no es un número: no filtra
        ->and(grillaNombres(grillaPaises(['f' => ['estado' => 'INACTIVO']])))->toBe(['Dinotopia'])
        ->and(grillaNombres(grillaPaises(['f' => ['estado' => 'BLOQUEADO']])))->toHaveCount(4); // no es una opción: no filtra

    grillaPaises(['f' => ['nombre' => 'zzz']])->assertSee('No hay resultados con esos filtros.');
});

test('el filtro y el orden se mantienen en la paginación', function () {
    foreach (range(1, 25) as $i) {
        Pais::create(['codigo' => 'Z'.chr(64 + $i), 'nombre' => sprintf('Zeta %02d', $i)]);
    }

    $pagina1 = grillaPaises(['f' => ['nombre' => 'zeta'], 'orden' => 'nombre', 'dir' => 'desc']);
    $pagina1->assertSeeInOrder(['Zeta 25', 'Zeta 06'])->assertDontSee('Zeta 05')->assertDontSee('Arcadia');
    expect(grillaEnlaces($pagina1->getContent()))->toContain(['f' => ['nombre' => 'zeta'], 'orden' => 'nombre', 'dir' => 'desc', 'page' => '2']);

    grillaPaises(['f' => ['nombre' => 'zeta'], 'orden' => 'nombre', 'dir' => 'desc', 'page' => 2])
        ->assertSeeInOrder(['Zeta 05', 'Zeta 01'])->assertDontSee('Zeta 06')->assertDontSee('Arcadia');
});

test('la búsqueda en vivo devuelve la tabla con los títulos que ordenan y la fila de filtros', function () {
    $html = test()->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('admin.paises.index', ['f' => ['nombre' => 'arc']]))
        ->assertOk()->getContent();

    expect($html)->not->toContain('<html')
        ->toContain('data-grilla-orden')
        ->toContain('name="f[nombre]" form="'.Grilla::FORMULARIO.'" value="arc"')
        ->toContain('<option value="INACTIVO"')
        ->toContain('Limpiar');
});

describe('al volver de un registro', function () {
    test('se recuperan orden, filtros y página; desde el menú o desde otra sección arranca limpia', function () {
        grillaPaises(['f' => ['nombre' => 'cadi'], 'orden' => 'nombre', 'dir' => 'desc']);
        $arcadia = Pais::where('codigo', 'XA')->sole();

        // "Cancelar" o la redirección después de guardar: viene del formulario del registro.
        $vuelta = grillaPaises([], ['Referer' => route('admin.paises.edit', $arcadia)]);
        expect(grillaNombres($vuelta))->toBe(['Cascadia', 'Arcadia'])
            ->and($vuelta->getContent())->toContain('history.replaceState');

        // Menú (sin Referer) u otra sección: limpia, y eso pasa a ser lo recordado.
        expect(grillaNombres(grillaPaises([], ['Referer' => route('admin.departamentos.index')])))->toHaveCount(4)
            ->and(grillaNombres(grillaPaises()))->toHaveCount(4)
            ->and(grillaNombres(grillaPaises([], ['Referer' => route('admin.paises.edit', $arcadia)])))->toHaveCount(4);
    });

    test('la redirección después de guardar vuelve a la grilla como estaba', function () {
        grillaPaises(['f' => ['estado' => 'INACTIVO']]);
        $dinotopia = Pais::where('codigo', 'XD')->sole();

        $this->from(route('admin.paises.edit', $dinotopia))
            ->put(route('admin.paises.update', $dinotopia), ['codigo' => 'XD', 'nombre' => 'Dinotopia', 'estado_id' => $dinotopia->estado_id])
            ->assertRedirect(route('admin.paises.index'));

        expect(grillaNombres(grillaPaises([], ['Referer' => route('admin.paises.edit', $dinotopia)])))->toBe(['Dinotopia']);
    });

    test('lo recordado es de cada grilla', function () {
        grillaPaises(['f' => ['nombre' => 'cadi']]);
        $this->get(route('admin.departamentos.index'))->assertOk();

        expect(session('grillas'))->toHaveKey('admin_paises_index')
            ->and(session('grillas.admin_paises_index'))->toBe(['f' => ['nombre' => 'cadi']]);
    });
});

describe('clase Grilla', function () {
    function grillaPersonas(array $parametros): array
    {
        $grilla = Grilla::de(Request::create('/', 'GET', $parametros))
            ->texto('apellidos', 'Apellidos', 'apellidos')
            ->fecha('nacimiento', 'Nacimiento', 'fecha_nacimiento');

        return $grilla->aplicar(Persona::query()->whereIn('nombres', ['Grillatest']), fn ($query) => $query->orderBy('apellidos'))
            ->pluck('apellidos')->all();
    }

    beforeEach(function () {
        foreach ([['Alfa', '1980-01-15'], ['Beta', '1990-06-30'], ['Gama', '2000-12-31']] as [$apellidos, $fecha]) {
            Persona::factory()->create(['apellidos' => $apellidos, 'nombres' => 'Grillatest', 'fecha_nacimiento' => $fecha]);
        }
    });

    test('el rango de fechas incluye ambos extremos y acepta uno solo', function () {
        expect(grillaPersonas(['f' => ['nacimiento' => ['desde' => '15/01/1980', 'hasta' => '30/06/1990']]]))->toBe(['Alfa', 'Beta'])
            ->and(grillaPersonas(['f' => ['nacimiento' => ['desde' => '01/07/1990']]]))->toBe(['Gama'])
            ->and(grillaPersonas(['f' => ['nacimiento' => ['hasta' => '14/01/1980']]]))->toBe([]);
    });

    test('una fecha incompleta o inexistente no filtra', function () {
        expect(grillaPersonas(['f' => ['nacimiento' => ['desde' => '31/02/1990']]]))->toHaveCount(3)
            ->and(grillaPersonas(['f' => ['nacimiento' => ['desde' => '01/07/19']]]))->toHaveCount(3)
            ->and(grillaPersonas(['f' => ['nacimiento' => 'texto']]))->toHaveCount(3);
    });

    test('ordena por la columna pedida y desempata por la clave', function () {
        expect(grillaPersonas(['orden' => 'nacimiento', 'dir' => 'desc']))->toBe(['Gama', 'Beta', 'Alfa'])
            ->and(grillaPersonas(['orden' => 'apellidos', 'dir' => 'desc']))->toBe(['Gama', 'Beta', 'Alfa']);
    });
});

describe('desplegable diferido (Auditoría: usuario)', function () {
    test('la lista de usuarios viene solo en la página entera; en vivo el filtro igual funciona y conserva "Sin usuario"', function () {
        $otro = User::factory()->conPersona(['apellidos' => 'Grillauno', 'nombres' => 'Liz'])->create();

        $pagina = $this->get(route('admin.auditoria.index'))->assertOk()->getContent();
        expect($pagina)->toContain('data-grilla-diferida')->toContain('<option value="'.$otro->id.'"')->toContain('(Sin usuario: correo inexistente)');

        $vivo = $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('admin.auditoria.index', ['f' => ['usuario' => (string) $otro->id]]))->assertOk();
        expect($vivo->getContent())->toContain('data-grilla-diferida')->toContain('(Sin usuario: correo inexistente)')
            ->not->toContain('<option value="'.$otro->id.'"')
            ->and($vivo->viewData('logs')->every(fn ($log) => $log->usuario_id === $otro->id))->toBeTrue();

        // Un valor que no es un usuario no filtra ni falla.
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('admin.auditoria.index', ['f' => ['usuario' => 'x; DROP']]))->assertOk();
    });
});

test('la grilla solo scrollea en horizontal: contenedor relativo, barra de arriba y sin sticky ni alto propio', function () {
    $html = grillaPaises()->getContent();

    expect($html)->toMatch('#data-grilla-scroll class="[^"]*\brelative\b[^"]*\boverflow-x-auto\b#')
        ->and($html)->toContain('data-grilla-barra')
        ->and($html)->not->toContain('sticky top-0')
        ->and($html)->not->toContain('overflow-y-auto')
        ->and($html)->not->toContain('max-h-[70vh]');
});
