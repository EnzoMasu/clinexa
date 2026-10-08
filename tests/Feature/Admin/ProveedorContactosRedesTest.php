<?php

use App\Enums\AccionAuditoria;
use App\Models\CategoriaProveedor;
use App\Models\ContactoProveedor;
use App\Models\LogAuditoria;
use App\Models\OrigenTurno;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\RedSocialProveedor;
use App\Models\TipoRedSocial;
use App\Models\User;
use Database\Seeders\DatosRealesClinicaSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
    $this->instagram = TipoRedSocial::create(['nombre' => 'Instagram']);
    $this->whatsapp = TipoRedSocial::create(['nombre' => 'WhatsApp']);
    $this->proveedor = Proveedor::create(['persona_id' => Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Laboratorio Central S.A.', 'apellidos' => null, 'nombres' => null])->id]);
});

/** Guarda el proveedor con lo que se indique; por defecto con las marcas de las listas (como el formulario con JavaScript). */
function guardarProveedor(array $datos)
{
    return test()->put(route('admin.proveedores.update', test()->proveedor), [
        'estado_id' => estadoId('ACTIVO'), 'con_contactos' => '1', 'con_redes' => '1', ...$datos,
    ]);
}

function contacto(array $cambios = []): array
{
    return ['nombre' => 'Liz', 'apellido' => 'Ruiz', 'telefono' => '0981 123 456', 'correo' => 'liz@example.com', ...$cambios];
}

describe('contactos', function () {
    test('se cargan varios en una sola vez, activos', function () {
        guardarProveedor(['contactos' => [contacto(), contacto(['nombre' => 'Ana', 'apellido' => 'Duarte', 'telefono' => null, 'correo' => 'ana@example.com'])]])
            ->assertSessionHasNoErrors();

        expect($this->proveedor->contactos()->orderBy('id')->get()->map(fn ($c) => [$c->apellido, $c->telefono, $c->correo, $c->activo])->all())
            ->toBe([['Ruiz', '0981 123 456', 'liz@example.com', true], ['Duarte', null, 'ana@example.com', true]]);
    });

    test('validaciones de cada contacto', function (array $fila, string $campo, ?string $mensaje) {
        $respuesta = guardarProveedor(['contactos' => [contacto($fila)]]);

        $mensaje ? $respuesta->assertSessionHasErrors(["contactos.0.{$campo}" => $mensaje]) : $respuesta->assertSessionHasErrors("contactos.0.{$campo}");
        expect(ContactoProveedor::count())->toBe(0);
    })->with([
        'sin nombre' => [['nombre' => ''], 'nombre', null],
        'sin apellido' => [['apellido' => '  '], 'apellido', null],
        'sin teléfono ni correo' => [['telefono' => '', 'correo' => ''], 'telefono', 'Indique al menos un teléfono o un correo electrónico para el contacto.'],
        'correo inválido' => [['correo' => 'no-es-un-correo'], 'correo', null],
        'teléfono con letras' => [['telefono' => '0981 abc'], 'telefono', 'El teléfono del contacto solo puede tener números, espacios y los signos + - ( ).'],
        'teléfono sin dígitos' => [['telefono' => '+ ( )'], 'telefono', null],
    ]);

    test('acepta teléfonos con +, espacios, guiones y paréntesis, y solo uno de los dos medios', function () {
        guardarProveedor(['contactos' => [contacto(['telefono' => '+595 (981) 123-456', 'correo' => null]), contacto(['nombre' => 'Ana', 'telefono' => null])]])
            ->assertSessionHasNoErrors();

        expect(ContactoProveedor::count())->toBe(2);
    });

    test('se deshabilita y se vuelve a habilitar; nunca se borra', function () {
        $guardado = $this->proveedor->contactos()->create(contacto());

        guardarProveedor(['contactos' => [[...contacto(), 'id' => $guardado->id, 'activo' => '0']]])->assertSessionHasNoErrors();
        expect($guardado->fresh()->activo)->toBeFalse();

        guardarProveedor(['contactos' => [[...contacto(), 'id' => $guardado->id, 'activo' => '1']]])->assertSessionHasNoErrors();
        expect($guardado->fresh()->activo)->toBeTrue();

        // Un envío que no lo trae no lo borra.
        guardarProveedor(['contactos' => []])->assertSessionHasNoErrors();
        expect(ContactoProveedor::whereKey($guardado->id)->exists())->toBeTrue();
    });

    test('sin JavaScript (sin la marca de la lista) no se pierden contactos ni redes', function () {
        $contacto = $this->proveedor->contactos()->create(contacto());
        $this->proveedor->redesSociales()->create(['tipo_red_social_id' => $this->instagram->id, 'enlace' => '@lab']);

        $this->put(route('admin.proveedores.update', $this->proveedor), ['estado_id' => estadoId('ACTIVO')])->assertSessionHasNoErrors();

        expect($contacto->fresh()->activo)->toBeTrue()->and($this->proveedor->redesSociales()->count())->toBe(1);
    });

    test('no se puede tocar un contacto de otro proveedor', function () {
        $otro = Proveedor::create(['persona_id' => Persona::factory()->create()->id]);
        $ajeno = $otro->contactos()->create(contacto());

        guardarProveedor(['contactos' => [[...contacto(['nombre' => 'Cambiado']), 'id' => $ajeno->id]]])->assertSessionHasErrors('contactos.0.id');
        expect($ajeno->fresh()->nombre)->toBe('Liz');
    });

    test('como mucho 20 contactos activos; los deshabilitados no cuentan', function () {
        $activos = collect(range(1, 21))->map(fn ($i) => contacto(['nombre' => "C{$i}"]))->all();
        guardarProveedor(['contactos' => $activos])->assertSessionHasErrors('contactos');
        expect(ContactoProveedor::count())->toBe(0);

        $conDeshabilitado = [...array_slice($activos, 0, 20), [...contacto(['nombre' => 'Viejo']), 'activo' => '0']];
        guardarProveedor(['contactos' => $conDeshabilitado])->assertSessionHasNoErrors();
        expect(ContactoProveedor::where('activo', true)->count())->toBe(20);
    });
});

describe('sitio web', function () {
    test('sin esquema se le antepone https://', function () {
        guardarProveedor(['sitio_web' => '  laboratoriocentral.com.py  '])->assertSessionHasNoErrors();

        expect($this->proveedor->fresh()->sitio_web)->toBe('https://laboratoriocentral.com.py');

        guardarProveedor(['sitio_web' => 'http://lab.com/catalogo?x=1'])->assertSessionHasNoErrors();
        expect($this->proveedor->fresh()->sitio_web)->toBe('http://lab.com/catalogo?x=1');
    });

    test('rechaza esquemas que no sean http/https y dominios inválidos', function (string $valor) {
        guardarProveedor(['sitio_web' => $valor])->assertSessionHasErrors('sitio_web');

        expect($this->proveedor->fresh()->sitio_web)->toBeNull();
    })->with([
        'javascript' => 'javascript:alert(1)',
        'javascript en mayúsculas' => 'JaVaScRiPt:alert(1)',
        'javascript con espacios y tabulador' => " java\tscript:alert(1)",
        'data' => 'data:text/html,<script>alert(1)</script>',
        'vbscript' => 'vbscript:msgbox(1)',
        'ftp' => 'ftp://lab.com',
        'sin dominio' => 'localhost',
        'sin punto' => 'https://laboratorio',
        'con espacios' => 'lab central.com',
    ]);

    test('vacío queda sin sitio web', function () {
        $this->proveedor->update(['sitio_web' => 'https://lab.com']);

        guardarProveedor(['sitio_web' => ''])->assertSessionHasNoErrors();

        expect($this->proveedor->fresh()->sitio_web)->toBeNull();
    });
});

describe('redes sociales', function () {
    test('se cargan y se quitan', function () {
        guardarProveedor(['redes' => [
            ['tipo_red_social_id' => $this->instagram->id, 'enlace' => '@laboratorio'],
            ['tipo_red_social_id' => $this->whatsapp->id, 'enlace' => '+595 981 123456'],
        ]])->assertSessionHasNoErrors();
        $instagram = RedSocialProveedor::where('tipo_red_social_id', $this->instagram->id)->sole();

        // Quitar WhatsApp: se envía solo la de Instagram.
        guardarProveedor(['redes' => [['id' => $instagram->id, 'tipo_red_social_id' => $this->instagram->id, 'enlace' => 'https://instagram.com/laboratorio']]])
            ->assertSessionHasNoErrors();

        expect(RedSocialProveedor::all()->map(fn ($r) => [$r->id, $r->enlace])->all())
            ->toBe([[$instagram->id, 'https://instagram.com/laboratorio']]);
    });

    test('un tipo inactivo no se ofrece para una red nueva, pero se conserva en la red que ya lo tenía', function () {
        $red = $this->proveedor->redesSociales()->create(['tipo_red_social_id' => $this->whatsapp->id, 'enlace' => '+595 981 123456']);
        $this->whatsapp->desactivar();

        $html = $this->get(route('admin.proveedores.edit', $this->proveedor))->assertOk()->getContent();
        expect($html)->toContain('WhatsApp (inactivo)');
        $this->get(route('admin.proveedores.create'))->assertOk()
            ->assertViewHas('tiposRedActivos', fn ($tipos) => $tipos->values()->all() === ['Instagram']);

        // La red que ya lo tenía se guarda igual.
        guardarProveedor(['redes' => [['id' => $red->id, 'tipo_red_social_id' => $this->whatsapp->id, 'enlace' => '+595 981 123456']]])
            ->assertSessionHasNoErrors();
        // Una red nueva con ese tipo, no.
        guardarProveedor(['redes' => [
            ['id' => $red->id, 'tipo_red_social_id' => $this->whatsapp->id, 'enlace' => '+595 981 123456'],
            ['tipo_red_social_id' => $this->whatsapp->id, 'enlace' => '+595 981 654321'],
        ]])->assertSessionHasErrors('redes.1.tipo_red_social_id');
    });

    test('rechaza enlaces con esquemas no permitidos, repetidos, sin tipo y más de 10', function () {
        foreach (['javascript:alert(1)', 'DATA:text/html,x', "vb\nscript:x", 'ftp://lab.com', 'https://'] as $malo) {
            guardarProveedor(['redes' => [['tipo_red_social_id' => $this->instagram->id, 'enlace' => $malo]]])->assertSessionHasErrors('redes.0.enlace');
        }
        guardarProveedor(['redes' => [['tipo_red_social_id' => '', 'enlace' => '@lab']]])->assertSessionHasErrors('redes.0.tipo_red_social_id');
        guardarProveedor(['redes' => [
            ['tipo_red_social_id' => $this->instagram->id, 'enlace' => '@Lab'],
            ['tipo_red_social_id' => $this->instagram->id, 'enlace' => '@lab'],
        ]])->assertSessionHasErrors(['redes' => 'Hay redes repetidas: el mismo tipo con el mismo enlace.']);
        guardarProveedor(['redes' => collect(range(1, 11))->map(fn ($i) => ['tipo_red_social_id' => $this->instagram->id, 'enlace' => "@lab{$i}"])->all()])
            ->assertSessionHasErrors('redes');

        expect(RedSocialProveedor::count())->toBe(0);
    });

    test('el mismo enlace con otro tipo sí se acepta', function () {
        guardarProveedor(['redes' => [
            ['tipo_red_social_id' => $this->instagram->id, 'enlace' => '@lab'],
            ['tipo_red_social_id' => $this->whatsapp->id, 'enlace' => '@lab'],
        ]])->assertSessionHasNoErrors();

        expect(RedSocialProveedor::count())->toBe(2);
    });
});

describe('cómo se muestran los enlaces', function () {
    test('solo una URL http(s) se vuelve enlace, que abre en otra pestaña sin acceso a esta; lo demás es texto escapado', function () {
        $this->proveedor->update(['sitio_web' => 'https://lab.com.py']);
        $this->proveedor->redesSociales()->create(['tipo_red_social_id' => $this->instagram->id, 'enlace' => 'https://instagram.com/lab']);
        // Valores que no deberían poder guardarse, insertados directo en la base: se muestran como texto.
        DB::table('redes_sociales_proveedor')->insert(['proveedor_id' => $this->proveedor->id, 'tipo_red_social_id' => $this->whatsapp->id,
            'enlace' => 'javascript:alert("x")', 'created_at' => now(), 'updated_at' => now()]);

        $listado = $this->get(route('admin.proveedores.index'))->assertOk()->getContent();
        expect($listado)->toContain('<a href="https://lab.com.py" target="_blank" rel="noopener noreferrer"');

        $edicion = $this->get(route('admin.proveedores.edit', $this->proveedor))->assertOk()->getContent();
        expect($edicion)->not->toContain('href="javascript:')
            ->and($edicion)->toContain('<a href="https://lab.com.py" target="_blank" rel="noopener noreferrer"');

        // El componente: texto escapado si no es http/https.
        $html = Blade::render('<x-admin.enlace-externo :valor="$v" />', ['v' => '<b>@lab</b>']);
        expect($html)->toContain('&lt;b&gt;@lab&lt;/b&gt;')->not->toContain('<a ');
        expect(Blade::render('<x-admin.enlace-externo :valor="$v" />', ['v' => 'javascript:alert(1)']))->not->toContain('href');
    });
});

describe('listado', function () {
    test('muestra la cantidad de contactos activos y busca por contacto activo y por sitio web', function () {
        $this->proveedor->update(['sitio_web' => 'https://laboratoriocentral.com.py']);
        $this->proveedor->contactos()->create(contacto(['nombre' => 'Marisol', 'apellido' => 'Benegas', 'telefono' => '0972 555 111', 'correo' => 'compras@labcentral.com']));
        $this->proveedor->contactos()->create(contacto(['nombre' => 'Oculto', 'apellido' => 'Deshabilitado', 'activo' => false]));
        $otro = Proveedor::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Insfrán', 'nombres' => 'Laura'])->id]);

        $this->get(route('admin.proveedores.index'))->assertOk()
            ->assertSeeInOrder(['Contactos', 'Insfrán, Laura', '—', 'Laboratorio Central S.A.', '1']);

        foreach (['benegas', 'marisol', 'compras@labcentral', '555 111', 'laboratoriocentral.com'] as $q) {
            $this->get(route('admin.proveedores.index', ['q' => $q]))->assertSee('Laboratorio Central S.A.')->assertDontSee('Insfrán');
        }
        // Un contacto deshabilitado no hace aparecer al proveedor.
        $this->get(route('admin.proveedores.index', ['q' => 'oculto']))->assertDontSee('Laboratorio Central S.A.');
    });

    test('rendimiento: con 50 proveedores con contactos y redes, una cantidad fija de consultas', function () {
        foreach (range(1, 50) as $i) {
            $proveedor = Proveedor::create(['persona_id' => Persona::factory()->create()->id, 'sitio_web' => "https://proveedor{$i}.com"]);
            foreach (range(1, 3) as $j) {
                $proveedor->contactos()->create(contacto(['nombre' => "C{$i}-{$j}", 'activo' => $j !== 3]));
                $proveedor->redesSociales()->create(['tipo_red_social_id' => $this->instagram->id, 'enlace' => "@p{$i}{$j}"]);
            }
        }

        $consultas = function (array $parametros) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('admin.proveedores.index', $parametros), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

            return count(DB::getQueryLog());
        };

        $consultas([]); // el primer pedido carga datos que después quedan en memoria (estados, permisos)
        $primera = $consultas([]);
        expect($primera)->toBeLessThan(15)
            // La página 2 (20 proveedores) hace las mismas consultas que la 1: no hay una por fila.
            ->and($consultas(['page' => 2]))->toBe($primera)
            ->and($consultas(['q' => 'C7-1']))->toBeLessThan(15);
    });
});

describe('auditoría', function () {
    test('los cambios de contactos y redes quedan como EDITAR del proveedor, con la lista de antes y la de después', function () {
        $contacto = $this->proveedor->contactos()->create(contacto());
        $this->proveedor->redesSociales()->create(['tipo_red_social_id' => $this->instagram->id, 'enlace' => '@lab']);

        // Deshabilitar el contacto, cambiarle el teléfono y quitar la red.
        guardarProveedor(['contactos' => [[...contacto(['telefono' => '0982 000 000']), 'id' => $contacto->id, 'activo' => '0']], 'redes' => []])
            ->assertSessionHasNoErrors();

        $registros = LogAuditoria::where('tabla_afectada', 'proveedores')->where('accion', AccionAuditoria::EDITAR->value)->orderBy('id')->get();
        $contactos = $registros->first(fn ($log) => array_key_exists('contactos', $log->valor_nuevo ?? []));
        $redes = $registros->first(fn ($log) => array_key_exists('redesSociales', $log->valor_nuevo ?? []));

        expect($contactos->valor_anterior)->toBe(['contactos' => ['Ruiz, Liz — 0981 123 456 · liz@example.com']])
            ->and($contactos->valor_nuevo)->toBe(['contactos' => ['Ruiz, Liz — 0982 000 000 · liz@example.com (deshabilitado)']])
            ->and($redes->valor_anterior)->toBe(['redesSociales' => ['Instagram: @lab']])
            ->and($redes->valor_nuevo)->toBe(['redesSociales' => []]);

        // Y el detalle del evento lo muestra.
        $this->get(route('admin.auditoria.show', $contactos))->assertOk()
            ->assertSeeInOrder(['contactos', 'Ruiz, Liz — 0981 123 456 · liz@example.com', 'Ruiz, Liz — 0982 000 000 · liz@example.com (deshabilitado)']);
        $this->get(route('admin.auditoria.show', $redes))->assertOk()->assertSeeInOrder(['redesSociales', 'Instagram: @lab', '(ninguno)']);
    });

    test('el sitio web lo registra el mecanismo normal', function () {
        guardarProveedor(['sitio_web' => 'lab.com.py']);

        expect(LogAuditoria::where('tabla_afectada', 'proveedores')->get()->first(fn ($log) => array_key_exists('sitio_web', $log->valor_nuevo ?? []))->valor_nuevo)
            ->toBe(['sitio_web' => 'https://lab.com.py']);
    });

    test('guardar sin cambiar contactos ni redes no registra nada de esas listas', function () {
        $contacto = $this->proveedor->contactos()->create(contacto());

        guardarProveedor(['contactos' => [[...contacto(), 'id' => $contacto->id, 'activo' => '1']], 'redes' => []]);

        expect(LogAuditoria::where('tabla_afectada', 'proveedores')->count())->toBe(0);
    });
});

describe('catálogo de tipos de red social', function () {
    test('el seeder siembra los 8 tipos solo si la tabla está vacía (renombrar uno no genera un duplicado)', function () {
        TipoRedSocial::query()->delete();
        $this->seed(DatosRealesClinicaSeeder::class);
        expect(TipoRedSocial::orderBy('nombre')->pluck('nombre')->all())
            ->toBe(['Facebook', 'Instagram', 'LinkedIn', 'Telegram', 'TikTok', 'WhatsApp', 'X', 'YouTube']);

        TipoRedSocial::where('nombre', 'X')->update(['nombre' => 'X (Twitter)']);
        $this->seed(DatosRealesClinicaSeeder::class);

        expect(TipoRedSocial::count())->toBe(8)->and(TipoRedSocial::where('nombre', 'X')->exists())->toBeFalse();
    });

    test('categorías de proveedor y orígenes de turno: mismo criterio', function () {
        $this->seed(DatosRealesClinicaSeeder::class);
        CategoriaProveedor::where('nombre', 'Equipos médicos')->update(['nombre' => 'Equipamiento médico']);
        OrigenTurno::where('codigo', 'WEB')->update(['codigo' => 'SITIO']);

        $this->seed(DatosRealesClinicaSeeder::class);

        expect(CategoriaProveedor::count())->toBe(5)->and(CategoriaProveedor::where('nombre', 'Equipos médicos')->exists())->toBeFalse()
            ->and(OrigenTurno::count())->toBe(4)->and(OrigenTurno::where('codigo', 'WEB')->exists())->toBeFalse();
    });

    test('permisos: sin VER no entra; con VER pero sin CREAR no da de alta', function () {
        $this->actingAs(User::factory()->conPermisos([])->create());
        $this->get(route('admin.tipos-red-social.index'))->assertForbidden();

        $this->actingAs(User::factory()->conPermisos(['TIPOS_RED_SOCIAL' => ['VER']])->create());
        $this->get(route('admin.tipos-red-social.index'))->assertOk()->assertDontSee(route('admin.tipos-red-social.create'));
        $this->post(route('admin.tipos-red-social.store'), ['nombre' => 'Mastodon'])->assertForbidden();
        $this->get('/dashboard')->assertSee(route('admin.tipos-red-social.index'))->assertSee('data-grupo-menu="Personas y Roles"', false);

        // En el menú del Administrador, justo después de Categorías de proveedor.
        $this->actingAs(User::factory()->administrador()->create())->get('/dashboard')
            ->assertSeeInOrder(['data-grupo-menu="Personas y Roles"', 'Categorías de proveedor', 'Tipos de red social', 'Responsables de pago'], false);
    });

    test('aviso de nombre repetido al salir del campo y validación al guardar', function () {
        $this->getJson(route('verificar-unico', ['campo' => 'tipo_red_social.nombre', 'valor' => 'Instagram']))
            ->assertJson(['disponible' => false, 'mensaje' => 'Ya hay un tipo de red social con este nombre. Corríjalo para poder guardar.']);
        $this->post(route('admin.tipos-red-social.store'), ['nombre' => 'Instagram'])->assertSessionHasErrors('nombre');
    });
});

describe('escapado de lo que carga el usuario', function () {
    beforeEach(function () {
        $this->img = '<img src=x onerror=alert(1)>';
        $this->script = '"><script>alert(1)</script>';
    });

    test('HTML y JS en el contacto y la red: escapados en el listado, el formulario y el detalle de auditoría', function () {
        // Por el formulario, como lo cargaría un usuario: todo esto pasa la validación.
        guardarProveedor([
            'contactos' => [contacto(['nombre' => $this->img, 'apellido' => $this->script, 'correo' => '"<img src=x onerror=alert(1)>"@example.com'])],
            'redes' => [['tipo_red_social_id' => $this->instagram->id, 'enlace' => $this->script]],
        ])->assertSessionHasNoErrors();
        expect($this->proveedor->contactos()->sole()->nombre)->toBe($this->img)
            ->and($this->proveedor->redesSociales()->sole()->enlace)->toBe($this->script);

        // Listado: se encuentra buscando el nombre del contacto, y la búsqueda vuelve escapada en el buscador.
        $listado = $this->get(route('admin.proveedores.index', ['q' => $this->img]))->assertOk()
            ->assertSee('Laboratorio Central S.A.')
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
        $listadoAjax = $this->get(route('admin.proveedores.index', ['q' => $this->script]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('Laboratorio Central S.A.');
        foreach ([$listado, $listadoAjax] as $respuesta) {
            $respuesta->assertDontSee($this->img, false)->assertDontSee('<script>alert(1)</script>', false);
        }

        // Formulario: los datos van a Alpine como JSON con <, >, " y ' codificados (<...), y se
        // muestran con x-model (valor del input), nunca como HTML.
        $this->get(route('admin.proveedores.edit', $this->proveedor))->assertOk()
            ->assertDontSee($this->img, false)->assertDontSee('<script>alert(1)</script>', false)
            // (dentro de JSON.parse('...'), por eso la barra va doble: \\u003C)
            ->assertSee('\\\\u003Cimg src=x onerror=alert(1)\\\\u003E', false)
            ->assertSee('\\\\u0022\\\\u003E\\\\u003Cscript\\\\u003Ealert(1)\\\\u003C\\\\\\/script\\\\u003E', false)
            ->assertDontSee('x-html', false);

        // Detalle de auditoría: la lista antes/después de contactos y redes, escapada.
        // (un evento por lista: contactos y redes)
        $logs = LogAuditoria::where('tabla_afectada', 'proveedores')->where('accion', AccionAuditoria::EDITAR)->get();
        $log = $logs->first(fn ($log) => isset($log->valor_nuevo['contactos']));
        $logRedes = $logs->first(fn ($log) => isset($log->valor_nuevo['redesSociales']));
        expect($log->valor_nuevo['contactos'][0])->toContain($this->img)
            ->and($logRedes->valor_nuevo['redesSociales'])->toBe(["Instagram: {$this->script}"]);

        $this->get(route('admin.auditoria.show', $log))->assertOk()
            ->assertDontSee($this->img, false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertSee('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->get(route('admin.auditoria.show', $logRedes))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Instagram: &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false);
    });

    test('un enlace javascript: (con mayúsculas o un tabulador en el esquema) no se guarda por el formulario', function (string $enlace) {
        guardarProveedor(['redes' => [['tipo_red_social_id' => $this->instagram->id, 'enlace' => $enlace]]])->assertSessionHasErrors('redes.0.enlace');
        guardarProveedor(['sitio_web' => $enlace])->assertSessionHasErrors('sitio_web');
        expect(RedSocialProveedor::count())->toBe(0)->and($this->proveedor->fresh()->sitio_web)->toBeNull();
    })->with([
        'mayúsculas mezcladas' => ['JaVaScRiPt:alert(1)'],
        'tabulador en el esquema' => ["java\tscript:alert(1)"],
        'espacios y salto de línea' => [" java\nscript :alert(1)"],
    ]);

    test('aunque llegue a la base por otra vía, un enlace javascript: no queda como <a href> en el listado, el formulario ni la auditoría', function () {
        // Cargados directo en la base (una importación, un dato viejo): sin pasar por la validación.
        $this->proveedor->update(['sitio_web' => 'JaVaScRiPt:alert(1)']);
        $otro = Proveedor::create(['persona_id' => Persona::factory()->create()->id, 'sitio_web' => "java\tscript:alert(1)"]);
        RedSocialProveedor::create(['proveedor_id' => $this->proveedor->id, 'tipo_red_social_id' => $this->instagram->id, 'enlace' => 'JaVaScRiPt:alert(1)']);
        RedSocialProveedor::create(['proveedor_id' => $this->proveedor->id, 'tipo_red_social_id' => $this->whatsapp->id, 'enlace' => "java\tscript:alert(1)"]);
        $log = LogAuditoria::create([
            'usuario_id' => auth()->id(), 'tabla_afectada' => 'proveedores', 'registro_afectado_id' => (string) $this->proveedor->id,
            'accion' => AccionAuditoria::EDITAR, 'fecha_hora' => now(),
            'valor_anterior' => ['sitio_web' => null, 'redesSociales' => []],
            'valor_nuevo' => ['sitio_web' => 'JaVaScRiPt:alert(1)', 'redesSociales' => ['Instagram: JaVaScRiPt:alert(1)', "WhatsApp: java\tscript:alert(1)"]],
        ]);

        // El detector ve lo que vería el navegador: entidades decodificadas y espacios o tabuladores intercalados.
        expect(hrefsPeligrosos('<a href="JaVa&#x53;cript:x">'))->not->toBe([])
            ->and(hrefsPeligrosos("<a href='java\tscript:x'>"))->not->toBe([])
            ->and(hrefsPeligrosos('<a href="https://ejemplo.com">'))->toBe([]);

        // Listado: el sitio web se ve como texto.
        $listado = $this->get(route('admin.proveedores.index'))->assertOk()->assertSee('JaVaScRiPt:alert(1)');
        expect(hrefsPeligrosos($listado->getContent()))->toBe([]);

        // Formulario: ni "Abrir el sitio web" ni "Abrir" en las redes (url null en las dos filas).
        $formulario = $this->get(route('admin.proveedores.edit', $this->proveedor))->assertOk()->assertDontSee('Abrir el sitio web');
        expect(hrefsPeligrosos($formulario->getContent()))->toBe([])
            ->and(substr_count($formulario->getContent(), '\\u0022url\\u0022:null'))->toBe(2)
            ->and($formulario->getContent())->not->toContain('\\u0022url\\u0022:\\u0022');
        $this->get(route('admin.proveedores.edit', $otro))->assertOk()->assertDontSee('Abrir el sitio web');

        // Auditoría: solo texto.
        $detalle = $this->get(route('admin.auditoria.show', $log))->assertOk()
            ->assertSee('JaVaScRiPt:alert(1)')->assertSee('Instagram: JaVaScRiPt:alert(1)');
        expect(hrefsPeligrosos($detalle->getContent()))->toBe([]);
    });
});

/**
 * Los href de la página cuyo esquema no es http/https (javascript:, data:, vbscript:). Se compara como
 * lo hace el navegador: decodificando las entidades y sin espacios ni caracteres de control intercalados.
 */
function hrefsPeligrosos(string $html): array
{
    preg_match_all('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $coincidencias, PREG_SET_ORDER);

    return collect($coincidencias)
        ->map(fn (array $m) => html_entity_decode(($m[1] ?? '').($m[2] ?? '').($m[3] ?? ''), ENT_QUOTES | ENT_HTML5))
        ->filter(fn (string $href) => preg_match('/^(javascript|data|vbscript):/', strtolower(preg_replace('/[\x00-\x20\x7F]+/', '', $href))) === 1)
        ->values()->all();
}
