<?php

use App\Exceptions\RecetaInmutable;
use App\Http\Controllers\Admin\RecetaController;
use App\Models\Consulta;
use App\Models\DetalleReceta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\Receta;
use App\Models\Sucursal;
use App\Models\TipoIndicacion;
use App\Models\User;
use App\Policies\ConsultaPolicy;
use App\Support\MediaHoja;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
 * Recetas de una consulta: borrador, vista previa, emisión, hoja impresa y anulación. "Ahora" del
 * escenario: martes 06/10/2026 09:00 en Paraguay. La Dra. Benítez atiende la consulta.
 */

beforeEach(function () {
    hcEscenario();
    $this->medico = hcDarPermisos($this->medico, HC_PERMISOS_RECETAS);
    $this->otroMedico = hcDarPermisos($this->otroMedico, HC_PERMISOS_RECETAS);
    $this->actingAs($this->medico);
    $this->consulta = hcConsulta();
});

afterEach(fn () => Carbon::setTestNow());

function estadoDe(Receta $receta): string
{
    return $receta->fresh()->estado->codigo;
}

function anular(Receta $receta, string $motivo = 'Dosis mal indicada', string $ruta = 'admin.recetas.anular')
{
    return test()->post(route($ruta, $receta), ['motivo' => $motivo]);
}

describe('autorización', function () {
    test('el profesional dueño crea, edita, emite y anula', function () {
        $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Nueva receta');
        $this->get(route('admin.recetas.create', $this->consulta))->assertOk()
            ->assertSee('<form method="POST" action="'.route('admin.recetas.store', $this->consulta).'" novalidate', false);
        $receta = hcReceta($this->consulta);

        $this->get(route('admin.recetas.edit', $receta))->assertOk();
        $this->put(route('admin.recetas.update', $receta), ['version' => $receta->version(), 'con_detalles' => '1', 'detalles' => [hcRenglon(['dosis' => '2 comprimidos'])]])
            ->assertRedirect(route('admin.recetas.vista-previa', $receta));
        hcEmitir($receta)->assertRedirect(route('admin.recetas.imprimir', $receta));
        anular($receta)->assertRedirect(route('admin.consultas.show', $this->consulta));

        expect(estadoDe($receta))->toBe('ANULADO');
    });

    test('otro profesional con permisos recibe 403 en todo lo que escribe, con el aviso de siempre', function () {
        $receta = hcReceta($this->consulta);
        $this->actingAs($this->otroMedico);

        $this->get(route('admin.recetas.create', $this->consulta))->assertForbidden()->assertSee(ConsultaPolicy::SOLO_EL_QUE_ATIENDE);
        hcGuardarReceta($this->consulta)->assertForbidden();
        $this->get(route('admin.recetas.edit', $receta))->assertForbidden();
        $this->put(route('admin.recetas.update', $receta), ['version' => $receta->version(), 'con_detalles' => '1', 'detalles' => [hcRenglon()]])->assertForbidden();
        hcEmitir($receta)->assertForbidden();
        anular($receta)->assertForbidden();

        // Pero lee: la sección, la vista previa (y la hoja cuando esté emitida).
        $this->get(route('admin.recetas.vista-previa', $receta))->assertOk()->assertDontSee('Emitir e imprimir');
        $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Borrador')->assertDontSee('Nueva receta')
            ->assertDontSee(route('admin.recetas.edit', $receta));
        expect(estadoDe($receta))->toBe('PENDIENTE')->and(Receta::count())->toBe(1);
    });

    test('el Administrador que no es el profesional recibe 403', function () {
        $receta = hcReceta($this->consulta);
        $this->actingAs(User::factory()->administrador()->create());

        $this->get(route('admin.recetas.create', $this->consulta))->assertForbidden();
        hcEmitir($receta)->assertForbidden();
        anular($receta)->assertForbidden();
        $this->get(route('admin.recetas.vista-previa', $receta))->assertOk();
        expect(estadoDe($receta))->toBe('PENDIENTE');
    });

    test('sin VER sobre RECETAS no se ve la sección (ni en la página ni en el popup) ni la hoja', function () {
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);
        [$lector] = hcMedico(['apellidos' => 'Ríos', 'nombres' => 'Ana'], 'MP-3', ['HISTORIA_CLINICA' => ['VER']]);
        $this->flushSession(); // sin el aviso "Receta RE-0000001 emitida." que dejó la emisión
        $this->actingAs($lector);

        $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertDontSee('RE-0000001')->assertDontSee('Acciones de recetas');
        $this->get(route('admin.consultas.detalle', $this->consulta), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertDontSee('RE-0000001');
        $this->get(route('admin.recetas.imprimir', $receta))->assertForbidden();
        $this->get(route('admin.recetas.vista-previa', $receta))->assertForbidden();
    });

    test('con VER pero sin CREAR ni EDITAR: se ve, sin botones, y los envíos dan 403', function () {
        $borrador = hcReceta($this->consulta);
        $emitida = hcReceta($this->consulta);
        hcEmitir($emitida);
        // La misma profesional, pero con un perfil sin CREAR ni EDITAR sobre RECETAS.
        $soloVer = hcConPerfil($this->medico, ['HISTORIA_CLINICA' => ['VER'], 'RECETAS' => ['VER']]);
        $this->flushSession();
        $this->actingAs($soloVer);

        $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('RE-0000001')
            ->assertDontSee('Nueva receta')->assertDontSee(route('admin.recetas.edit', $borrador))->assertDontSee(route('admin.recetas.anular', $emitida));
        $this->get(route('admin.recetas.imprimir', $emitida))->assertOk()->assertDontSee(route('admin.recetas.anular', $emitida));

        $this->get(route('admin.recetas.create', $this->consulta))->assertForbidden();
        hcGuardarReceta($this->consulta)->assertForbidden();
        hcEmitir($borrador)->assertForbidden();
        $this->get(route('admin.recetas.edit', $borrador))->assertForbidden();
        anular($emitida)->assertForbidden();
        anular($emitida, ruta: 'admin.recetas.corregir')->assertForbidden();
    });

    test('"Anular y corregir" pide EDITAR y CREAR: con solo EDITAR se anula pero no se corrige', function () {
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);
        $sinCrear = hcConPerfil($this->medico, ['HISTORIA_CLINICA' => ['VER', 'EDITAR'], 'RECETAS' => ['VER', 'EDITAR']]);
        $this->actingAs($sinCrear);

        $this->get(route('admin.recetas.imprimir', $receta))->assertOk()->assertSee(route('admin.recetas.anular', $receta))->assertDontSee('Anular y corregir');
        anular($receta, ruta: 'admin.recetas.corregir')->assertForbidden();
        expect(estadoDe($receta))->toBe('EMITIDO');
    });

    test('hasta 10 recetas sin anular por consulta', function () {
        foreach (range(1, RecetaController::MAXIMO_RECETAS) as $n) {
            hcReceta($this->consulta);
        }

        hcGuardarReceta($this->consulta)->assertForbidden()->assertSee('ya tiene 10 recetas sin anular');
        anular(Receta::first(), 'Ya no hace falta');
        hcGuardarReceta($this->consulta)->assertRedirect();
    });
});

describe('ciclo de vida', function () {
    test('borrador → emitida → anulada, con número, fecha, emitida_en, anulada_en y motivo', function () {
        $receta = hcReceta($this->consulta);
        expect(estadoDe($receta))->toBe('PENDIENTE')->and($receta->numero)->toBeNull()->and($receta->fecha)->toBeNull();

        hcEmitir($receta)->assertSessionHas('status', 'Receta RE-0000001 emitida.')->assertSessionHas('imprimir', true);
        $receta->refresh();
        expect($receta->estado->codigo)->toBe('EMITIDO')->and($receta->numero)->toBe('RE-0000001')
            ->and($receta->fecha->format('Y-m-d'))->toBe('2026-10-06')->and($receta->emitida_en)->not->toBeNull()
            ->and($receta->snapshot['numero'])->toBe('RE-0000001');

        anular($receta, 'Se indicó el medicamento equivocado')->assertRedirect();
        $receta->refresh();
        expect($receta->estado->codigo)->toBe('ANULADO')->and($receta->anulada_en)->not->toBeNull()->and($receta->motivo_anulacion)->toBe('Se indicó el medicamento equivocado');

        // Queda visible, atenuada, con el motivo.
        $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Anulada')->assertSee('Motivo de la anulación: Se indicó el medicamento equivocado');
    });

    test('un borrador también se anula; no se anula dos veces ni se emite una anulada', function () {
        $receta = hcReceta($this->consulta);

        anular($receta)->assertRedirect();
        anular($receta)->assertForbidden()->assertSee('Esta receta ya está anulada.');
        hcEmitir($receta)->assertRedirect(route('admin.recetas.imprimir', $receta))->assertSessionHas('error');
        expect(estadoDe($receta))->toBe('ANULADO')->and($receta->fresh()->numero)->toBeNull();
    });

    test('el motivo de la anulación es obligatorio, de 5 a 300 caracteres', function (string $motivo) {
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);

        anular($receta, $motivo)->assertSessionHasErrors('motivo');
        expect(estadoDe($receta))->toBe('EMITIDO');
    })->with(['vacío' => ['   '], 'corto' => ['abcd'], 'largo' => [str_repeat('a', 301)]]);

    test('una emitida no se edita por la pantalla: la edición lleva a la hoja y guardar da 403', function () {
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);

        $this->get(route('admin.recetas.edit', $receta))->assertRedirect(route('admin.recetas.imprimir', $receta));
        $this->put(route('admin.recetas.update', $receta), ['version' => $receta->fresh()->version(), 'con_detalles' => '1', 'detalles' => [hcRenglon(['dosis' => 'Otra'])]])
            ->assertForbidden();
        expect($receta->detalles()->sole()->dosis)->toBe('1 comprimido');
    });

    test('el modelo rechaza tocar una emitida o anulada: contenido, renglones, snapshot y transiciones inválidas', function (string $estado) {
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);
        if ($estado === 'ANULADO') {
            anular($receta);
        }
        $receta = Receta::find($receta->id);
        $detalle = $receta->detalles()->sole();

        expect(fn () => $receta->update(['observaciones' => 'cambiada']))->toThrow(RecetaInmutable::class)
            ->and(fn () => Receta::find($receta->id)->forceFill(['snapshot' => ['otra' => 'cosa']])->save())->toThrow(RecetaInmutable::class)
            ->and(fn () => Receta::find($receta->id)->forceFill(['numero' => 'RE-9999999'])->save())->toThrow(RecetaInmutable::class)
            ->and(fn () => $receta->detalles()->create([...hcRenglon(), 'orden' => 2]))->toThrow(RecetaInmutable::class)
            ->and(fn () => $detalle->update(['dosis' => 'otra']))->toThrow(RecetaInmutable::class)
            ->and(fn () => $detalle->delete())->toThrow(RecetaInmutable::class)
            ->and(fn () => Receta::find($receta->id)->delete())->toThrow(RecetaInmutable::class)
            ->and(fn () => Receta::find($receta->id)->update(['estado_id' => Estado::idDe(Estado::PENDIENTE)]))->toThrow(RecetaInmutable::class);

        expect(DetalleReceta::count())->toBe(1)->and(Receta::find($receta->id)->observaciones)->toBeNull();
    })->with(['emitida' => ['EMITIDO'], 'anulada' => ['ANULADO']]);

    test('transiciones: solo PENDIENTE→EMITIDO, PENDIENTE→ANULADO y EMITIDO→ANULADO', function () {
        expect(Receta::TRANSICIONES)->toBe([
            'PENDIENTE' => ['EMITIDO', 'ANULADO'],
            'EMITIDO' => ['ANULADO'],
        ]);

        $anulada = hcReceta($this->consulta);
        anular($anulada);
        expect(fn () => Receta::find($anulada->id)->update(['estado_id' => Estado::idDe(Estado::EMITIDO)]))->toThrow(RecetaInmutable::class);

        // Una emitida solo cambia estado, fecha y motivo de anulación, aunque quiera colar otro campo.
        $emitida = hcReceta($this->consulta);
        hcEmitir($emitida);
        expect(fn () => Receta::find($emitida->id)->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'motivo_anulacion' => 'x', 'observaciones' => 'colada'])->save())
            ->toThrow(RecetaInmutable::class);
    });

    test('un borrador permite quitar renglones, y la receta se emite con lo que quedó', function () {
        $receta = hcReceta($this->consulta, [hcRenglon(), hcRenglon(['medicamento' => 'Ibuprofeno 400 mg'])]);
        $detalles = $receta->detalles()->orderBy('orden')->get();

        $this->put(route('admin.recetas.update', $receta), ['version' => $receta->version(), 'con_detalles' => '1',
            'detalles' => [hcRenglon(['id' => (string) $detalles[1]->id, 'medicamento' => 'Ibuprofeno 400 mg'])]])->assertSessionHasNoErrors();

        expect($receta->detalles()->pluck('medicamento')->all())->toBe(['Ibuprofeno 400 mg']);
        hcEmitir($receta)->assertSessionHas('status');
    });

    test('sin JavaScript (sin la marca) los renglones guardados no se tocan', function () {
        $receta = hcReceta($this->consulta, [hcRenglon(), hcRenglon(['medicamento' => 'Ibuprofeno 400 mg'])]);

        $this->put(route('admin.recetas.update', $receta), ['version' => $receta->version(), 'observaciones' => 'Solo esto'])->assertSessionHasNoErrors();

        expect($receta->detalles()->count())->toBe(2)->and($receta->fresh()->observaciones)->toBe('Solo esto');
        $this->get(route('admin.recetas.create', $this->consulta))->assertSee('<template x-if="true"><input type="hidden" name="con_detalles" value="1"></template>', false);
    });

    test('doble emisión: el segundo envío no emite de nuevo, avisa y va a la hoja', function () {
        $receta = hcReceta($this->consulta);
        $version = $receta->version();

        hcEmitir($receta, $version)->assertSessionHas('status');
        hcEmitir($receta, $version)->assertRedirect(route('admin.recetas.imprimir', $receta))->assertSessionHas('aviso', 'La receta ya estaba emitida.');

        expect(Receta::whereNotNull('numero')->count())->toBe(1)
            ->and(LogAuditoria::where('tabla_afectada', 'recetas')->where('accion', 'EDITAR')->get()->filter(fn ($e) => isset($e->valor_nuevo['numero'])))->toHaveCount(1);
    });

    test('si el borrador cambió después de la vista previa, no se emite', function () {
        $receta = hcReceta($this->consulta);
        $vista = $receta->version();

        Carbon::setTestNow(now()->addMinute());
        $this->put(route('admin.recetas.update', $receta), ['version' => $vista, 'con_detalles' => '1', 'detalles' => [hcRenglon(['dosis' => '2 comprimidos'])]])->assertSessionHasNoErrors();

        hcEmitir($receta, $vista)->assertRedirect(route('admin.recetas.vista-previa', $receta))
            ->assertSessionHas('error', RecetaController::VISTA_PREVIA_VIEJA);
        expect(estadoDe($receta))->toBe('PENDIENTE');
    });

    test('no se emite sin renglones', function () {
        $receta = hcReceta($this->consulta);
        $receta->detalles()->each(fn ($detalle) => $detalle->delete()); // posible en un borrador

        hcEmitir($receta)->assertSessionHas('error', 'La receta no tiene medicamentos. Agregue al menos uno.');
        expect(estadoDe($receta))->toBe('PENDIENTE');
    });

    test('"Anular y corregir": anula, crea el borrador con copia y "Reemplaza a" en la hoja nueva', function () {
        $receta = hcReceta($this->consulta, [hcRenglon(), hcRenglon(['medicamento' => 'Ibuprofeno 400 mg'])], ['observaciones' => 'Volver si hay fiebre']);
        hcEmitir($receta);

        anular($receta, 'Error en la dosis', 'admin.recetas.corregir')->assertRedirect();
        $nueva = Receta::latest('id')->first();

        expect(estadoDe($receta))->toBe('ANULADO')
            ->and($nueva->esBorrador())->toBeTrue()->and($nueva->reemplaza_a_id)->toBe($receta->id)
            ->and($nueva->observaciones)->toBe('Volver si hay fiebre')
            ->and($nueva->detalles()->orderBy('orden')->pluck('medicamento')->all())->toBe(['Amoxicilina 500 mg', 'Ibuprofeno 400 mg']);

        $this->get(route('admin.recetas.edit', $nueva))->assertOk()->assertSee('Reemplaza a RE-0000001');
        hcEmitir($nueva);
        $this->get(route('admin.recetas.imprimir', $nueva))->assertOk()->assertSee('Receta N° RE-0000002')->assertSee('Reemplaza a RE-0000001');

        // Solo una emitida se corrige.
        anular($nueva, 'Otra vez', 'admin.recetas.corregir')->assertRedirect();
        $borrador = Receta::latest('id')->first();
        anular($borrador, 'Un borrador no', 'admin.recetas.corregir')->assertForbidden()->assertSee('Solo se corrige una receta emitida.');
    });
});

describe('número', function () {
    test('correlativo, único, RE-0000001, y los borradores no consumen números', function () {
        $primero = hcReceta($this->consulta);
        $segundo = hcReceta($this->consulta);
        $tercero = hcReceta($this->consulta);

        hcEmitir($segundo);
        anular($primero); // un borrador anulado nunca tiene número
        hcEmitir($tercero);

        expect($segundo->fresh()->numero)->toBe('RE-0000001')->and($tercero->fresh()->numero)->toBe('RE-0000002')
            ->and($primero->fresh()->numero)->toBeNull()->and(Receta::siguienteNumero())->toBe('RE-0000003');
    });

    test('el número es único en la base', function () {
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);
        $otra = hcReceta($this->consulta);

        expect(fn () => $otra->forceFill(['numero' => 'RE-0000001'])->save())->toThrow(QueryException::class);
    });
});

describe('snapshot', function () {
    test('reimprimir sale idéntico aunque cambien el paciente, la matrícula, la sucursal y las indicaciones', function () {
        $this->actingAs($this->medico);
        hcActualizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'con_indicaciones' => '1',
            'indicaciones' => [['id' => '', 'tipo_indicacion_id' => TipoIndicacion::create(['nombre' => 'Reposo'])->id, 'descripcion' => 'Reposo 48 horas']]])->assertSessionHasNoErrors();
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);
        $antes = $this->get(route('admin.recetas.imprimir', $receta))->assertOk()->assertSee('Carmen Duarte')->assertSee('Mat. MP-1')->assertSee('Reposo 48 horas')->getContent();

        $this->paciente->persona->update(['nombres' => 'Carmen Rosa', 'apellidos' => 'Duarte Peña']);
        $this->profesional->update(['matricula' => 'MP-99']);
        Sucursal::query()->update(['direccion' => 'Otra dirección']);
        Carbon::setTestNow(now()->addMinute());
        $indicacion = $this->consulta->indicaciones()->sole();
        hcActualizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'con_indicaciones' => '1',
            'indicaciones' => [['id' => (string) $indicacion->id, 'activo' => '1', 'tipo_indicacion_id' => $indicacion->tipo_indicacion_id, 'descripcion' => 'Reposo 7 días']]])->assertSessionHasNoErrors();

        $despues = $this->get(route('admin.recetas.imprimir', $receta))->assertOk()->getContent();
        $hoja = fn (string $html) => preg_replace('#.*(<div class="hoja">.*)<script>.*#s', '$1', $html);
        expect($hoja($despues))->toBe($hoja($antes))
            ->and($despues)->not->toContain('Duarte Peña')->not->toContain('MP-99')->not->toContain('Reposo 7 días')->not->toContain('Otra dirección');
    });

    test('el borrador (vista previa) se dibuja con los datos de hoy', function () {
        $receta = hcReceta($this->consulta);
        $this->get(route('admin.recetas.vista-previa', $receta))->assertSee('Mat. MP-1');

        $this->profesional->update(['matricula' => 'MP-99']);
        $this->get(route('admin.recetas.vista-previa', $receta))->assertSee('Mat. MP-99')->assertSee('(se asigna al emitir)');
    });

    test('guarda todo lo que se imprime', function () {
        $receta = hcReceta($this->consulta, [], ['observaciones' => 'Volver si hay fiebre']);
        hcEmitir($receta);

        expect($receta->fresh()->snapshot)->toMatchArray([
            'clinica' => ['nombre' => 'Plenitud Mujer', 'direccion' => 'Iturbe', 'telefono' => '0975'],
            'numero' => 'RE-0000001',
            'fecha' => '2026-10-06',
            'reemplaza_a' => null,
            'paciente' => ['nombre' => 'Carmen Duarte', 'documento' => $this->paciente->persona->tipoDocumento->codigo.' '.$this->paciente->persona->nro_documento, 'ficha' => 'FP-0000001', 'edad' => 36],
            'profesional' => ['nombre' => 'Rosa Benítez', 'matricula' => 'MP-1', 'especialidades' => []],
            'medicamentos' => [['medicamento' => 'Amoxicilina 500 mg', 'cantidad' => '1 caja x 21', 'dosis' => '1 comprimido', 'via' => 'Oral',
                'frecuencia' => 'Cada 8 horas', 'duracion' => '7 días', 'observaciones' => 'Tomar con las comidas']],
            'observaciones' => 'Volver si hay fiebre',
            'indicaciones' => [],
        ]);
    });
});

describe('límites', function () {
    test('hasta 6 renglones', function () {
        hcGuardarReceta($this->consulta, array_fill(0, 7, hcRenglon(['cantidad' => '', 'observaciones' => ''])))->assertSessionHasErrors('detalles');
        hcGuardarReceta($this->consulta, [])->assertSessionHasNoErrors(); // el helper pone uno
        $this->post(route('admin.recetas.store', $this->consulta), ['con_detalles' => '1', 'detalles' => []])->assertSessionHasErrors(['detalles' => 'Agregue al menos un medicamento.']);
    });

    test('obligatorios y largos máximos de cada campo', function (array $cambios, string $campo) {
        hcGuardarReceta($this->consulta, [hcRenglon($cambios)])->assertSessionHasErrors("detalles.0.{$campo}");
    })->with([
        'sin medicamento' => [['medicamento' => ''], 'medicamento'],
        'sin dosis' => [['dosis' => ' '], 'dosis'],
        'sin frecuencia' => [['frecuencia' => ''], 'frecuencia'],
        'medicamento largo' => [['medicamento' => str_repeat('a', 151)], 'medicamento'],
        'cantidad larga' => [['cantidad' => str_repeat('a', 101)], 'cantidad'],
        'vía larga' => [['via' => str_repeat('a', 51)], 'via'],
        'observaciones largas' => [['observaciones' => str_repeat('a', 201)], 'observaciones'],
    ]);

    test('observaciones de la receta hasta 300', function () {
        hcGuardarReceta($this->consulta, [], ['observaciones' => str_repeat('a', 301)])->assertSessionHasErrors('observaciones');
    });

    test('el máximo que entra en media hoja se acepta; lo que no entra se rechaza con el aviso', function () {
        // 6 renglones de 2 líneas arriba (medicamento + cantidad = 130 caracteres) y observaciones de 3 líneas: 11 + 12 + 3 = 26.
        $justo = array_fill(0, 6, hcRenglon(['medicamento' => str_repeat('a', 70), 'cantidad' => str_repeat('c', 47), 'dosis' => 'x', 'via' => '', 'frecuencia' => 'y', 'duracion' => '', 'observaciones' => '']));
        expect(MediaHoja::lineas(array_map(fn ($r) => $r, $justo), str_repeat('o', 220), [])['receta'])->toBe(26);

        hcGuardarReceta($this->consulta, $justo, ['observaciones' => str_repeat('o', 220)])->assertSessionHasNoErrors();
        hcGuardarReceta($this->consulta, $justo, ['observaciones' => str_repeat('o', 300)])->assertSessionHasErrors(['detalles' => MediaHoja::EXCEDE]);
        hcGuardarReceta($this->consulta, array_fill(0, 6, hcRenglon(['medicamento' => str_repeat('m', 150), 'cantidad' => str_repeat('c', 100)])))
            ->assertSessionHasErrors(['detalles' => MediaHoja::EXCEDE]);
    });

    test('si las indicaciones generales de la consulta ya no entran, no se emite (y la vista previa lo avisa)', function () {
        $receta = hcReceta($this->consulta, array_fill(0, 3, hcRenglon()));
        Carbon::setTestNow(now()->addMinute());
        hcActualizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'con_indicaciones' => '1',
            'indicaciones' => array_fill(0, 10, ['id' => '', 'tipo_indicacion_id' => '', 'descripcion' => str_repeat('i', 200)])])->assertSessionHasNoErrors();

        $this->get(route('admin.recetas.vista-previa', $receta))->assertOk()->assertSee(MediaHoja::EXCEDE)->assertDontSee('Emitir e imprimir');
        hcEmitir($receta)->assertSessionHas('error', MediaHoja::EXCEDE);
        expect(estadoDe($receta))->toBe('PENDIENTE');
    });

    test('la estimación: constantes documentadas y conservadoras', function () {
        expect(MediaHoja::ANCHO_LINEA)->toBe(80)->and(MediaHoja::LINEAS_POR_MITAD)->toBe(26)
            ->and(MediaHoja::LINEAS_FIJAS_RECETA)->toBe(11)->and(MediaHoja::LINEAS_FIJAS_INDICACIONES)->toBe(7);
    });
});

describe('hoja', function () {
    test('contiene las dos mitades con todas las secciones, la línea de corte y el A4; sin RUC', function () {
        hcActualizar($this->consulta, [...hcFilasGuardadas($this->consulta), 'con_indicaciones' => '1',
            'indicaciones' => [['id' => '', 'tipo_indicacion_id' => TipoIndicacion::create(['nombre' => 'Control'])->id, 'descripcion' => 'Control en 7 días']]]);
        $receta = hcReceta($this->consulta, [], ['observaciones' => 'Volver si hay fiebre']);
        hcEmitir($receta);

        $html = $this->get(route('admin.recetas.imprimir', $receta))->assertOk()
            ->assertSeeInOrder(['Plenitud Mujer', 'Iturbe', 'Tel. 0975', 'Receta N° RE-0000001', 'Fecha: 06/10/2026',
                'Carmen Duarte', 'Ficha FP-0000001', '36 años', 'Amoxicilina 500 mg', 'Cantidad: 1 caja x 21', 'Observaciones:', 'Volver si hay fiebre',
                'Rosa Benítez', 'Mat. MP-1',
                'Cortar por la línea',
                'Indicaciones para el paciente', 'Cómo tomar su medicación', 'Amoxicilina 500 mg', 'Dosis:', '1 comprimido', 'Vía:', 'Oral',
                'Frecuencia:', 'Cada 8 horas', 'Duración:', '7 días', 'Observaciones:', 'Tomar con las comidas',
                'Indicaciones generales', 'Control:', 'Control en 7 días', 'Rosa Benítez'])
            ->assertSee('images/logo-plenitud-mujer.png')->assertSee('window.print()', false)
            ->getContent();

        expect($html)->not->toContain('RUC')
            ->toContain('@page { size: A4 portrait; margin: 0; }')->toContain('height: 148.5mm')->toContain('border-top: 1px dashed')
            ->not->toContain('overflow: hidden')->not->toContain('overflow:hidden')
            ->not->toContain('VISTA PREVIA')->not->toContain('<span>ANULADA</span>');
    });

    test('la vista previa lleva su marca y "Emitir e imprimir"; la anulada, su marca y sin imprimir', function () {
        $receta = hcReceta($this->consulta);
        $this->get(route('admin.recetas.vista-previa', $receta))->assertOk()
            ->assertSee('<span>VISTA PREVIA - NO VÁLIDA</span>', false)->assertSee('Emitir e imprimir')->assertSee('Volver a editar');

        hcEmitir($receta);
        anular($receta, 'Paciente alérgico');
        $this->get(route('admin.recetas.imprimir', $receta))->assertOk()
            ->assertSee('<span>ANULADA</span>', false)->assertSee('Motivo: Paciente alérgico')->assertDontSee('window.print()', false);
    });

    test('un borrador en "imprimir" va a la vista previa; una emitida en "vista previa" va a la hoja', function () {
        $receta = hcReceta($this->consulta);
        $this->get(route('admin.recetas.imprimir', $receta))->assertRedirect(route('admin.recetas.vista-previa', $receta));

        hcEmitir($receta);
        $this->get(route('admin.recetas.vista-previa', $receta))->assertRedirect(route('admin.recetas.imprimir', $receta));
    });

    test('no-store y nosniff en la hoja, la vista previa y el formulario', function () {
        $receta = hcReceta($this->consulta);

        foreach ([$this->get(route('admin.recetas.vista-previa', $receta)), $this->get(route('admin.recetas.edit', $receta)), $this->get(route('admin.recetas.create', $this->consulta))] as $respuesta) {
            $respuesta->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            expect($respuesta->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        }
        hcEmitir($receta);
        expect($this->get(route('admin.recetas.imprimir', $receta))->headers->get('Cache-Control'))->toContain('no-store');
    });

    test('una urgencia toma la sucursal activa más antigua; con turno, la del consultorio del turno', function () {
        $otraSucursal = Sucursal::create(['nombre' => 'Yhaguy', 'direccion' => 'Yhaguy c/ Ruta 5', 'telefono' => '0981']);
        $receta = hcReceta($this->consulta);
        $this->get(route('admin.recetas.vista-previa', $receta))->assertSee('Iturbe')->assertDontSee('Yhaguy c/ Ruta 5');

        $this->consultorio->update(['sucursal_id' => $otraSucursal->id]);
        hcGuardarNueva([], hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30']))->assertSessionHasNoErrors();
        $conTurno = Consulta::latest('id')->first();
        $this->get(route('admin.recetas.vista-previa', hcReceta($conTurno)))->assertSee('Yhaguy c/ Ruta 5')->assertSee('Plenitud Mujer');
    });
});

describe('concurrencia', function () {
    test('el segundo guardado del borrador desde una pestaña vieja se rechaza', function () {
        $receta = hcReceta($this->consulta);
        $vieja = $receta->version();

        Carbon::setTestNow(now()->addSecond());
        $this->put(route('admin.recetas.update', $receta), ['version' => $vieja, 'con_detalles' => '1', 'detalles' => [hcRenglon(['dosis' => 'Pestaña 1'])]])->assertSessionHasNoErrors();
        $this->from(route('admin.recetas.edit', $receta))
            ->put(route('admin.recetas.update', $receta), ['version' => $vieja, 'con_detalles' => '1', 'detalles' => [hcRenglon(['dosis' => 'Pestaña 2'])]])
            ->assertRedirect(route('admin.recetas.edit', $receta))->assertSessionHas('error', RecetaController::MODIFICADA_EN_OTRA_VENTANA);

        expect($receta->detalles()->sole()->dosis)->toBe('Pestaña 1');
    });
});

describe('verificaciones', function () {
    test('palabras largas sin espacios: la hoja las parte (overflow-wrap) y la estimación cuenta varias líneas, más en mayúsculas', function () {
        $receta = hcReceta($this->consulta, [hcRenglon(['medicamento' => str_repeat('a', 150), 'dosis' => str_repeat('b', 100), 'frecuencia' => str_repeat('c', 100), 'observaciones' => str_repeat('d', 200)])]);
        $html = $this->get(route('admin.recetas.vista-previa', $receta))->assertOk()->getContent();

        expect($html)->toContain('.mitad, .mitad * { overflow-wrap: anywhere; word-break: break-word; min-width: 0; }')
            ->toContain('mitad.scrollWidth > mitad.clientWidth + 1'); // el aviso de pantalla también mira el ancho

        // 150 caracteres pegados: 2 líneas en minúsculas, 3 en mayúsculas (cuentan 1,3), 4 si son W (cuentan 2).
        expect(MediaHoja::alto(str_repeat('a', 150)))->toBe(2)
            ->and(MediaHoja::alto(str_repeat('A', 150)))->toBe(3)
            ->and(MediaHoja::alto(str_repeat('W', 150)))->toBe(4)
            ->and(MediaHoja::ancho('Amoxicilina 500 mg'))->toBe(21.2); // 18 caracteres: A y 500 a 1,3; las dos m a 2

        // Medido en el navegador: dos renglones así en MAYÚSCULAS se desbordaban; ahora la estimación los rechaza.
        $mayusculas = fn (int $largo) => mb_substr(str_repeat('AMOXICILINACONACIDOCLAVULANICO', 10), 0, $largo);
        hcGuardarReceta($this->consulta, array_fill(0, 2, hcRenglon(['medicamento' => $mayusculas(150), 'cantidad' => '', 'dosis' => $mayusculas(100), 'via' => '',
            'frecuencia' => $mayusculas(100), 'duracion' => '', 'observaciones' => $mayusculas(200)])))->assertSessionHasErrors(['detalles' => MediaHoja::EXCEDE]);
    });

    test('sin VER sobre RECETAS las recetas ni siquiera se consultan en la página ni en el popup, y el historial no las trae', function () {
        $receta = hcReceta($this->consulta);
        hcEmitir($receta);
        $sinRecetas = hcConPerfil($this->otroMedico, ['HISTORIA_CLINICA' => ['VER'], 'AUDITORIA' => ['VER']]);
        $this->flushSession();
        $this->actingAs($sinRecetas);

        $pedidos = [
            fn () => $this->get(route('admin.consultas.show', $this->consulta)),
            fn () => $this->get(route('admin.consultas.detalle', $this->consulta), ['X-Requested-With' => 'XMLHttpRequest']),
        ];
        foreach ($pedidos as $pedir) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $html = $pedir()->assertOk()->getContent();
            $aRecetas = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => preg_match('/"(recetas|detalles_receta)"/', $sql));
            DB::disableQueryLog();

            expect($aRecetas)->toBeEmpty()
                ->and($html)->not->toContain('RE-0000001')->not->toContain('Amoxicilina')->not->toContain('data-receta-id')->not->toContain('Acciones de recetas');
        }

        // Historial de cambios (tiene VER sobre AUDITORIA): sin los eventos de la receta.
        $this->get(route('admin.consultas.show', $this->consulta))->assertSee('Historial de cambios')->assertDontSee('Receta RE-0000001:')->assertDontSee('medicamentos');
        $this->get(route('admin.recetas.imprimir', $receta))->assertForbidden();
        $this->get(route('admin.recetas.vista-previa', $receta))->assertForbidden();
    });

    test('con VER sobre RECETAS el popup sigue siendo idéntico al artículo de la página, y las acciones quedan fuera', function () {
        $borrador = hcReceta($this->consulta);
        $emitida = hcReceta($this->consulta);
        hcEmitir($emitida);
        $this->flushSession();

        $pagina = $this->get(route('admin.consultas.show', $this->consulta))->assertOk()->assertSee('Nueva receta')->assertSee(route('admin.recetas.edit', $borrador))->getContent();
        $popup = $this->get(route('admin.consultas.detalle', $this->consulta), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();

        expect(articuloDe($pagina))->toBe(trim($popup))->toContain('RE-0000001')->toContain('Borrador')
            ->and($popup)->not->toContain('Nueva receta')->not->toContain('admin/recetas')->not->toContain('name="motivo"')->not->toContain('Vista previa');
    });

    test('lectura: un 403 o una redirección no registran VER sobre recetas', function () {
        $receta = hcReceta($this->consulta);

        $this->get(route('admin.recetas.imprimir', $receta))->assertRedirect(route('admin.recetas.vista-previa', $receta)); // borrador
        $this->actingAs(hcConPerfil($this->otroMedico, ['HISTORIA_CLINICA' => ['VER']]));
        $this->get(route('admin.recetas.vista-previa', $receta))->assertForbidden();

        expect(LogAuditoria::where('accion', 'VER')->where('tabla_afectada', 'recetas')->count())->toBe(0);
    });

    test('el diálogo de impresión se abre solo justo después de emitir: no al volver a abrir la hoja, al recargar ni al anular', function () {
        $abreSolo = "window.addEventListener('load', () => window.print())";
        $receta = hcReceta($this->consulta);

        $this->followingRedirects()->post(route('admin.recetas.emitir', $receta), ['version' => $receta->version()])->assertOk()->assertSee($abreSolo, false);
        $this->get(route('admin.recetas.imprimir', $receta))->assertOk()->assertDontSee($abreSolo, false); // recargar / abrir desde la consulta
        $this->get(route('admin.recetas.imprimir', $receta))->assertDontSee($abreSolo, false);

        $this->followingRedirects()->post(route('admin.recetas.anular', $receta), ['motivo' => 'Ya no hace falta'])->assertOk();
        $this->get(route('admin.recetas.imprimir', $receta))->assertDontSee($abreSolo, false);

        // Una segunda emisión (doble clic) tampoco lo abre de nuevo.
        $otra = hcReceta($this->consulta);
        $version = $otra->version();
        hcEmitir($otra, $version);
        $this->flushSession();
        $this->followingRedirects()->post(route('admin.recetas.emitir', $otra), ['version' => $version])->assertSee('La receta ya estaba emitida.')->assertDontSee($abreSolo, false);
    });

    test('tope de recetas: la undécima sin anular se rechaza; las anuladas no cuentan, tampoco al corregir', function () {
        $recetas = collect(range(1, RecetaController::MAXIMO_RECETAS))->map(fn () => hcReceta($this->consulta));
        hcGuardarReceta($this->consulta)->assertForbidden();
        $this->get(route('admin.consultas.show', $this->consulta))->assertDontSee('Nueva receta');

        // "Anular y corregir" con 10: anula una y crea su reemplazo (siguen siendo 10 sin anular).
        hcEmitir($recetas->first());
        $this->post(route('admin.recetas.corregir', $recetas->first()), ['motivo' => 'Corregir la dosis'])->assertRedirect();
        expect(Receta::where('estado_id', '!=', Estado::idDe(Estado::ANULADO))->count())->toBe(10)
            ->and(Receta::count())->toBe(11);
        hcGuardarReceta($this->consulta)->assertForbidden();

        anular($recetas->last(), 'Ya no hace falta');
        hcGuardarReceta($this->consulta)->assertRedirect();
        expect(Receta::where('estado_id', '!=', Estado::idDe(Estado::ANULADO))->count())->toBe(10);
    });
});
