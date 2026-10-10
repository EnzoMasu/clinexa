<?php

/*
 * Rutas compartidas (permiso.alguno) y la regla fina por grupo:
 * - PREPARACIÓN (anamnesis y signos vitales): el profesional de la consulta o quien tiene EDITAR sobre
 *   PREPARACION, con la consulta EN_PREPARACION o EN_CURSO.
 * - CLÍNICO (motivo, hallazgos, diagnósticos, indicaciones, recetas): solo el profesional, EN_CURSO.
 * Quien solo tiene PREPARACION no ve ni manda nada clínico: ni en el HTML ni en el servidor.
 * Recetas: solo con la consulta EN_CURSO o FINALIZADA; anular una emitida, siempre.
 */

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Receta;
use App\Models\TipoIndicacion;
use App\Models\User;

const PERMISOS_ENFERMERIA = ['PREPARACION' => ['VER', 'CREAR', 'EDITAR']];

beforeEach(function () {
    hcEscenario();
    $this->enfermera = User::factory()->conPermisos(PERMISOS_ENFERMERIA)->conPersona(['apellidos' => 'Ortiz', 'nombres' => 'Nidia'])->create();
    $this->reposo = TipoIndicacion::create(['nombre' => 'Reposo']);
});

/** Lo que una persona de preparación carga: anamnesis y signos vitales. */
function datosPreparacion(string $contenido = 'Refiere fiebre de 2 días.'): array
{
    return [
        'con_anamnesis' => '1',
        'anamnesis' => [['uid' => 'n-1', 'id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => test()->enfermedadActual->id, 'contenido' => $contenido]],
        'examen' => ['presion_arterial' => '110/70', 'temperatura' => '37,9'],
    ];
}

/** Lo clínico que carga el profesional, con textos fáciles de buscar en el HTML. */
function datosClinicos(): array
{
    return [
        'motivo_consulta' => 'MOTIVO-SECRETO de la consulta',
        'examen' => ['presion_arterial' => '110/70', 'hallazgos' => 'HALLAZGO-SECRETO en faringe'],
        'con_diagnosticos' => '1',
        'diagnosticos' => [['uid' => 'n-2', 'id' => '', 'activo' => '1', 'codigo_cie10' => 'R51', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => 'DIAGNOSTICO-SECRETO']],
        'diagnostico_principal' => '0',
        'con_indicaciones' => '1',
        'indicaciones' => [['uid' => 'n-3', 'id' => '', 'activo' => '1', 'tipo_indicacion_id' => test()->reposo->id, 'descripcion' => 'INDICACION-SECRETA']],
    ];
}

/** Una consulta EN_CURSO del turno de hoy, con contenido clínico, y una consulta anterior finalizada. */
function consultaConContenidoClinico(): Consulta
{
    hcConsulta(['motivo_consulta' => 'MOTIVO-ANTERIOR-SECRETO']);
    $consulta = hcEnCurso(hcTurno());
    hcAutoguardar($consulta, [...datosPreparacion('ANAMNESIS-VISIBLE'), ...datosClinicos()])->assertOk();

    return $consulta->fresh();
}

describe('preparación (solo PREPARACION)', function () {
    test('prepara un turno de hoy y carga anamnesis y signos: queda a su nombre', function () {
        $turno = hcTurno();
        $this->actingAs($this->enfermera)->post(route('admin.preparacion.preparar', $turno))->assertRedirect();
        $consulta = Consulta::where('turno_id', $turno->id)->sole();
        expect($consulta->enPreparacion())->toBeTrue();

        hcAutoguardar($consulta, datosPreparacion())->assertOk();

        $bloque = $consulta->bloquesAnamnesis()->sole();
        expect($bloque->contenido)->toBe('Refiere fiebre de 2 días.')
            ->and($bloque->usuario_id)->toBe($this->enfermera->id)
            ->and((float) $consulta->examenFisico->temperatura)->toBe(37.9)
            ->and($consulta->examenFisico->signos_usuario_id)->toBe($this->enfermera->id);
    });

    test('el formulario de preparación no trae ningún campo ni dato clínico, ni con la consulta en curso', function () {
        $consulta = consultaConContenidoClinico();

        $html = $this->actingAs($this->enfermera)->get(route('admin.preparacion.formulario', $consulta))->assertOk()->getContent();

        expect($html)->toContain('ANAMNESIS-VISIBLE')
            ->not->toContain('name="motivo_consulta"')->not->toContain('examen[hallazgos]')
            ->not->toContain('diagnosticos[')->not->toContain('indicaciones[')
            ->not->toContain('SECRETO')->not->toContain('SECRETA')
            ->not->toContain('Historial del paciente')->not->toContain('Nueva receta');
    });

    test('mandar cualquier campo clínico por el autoguardado: 403 y no se guarda nada', function (string $campo) {
        $turno = hcTurno();
        $this->actingAs($this->enfermera)->post(route('admin.preparacion.preparar', $turno));
        $consulta = Consulta::where('turno_id', $turno->id)->sole();
        $clinico = datosClinicos();
        $pedido = match ($campo) {
            'hallazgos' => ['examen' => ['hallazgos' => 'x']],
            default => [$campo => $clinico[$campo]],
        };

        hcAutoguardar($consulta, [...datosPreparacion(), ...$pedido])->assertForbidden();

        expect($consulta->bloquesAnamnesis()->count())->toBe(0)
            ->and($consulta->examenFisico()->exists())->toBeFalse()
            ->and($consulta->fresh()->motivo_consulta)->toBeNull();
    })->with(['motivo_consulta', 'hallazgos', 'diagnosticos', 'indicaciones', 'con_diagnosticos', 'diagnostico_principal']);

    test('en una consulta en curso puede seguir con la preparación, pero no tocar lo clínico', function () {
        $consulta = consultaConContenidoClinico();

        $this->actingAs($this->enfermera);
        hcAutoguardar($consulta, ['examen' => ['presion_arterial' => '130/85']])->assertOk();
        hcAutoguardar($consulta, ['motivo_consulta' => 'cambiado'])->assertForbidden();

        $consulta->refresh();
        expect($consulta->examenFisico->presion_arterial)->toBe('130/85')
            ->and($consulta->motivo_consulta)->toBe('MOTIVO-SECRETO de la consulta')
            ->and($consulta->examenFisico->hallazgos)->toBe('HALLAZGO-SECRETO en faringe');
    });

    test('no entra a nada clínico: atención, lectura, popup, historia, finalizar, deshacer, recetas', function () {
        $consulta = consultaConContenidoClinico();
        $this->actingAs($this->enfermera);

        $this->get(route('admin.consultas.atencion', $consulta))->assertForbidden();
        $this->get(route('admin.consultas.show', $consulta))->assertForbidden();
        $this->get(route('admin.consultas.detalle', $consulta), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
        $this->get(route('admin.historias-clinicas.show', $consulta->historia_clinica_id))->assertForbidden();
        $this->get(route('admin.atencion.index'))->assertForbidden();
        $this->post(route('admin.consultas.finalizar', $consulta), [...datosClinicos(), 'version' => $consulta->version()])->assertForbidden();
        $this->post(route('admin.consultas.deshacer', $consulta))->assertForbidden();
        $this->get(route('admin.recetas.create', $consulta))->assertForbidden();
        $this->get(route('admin.historias-clinicas.cie10', ['q' => 'R51']))->assertForbidden();

        expect($consulta->fresh()->enCurso())->toBeTrue();
    });

    test('la lista de Preparación muestra solo datos de agenda', function () {
        consultaConContenidoClinico();

        $html = $this->actingAs($this->enfermera)->get(route('admin.preparacion.index'))->assertOk()->getContent();

        expect($html)->toContain('Duarte, Carmen')->toContain('Benítez, Rosa')
            ->not->toContain('SECRETO')->not->toContain('SECRETA')->not->toContain('ANAMNESIS-VISIBLE');
    });

    test('con la consulta finalizada ya no escribe: el autoguardado da 409 y el formulario manda a la lectura', function () {
        $consulta = consultaConContenidoClinico();
        hcFinalizar($consulta, [...hcFilasGuardadas($consulta), 'con_indicaciones' => '1'])->assertSessionHasNoErrors();
        expect($consulta->fresh()->finalizada())->toBeTrue();

        $this->actingAs($this->enfermera);
        hcAutoguardar($consulta, datosPreparacion())->assertStatus(409);
        $this->get(route('admin.preparacion.formulario', $consulta))->assertRedirect(route('admin.consultas.show', $consulta));
    });
});

describe('el profesional', function () {
    test('con la consulta en preparación, lo clínico todavía no se escribe (403)', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno))->assertRedirect();
        $consulta = Consulta::where('turno_id', $turno->id)->sole();

        hcAutoguardar($consulta, datosPreparacion())->assertOk();
        hcAutoguardar($consulta, ['motivo_consulta' => 'todavía no'])->assertForbidden();
        expect($consulta->fresh()->motivo_consulta)->toBeNull();
    });

    test('otro profesional (con CREAR y EDITAR) no escribe en una consulta ajena: ni preparación ni clínico', function () {
        $consulta = hcEnCurso();

        $this->actingAs($this->otroMedico);
        hcAutoguardar($consulta, datosPreparacion())->assertForbidden();
        hcAutoguardar($consulta, ['motivo_consulta' => 'ajeno'])->assertForbidden();
        $this->get(route('admin.consultas.atencion', $consulta))->assertForbidden();
        expect($consulta->bloquesAnamnesis()->count())->toBe(0);
    });
});

describe('recetas según el estado de la consulta', function () {
    beforeEach(function () {
        $this->medico = hcDarPermisos($this->medico, HC_PERMISOS_RECETAS);
        $this->actingAs($this->medico);
    });

    test('en preparación: no se crean (403 con el aviso)', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno));
        $consulta = Consulta::where('turno_id', $turno->id)->sole();

        $this->get(route('admin.recetas.create', $consulta))->assertForbidden()->assertSee(\App\Policies\RecetaPolicy::SOLO_EN_CURSO);
        hcGuardarReceta($consulta)->assertForbidden();
        expect(Receta::count())->toBe(0);
    });

    test('en curso y finalizada: se crean, y una emitida se anula en cualquiera de los dos', function () {
        $consulta = hcEnCurso();
        $enCurso = hcReceta($consulta);
        hcEmitir($enCurso)->assertSessionHasNoErrors();
        $this->post(route('admin.recetas.anular', $enCurso), ['motivo' => 'Dosis equivocada'])->assertSessionHasNoErrors();
        expect($enCurso->fresh()->estaAnulada())->toBeTrue();

        hcFinalizar($consulta, hcDatos())->assertSessionHasNoErrors();
        expect($consulta->fresh()->finalizada())->toBeTrue();

        $finalizada = hcReceta($consulta);
        hcEmitir($finalizada)->assertSessionHasNoErrors();
        $this->post(route('admin.recetas.anular', $finalizada), ['motivo' => 'Paciente alérgico'])->assertSessionHasNoErrors();
        expect($finalizada->fresh()->tieneEstado(Estado::ANULADO))->toBeTrue();
    });
});
