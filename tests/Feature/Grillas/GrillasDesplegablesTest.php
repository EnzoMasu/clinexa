<?php

use App\Models\AcuerdoReparto;
use App\Models\CatalogoCIE10;
use App\Models\CategoriaGasto;
use App\Models\CategoriaProveedor;
use App\Models\Compra;
use App\Enums\CondicionCompra;
use App\Models\Disponibilidad;
use App\Models\Especialidad;
use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Timbrado;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Grilla\Grilla;
use Carbon\Carbon;
use Database\Seeders\DatosRealesClinicaSeeder;
use Database\Seeders\Demo\DiasDemo;
use Database\Seeders\GeografiaSeeder;
use Database\Seeders\ModuloSistemaSeeder;
use Database\Seeders\PerfilAdministradorSeeder;
use Database\Seeders\PerfilesPredefinidosSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/*
 * Cada desplegable de filtro de cada grilla (docs/grillas.md), con los datos demo (inventados): se elige cada opción
 * por lo que dice (como en el formulario), se manda su valor y cada fila del resultado tiene que ser de ese registro.
 * Si un valor no fuera el del registro (p. ej. el índice 0, 1, 2… en lugar del id), las filas serían de otro.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.zona_horaria_local')));
    $this->seed([ModuloSistemaSeeder::class, PerfilAdministradorSeeder::class, PerfilesPredefinidosSeeder::class, GeografiaSeeder::class, DatosRealesClinicaSeeder::class]);
    foreach (DiasDemo::CODIGOS_CIE10 as $codigo) {
        CatalogoCIE10::create(['codigo' => $codigo, 'descripcion' => "Diagnóstico de prueba {$codigo}", 'capitulo' => 'XXI']);
    }
    $this->artisan('clinexa:datos-demo', ['--confirmo-desarrollo' => true, '--password' => 'Clave-De-Prueba-5432'])->assertSuccessful();
    CategoriaGasto::create(['nombre' => 'Gasto de prueba', 'estado_id' => Estado::idDe(Estado::ACTIVO)]); // los datos demo no traen ninguna
    CategoriaGasto::create(['nombre' => 'Otro gasto de prueba', 'estado_id' => Estado::idDe(Estado::ACTIVO)]);
    grillasDesplegablesCompras(); // ni compras
    $this->actingAs(User::factory()->administrador()->conPersona(['apellidos' => 'Pruebas', 'nombres' => 'Desplegables'])->create());
});

afterEach(fn () => Carbon::setTestNow());

/** Dos compras inventadas (otra categoría y condición cada una) para los desplegables de Compras. */
function grillasDesplegablesCompras(): void
{
    $usuario = User::factory()->create();
    foreach ([['Gasto de prueba', CondicionCompra::CONTADO, null, 0], ['Otro gasto de prueba', CondicionCompra::CREDITO, '2026-11-20', 1000]] as $i => [$categoria, $condicion, $vencimiento, $anticipo]) {
        Compra::create([
            'proveedor_id' => App\Models\Proveedor::create(['persona_id' => Persona::factory()->create()->id])->id,
            'categoria_gasto_id' => CategoriaGasto::where('nombre', $categoria)->sole()->id,
            'fecha' => '2026-10-05', 'tipo_comprobante_id' => App\Models\TipoComprobanteCompra::where('nombre', 'Ticket')->sole()->id,
            'numero_comprobante' => "D-{$i}", 'fecha_comprobante' => '2026-10-05', 'moneda_id' => App\Models\Moneda::guarani()->id,
            'total' => 25000, 'condicion' => $condicion, 'vencimiento' => $vencimiento, 'anticipo' => $anticipo, 'registrada_por' => $usuario->id,
        ]);
    }
}

/** Lo que dice la opción que le corresponde a la fila, según el registro (no según el desplegable). */
function grillasDesplegablesEtiquetas(string $ruta, string $clave, Model $fila): array
{
    $fila = $fila->newQuery()->findOrFail($fila->getKey());
    $ruta = preg_replace('/\.index$/', '', $ruta);
    $nombres = fn ($registros) => $registros->pluck('nombre')->all();

    return match ("{$ruta}.{$clave}") {
        'auditoria.usuario' => [$fila->usuario?->name ?? '(Sin usuario: correo inexistente)'],
        'auditoria.accion' => [$fila->accion->etiqueta()],
        'auditoria.modulo' => [ModuloSistema::where('codigo', Auditoria::modulosPorTabla()[$fila->tabla_afectada] ?? '')->value('nombre')],
        'acuerdos-reparto.tipo' => [AcuerdoReparto::TIPOS[$fila->tipo]],
        'compras.categoria' => [$fila->categoriaGasto->nombre],
        'liquidaciones-beneficiarios.tipo' => [App\Models\LiquidacionBeneficiario::BENEFICIARIOS[$fila->beneficiario]],
        'compras.condicion' => [$fila->condicion->etiqueta()],
        'cobros.medios' => $fila->lineas->map(fn ($linea) => $linea->medioPago->nombre)->all(),
        'disponibilidades.dia' => [Disponibilidad::DIAS[$fila->dia_semana]],
        'facturas.motivos.detalle' => [$fila->requiere_detalle ? 'Sí' : 'No'],
        'facturas.tasas-iva.tasa' => [Procedimiento::TASAS_IVA[$fila->tasa_iva]],
        'personas.tipo' => [$fila->tipo_persona],
        'prestaciones.origen' => [Prestacion::ORIGENES[$fila->informe_estudio_id ? 'INFORME' : 'CONSULTA']],
        'prestaciones.cobro' => [$fila->cobroVigente ? Prestacion::COBRADA : Prestacion::PENDIENTE_DE_COBRO],
        'procedimientos.tipo' => [$fila->tipo],
        'profesionales.especialidades' => $nombres($fila->especialidades),
        'profesionales.especialidades' => $fila->especialidades()->syncWithoutDetaching([Especialidad::where('nombre', $texto)->sole()->id => ['activa' => true, 'fecha_desde' => '2026-01-01']]) !== [],
        'proveedores.categorias' => $nombres($fila->categorias),
        'tipos-documento.modulos' => $nombres($fila->modulos),
        'tipos-comprobante-compra.numero' => [$fila->requiere_numero ? ($fila->numero_formato_legal ? 'Sí (formato legal)' : 'Sí') : 'No'],
        'tipos-comprobante-compra.timbrado' => [$fila->requiere_timbrado ? 'Sí' : 'No'],
        'tipos-comprobante-compra.fecha' => [$fila->requiere_fecha_comprobante ? 'Sí' : 'No'],
        'tipos-comprobante-compra.iva' => [$fila->discrimina_iva ? 'Sí' : 'No'],
        // Lo que dice la fila según el cálculo en PHP (EstadoVisacionTurnos), no según la consulta SQL que se prueba.
        'turnos.seguro' => [\App\Support\Convenios\EstadoVisacionTurnos::para([$fila])[$fila->id]['seguro'] ?? ''],
        'turnos.visacion' => [match (\App\Support\Convenios\EstadoVisacionTurnos::para([$fila])[$fila->id]['visada'] ?? null) {
            true => 'Visado', false => 'Falta visar', null => 'Sin seguro',
        }],
        'usuarios.perfiles' => $nombres($fila->perfiles),
        default => $clave === 'estado' ? grillasDesplegablesEstado($ruta === 'historias-clinicas' ? $fila->paciente : $fila) : [],
    };
}

/** El estado de la fila, con el nombre de la tabla estados o la etiqueta del modelo (ETIQUETAS). */
function grillasDesplegablesEstado(Model $fila): array
{
    $estado = Estado::findOrFail($fila->estado_id);
    $etiquetas = defined($fila::class.'::ETIQUETAS') ? constant($fila::class.'::ETIQUETAS') : [];

    return array_values(array_filter([$etiquetas[$estado->codigo] ?? null, $estado->nombre]));
}

/** Las opciones de los desplegables de filtro del formulario del listado: clave => [valor => texto]. */
function grillasDesplegablesDelFormulario(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $desplegables = [];
    foreach ((new DOMXPath($dom))->query('//select[@form="'.Grilla::FORMULARIO.'"]') as $select) {
        preg_match('/^f\[(.+)\]$/', $select->getAttribute('name'), $nombre);
        foreach ($select->getElementsByTagName('option') as $opcion) {
            if ($opcion->getAttribute('value') !== '') {
                $desplegables[$nombre[1]][$opcion->getAttribute('value')] = trim($opcion->textContent);
            }
        }
    }

    return $desplegables;
}

function grillasDesplegablesPaginador($respuesta): LengthAwarePaginator
{
    return collect($respuesta->original->getData())->first(fn ($dato) => $dato instanceof LengthAwarePaginator);
}

/**
 * Deja la fila con lo que dice la opción (cuando los datos demo no la tienen): el estado por su nombre o su etiqueta,
 * la tasa de IVA por su texto, la categoría por su nombre. Nunca por el valor del desplegable, que es lo que se prueba.
 */
function grillasDesplegablesPreparar(string $ruta, string $clave, Model $fila, string $texto): bool
{
    $ruta = preg_replace('/\.index$/', '', $ruta);
    $cambiar = fn (Model $registro, array $datos) => (bool) $registro->newQuery()->whereKey($registro->getKey())->update($datos);

    if ($clave === 'estado') {
        $registro = $ruta === 'historias-clinicas' ? $fila->paciente : $fila;
        $codigo = array_search($texto, defined($registro::class.'::ETIQUETAS') ? constant($registro::class.'::ETIQUETAS') : [], true);
        $estado = $codigo !== false ? Estado::where('codigo', $codigo)->sole() : Estado::where('nombre', $texto)->sole();

        return $cambiar($registro, ['estado_id' => $estado->id]);
    }

    return match ("{$ruta}.{$clave}") {
        'facturas.tasas-iva.tasa' => $cambiar($fila, ['tasa_iva' => array_search($texto, Procedimiento::TASAS_IVA, true)]),
        'profesionales.especialidades' => $fila->especialidades()->syncWithoutDetaching([Especialidad::where('nombre', $texto)->sole()->id => ['activa' => true, 'fecha_desde' => '2026-01-01']]) !== [],
        'proveedores.categorias' => $fila->categorias()->syncWithoutDetaching([CategoriaProveedor::where('nombre', $texto)->sole()->id]) !== [],
        default => false,
    };
}

/** Filas que no se tocan: el usuario del test, su persona y sus perfiles (dejaría de poder entrar). */
function grillasDesplegablesIntocable(Model $fila): bool
{
    $usuario = auth()->user();

    return ($fila instanceof User && $fila->is($usuario)) || ($fila instanceof Persona && $fila->getKey() === $usuario->persona_id)
        || ($fila instanceof PerfilAcceso && $usuario->perfiles->contains($fila));
}

/** Las filas del listado sin filtros (hasta 10 páginas), salvo las que no se tocan. */
function grillasDesplegablesFilas($test, string $ruta, int $paginas): array
{
    $filas = [];
    foreach (range(1, min($paginas, 10)) as $pagina) {
        $filas = array_merge($filas, grillasDesplegablesPaginador($test->get(route("admin.{$ruta}", ['page' => $pagina])))->items());
    }

    return array_values(array_filter($filas, fn ($fila) => ! grillasDesplegablesIntocable($fila)));
}

/** Una segunda fila para las grillas que tienen una sola: copia de la primera, con otro nombre o número. */
function grillasDesplegablesCopia(Model $fila): Model
{
    $copia = $fila->replicate();
    foreach (['nombre', 'codigo'] as $campo) {
        if (is_string($copia->getAttribute($campo))) {
            $copia->setAttribute($campo, mb_substr($copia->getAttribute($campo), 0, 20).' (copia)');
        }
    }
    if (array_key_exists('persona_id', $copia->getAttributes()) && ! $copia instanceof Persona) {
        $copia->persona_id = Persona::factory()->create()->id;
    }
    if ($copia instanceof Timbrado) {
        $copia->numero = (string) ((int) $copia->numero + 1);
        $copia->punto_expedicion = '999';
    }
    $copia->saveQuietly();

    return $copia;
}

test('cada opción de cada desplegable de filtro filtra por su registro', function () {
    $errores = [];
    $desplegablesProbados = 0;

    foreach (grillasTodasRutas() as $ruta) {
        $pagina = $this->get(route("admin.{$ruta}"))->assertOk();
        $filas = grillasDesplegablesFilas($this, $ruta, grillasDesplegablesPaginador($pagina)->lastPage());
        if (count($filas) === 1) {
            $filas[] = grillasDesplegablesCopia($filas[0]);
        }

        foreach (grillasDesplegablesDelFormulario($pagina->getContent()) as $clave => $opciones) {
            $desplegablesProbados++;

            // Que haya filas de más de una opción: a cada opción que falta se le pasa una fila cuyo valor se repite en otra.
            $deFila = array_map(fn ($fila) => grillasDesplegablesEtiquetas($ruta, $clave, $fila), $filas);
            $presentes = array_values(array_unique(array_merge(...$deFila)));
            $esperadas = $presentes;
            $veces = array_count_values(array_map(fn ($etiquetas) => $etiquetas[0] ?? '', $deFila));
            $libres = array_keys(array_filter($deFila, fn ($etiquetas) => ($etiquetas[0] ?? '') !== ''));
            foreach (array_diff($opciones, $presentes) as $texto) {
                while (($indice = array_shift($libres)) !== null && $veces[$deFila[$indice][0]] < 2);
                if ($indice !== null && grillasDesplegablesPreparar($ruta, $clave, $filas[$indice], $texto)) {
                    $veces[$deFila[$indice][0]]--;
                    $esperadas[] = $texto;
                }
            }
            if (count(array_intersect($opciones, $esperadas)) < min(2, count($opciones))) {
                $errores["{$ruta}.{$clave}"] = 'sin filas de dos opciones distintas para probar';
            }

            foreach ($opciones as $valor => $texto) {
                $filtrado = grillasDesplegablesPaginador($this->get(route("admin.{$ruta}", ['f' => [$clave => (string) $valor]]))->assertOk());
                foreach ($filtrado->items() as $fila) {
                    $etiquetas = grillasDesplegablesEtiquetas($ruta, $clave, $fila);
                    if (! in_array($texto, $etiquetas, true)) {
                        $errores["{$ruta}.{$clave}={$valor}"] = "al elegir «{$texto}» vino una fila de «".implode(' / ', $etiquetas).'»';
                    }
                }
                if (in_array($texto, $esperadas, true) && $filtrado->total() === 0) {
                    $errores["{$ruta}.{$clave}={$valor}"] = "al elegir «{$texto}» no vino ninguna fila y hay filas de «{$texto}»";
                }
            }
        }
    }

    expect($desplegablesProbados)->toBeGreaterThan(count(grillasTodasRutas()))
        ->and($errores)->toBe([]);
})->group('lento'); // carga los datos demo (docs/datos-demo.md)
