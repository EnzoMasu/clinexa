<?php

use App\Models\CatalogoCIE10;
use App\Models\CategoriaGasto;
use App\Models\Especialidad;
use App\Models\MedioPago;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Procedimiento;
use App\Models\Sucursal;
use App\Models\TipoDocumento;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create(['name' => 'Admin Pruebas']));
});

function ajax(string $url)
{
    return test()->withHeader('X-Requested-With', 'XMLHttpRequest')->get($url);
}

dataset('listados', ['usuarios', 'perfiles-acceso', 'personas', 'especialidades', 'sucursales',
    'tipos-documento', 'cie10', 'medios-pago', 'categorias-gasto', 'procedimientos']);

test('una request normal devuelve la página completa con el buscador en vivo', function (string $listado) {
    $html = $this->get(route("admin.{$listado}.index"))->assertOk()
        ->assertHeader('Vary', 'X-Requested-With')
        ->getContent();

    expect($html)->toContain('<html')
        ->toContain('Administración') // menú del layout
        ->toContain('x-data="listadoEnVivo"')
        ->toContain('<table')
        // Respaldo sin JavaScript: formulario GET al mismo listado, con botón de envío.
        ->toMatch('#<form method="GET" action="'.preg_quote(route("admin.{$listado}.index"), '#').'" role="search"#')
        ->toMatch('#<button type="submit"[^>]*>\s*Buscar\s*</button>#');
})->with('listados');

test('una request AJAX devuelve solo el fragmento de la tabla, sin layout', function (string $listado) {
    $html = ajax(route("admin.{$listado}.index"))->assertOk()
        ->assertHeader('Vary', 'X-Requested-With')
        ->getContent();

    expect(trim($html))->toStartWith('<div class="overflow-x-auto">')
        ->toContain('<table')
        ->not->toContain('<html')
        ->not->toContain('<head')
        ->not->toContain('<nav x-data') // barra de navegación
        ->not->toContain('listadoEnVivo') // el buscador no se repite dentro del fragmento
        ->not->toContain('role="search"');
})->with('listados');

test('la request AJAX también exige permiso VER', function () {
    $this->actingAs(User::factory()->conPermisos(['PERSONAS' => ['CREAR']])->create());

    ajax(route('admin.personas.index'))->assertForbidden();
});

test('los listados que ya buscaban siguen buscando los mismos campos, con y sin AJAX', function () {
    $ci = TipoDocumento::create(['codigo' => 'CI', 'nombre' => 'Cédula', 'aplica_a' => 'FISICA']);
    $base = ['tipo_persona' => 'FISICA', 'tipo_documento_id' => $ci->id, 'fecha_nacimiento' => '1990-01-01', 'email' => 'x@example.com', 'telefono' => '1', 'direccion' => 'X'];
    Persona::create([...$base, 'nro_documento' => '5234567', 'apellidos' => 'Duarte', 'nombres' => 'Carmen']);
    Persona::create([...$base, 'nro_documento' => '5876543', 'apellidos' => 'Ramírez', 'nombres' => 'Ana']);

    foreach ([fn ($url) => $this->get($url), fn ($url) => ajax($url)] as $pedir) {
        // Documento (por prefijo), apellido y nombre, como antes.
        $pedir(route('admin.personas.index', ['q' => '5234']))->assertSee('Duarte')->assertDontSee('Ramírez');
        $pedir(route('admin.personas.index', ['q' => 'ramír']))->assertSee('Ramírez')->assertDontSee('Duarte');
        $pedir(route('admin.personas.index', ['q' => 'carmen']))->assertSee('Duarte')->assertDontSee('Ramírez');
        // Usuarios por nombre o email; CIE-10 por código o descripción.
        $pedir(route('admin.usuarios.index', ['q' => 'admin pruebas']))->assertSee('Admin Pruebas');
    }

    CatalogoCIE10::create(['codigo' => 'O14.1', 'descripcion' => 'Preeclampsia severa', 'capitulo' => 'Embarazo']);
    CatalogoCIE10::create(['codigo' => 'N76.0', 'descripcion' => 'Vaginitis aguda', 'capitulo' => 'Genitourinario']);
    ajax(route('admin.cie10.index', ['q' => 'O14']))->assertSee('Preeclampsia')->assertDontSee('Vaginitis');
    ajax(route('admin.cie10.index', ['q' => 'vaginitis']))->assertSee('N76.0')->assertDontSee('O14.1');
});

test('los listados sin buscador ahora buscan por sus columnas visibles', function (string $listado, Closure $crear, string $q, string $esperado, string $noEsperado) {
    $crear();

    foreach ([fn ($url) => $this->get($url), fn ($url) => ajax($url)] as $pedir) {
        $pedir(route("admin.{$listado}.index", ['q' => $q]))->assertOk()->assertSee($esperado)->assertDontSee($noEsperado);
    }
})->with([
    'especialidades (descripción)' => ['especialidades', function () {
        Especialidad::create(['nombre' => 'Ginecología', 'descripcion' => 'Salud de la mujer']);
        Especialidad::create(['nombre' => 'Pediatría', 'descripcion' => 'Niños']);
    }, 'mujer', 'Ginecología', 'Pediatría'],
    'sucursales (teléfono)' => ['sucursales', function () {
        Sucursal::create(['nombre' => 'Plenitud Mujer', 'direccion' => 'Iturbe', 'telefono' => '0975282556']);
        Sucursal::create(['nombre' => 'Otra Sede', 'direccion' => 'Centro', 'telefono' => '021000']);
    }, '0975', 'Plenitud Mujer', 'Otra Sede'],
    'tipos de documento (código)' => ['tipos-documento', function () {
        TipoDocumento::create(['codigo' => 'RUC', 'nombre' => 'Registro Único', 'aplica_a' => 'AMBOS']);
        TipoDocumento::create(['codigo' => 'PAS', 'nombre' => 'Pasaporte', 'aplica_a' => 'FISICA']);
    }, 'ruc', 'Registro Único', 'Pasaporte'],
    'medios de pago' => ['medios-pago', function () {
        MedioPago::create(['nombre' => 'Efectivo']);
        MedioPago::create(['nombre' => 'Tarjeta de crédito']);
    }, 'tarjeta', 'Tarjeta de crédito', 'Efectivo'],
    'categorías de gasto' => ['categorias-gasto', function () {
        CategoriaGasto::create(['nombre' => 'Insumos médicos']);
        CategoriaGasto::create(['nombre' => 'Alquiler']);
    }, 'insumos', 'Insumos médicos', 'Alquiler'],
    'procedimientos (tipo)' => ['procedimientos', function () {
        Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
        Procedimiento::create(['codigo' => 'ECO-TV', 'nombre' => 'Ecografía Transvaginal', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    }, 'estudio', 'Ecografía Transvaginal', 'CONS-001'],
    'perfiles de acceso (descripción)' => ['perfiles-acceso', function () {
        PerfilAcceso::create(['nombre' => 'Recepción', 'descripcion' => 'Atención al público']);
        PerfilAcceso::create(['nombre' => 'Médicos', 'descripcion' => 'Consultorio']);
    }, 'público', 'Recepción', 'Médicos'],
]);

test('la paginación funciona dentro del fragmento y conserva la búsqueda', function () {
    foreach (range(10, 49) as $n) {
        CatalogoCIE10::create(['codigo' => "O{$n}.0", 'descripcion' => "Código obstétrico {$n}", 'capitulo' => 'Embarazo']);
    }
    CatalogoCIE10::create(['codigo' => 'N76.0', 'descripcion' => 'Vaginitis aguda', 'capitulo' => 'Genitourinario']);

    // 40 resultados para "O", 25 por página: la página 2 tiene los últimos 15.
    $pagina2 = ajax(route('admin.cie10.index', ['q' => 'O', 'page' => 2]))->assertOk()->getContent();

    expect($pagina2)->not->toContain('<html')
        ->toContain('O49.0')->not->toContain('O10.0')->not->toContain('N76.0')
        // Los links de paginación del fragmento mantienen la búsqueda, para seguir paginando vía fetch.
        ->toMatch('#<nav role="navigation"#')
        ->toContain('q=O&amp;page=1');
});

test('sin resultados muestra un mensaje con lo buscado', function () {
    ajax(route('admin.especialidades.index', ['q' => 'inexistente']))
        ->assertOk()
        ->assertSee('No hay resultados para «inexistente».');
});
