<?php

/*
 * Guardado del triaje: confirmación de los valores muy alterados (autoguardado con 422 requiere_confirmacion,
 * Finalizar y sin JavaScript), signos_alertas calculado al guardar y congelado con la consulta cerrada, y el
 * urgente con la observación (quién y cuándo, auditoría, congelado). Rangos de prueba inventados. Escenario:
 * escenario.php (martes 06/10/2026 09:00).
 */

use App\Exceptions\ConsultaCerrada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\ParametroClinico;
use App\Models\User;
use App\Support\Atencion\SignosVitales;

beforeEach(function () {
    hcEscenario();
    // Temperatura (rangos de prueba): muy alterado fuera de 35-40, normal 36-37,5. Aprobado.
    ParametroClinico::where('codigo', 'TEMPERATURA')->sole()->forceFill([
        'muy_alterado_min' => 35, 'muy_alterado_max' => 40, 'normal_min' => 36, 'normal_max' => 37.5,
        'estado_id' => Estado::idDe(Estado::APROBADO), 'aprobado_en' => now(),
    ])->save();
    $this->enfermera = User::factory()->conPermisos(['PREPARACION' => ['VER', 'CREAR', 'EDITAR']])->personalDeSalud()
        ->conPersona(['apellidos' => 'Ortiz', 'nombres' => 'Nidia'])->create();
    $this->actingAs($this->enfermera)->post(route('admin.preparacion.preparar', hcTurno()));
    $this->consulta = Consulta::sole();
});

function guardadoTriajeExamen(Consulta $consulta): array
{
    return $consulta->fresh()->examenFisico?->only(['temperatura', 'frecuencia_cardiaca', 'signos_alertas']) ?? [];
}

describe('confirmación de los valores muy alterados', function () {
    test('autoguardado: 422 requiere_confirmacion; el valor no se guarda y los demás campos sí', function () {
        $respuesta = hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '41,5', 'frecuencia_cardiaca' => '88']])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'requiere_confirmacion')
            ->assertJsonPath('campos.temperatura.valor', '41.5')
            ->assertJsonPath('campos.temperatura.mensaje', 'Valor muy alto: 41,5 °C. ¿Está seguro de que es correcto?');

        expect(guardadoTriajeExamen($this->consulta))->temperatura->toBeNull()->frecuencia_cardiaca->toBe(88)
            // La versión nueva viene en la respuesta (lo demás se guardó).
            ->and($respuesta->json('version'))->toBe($this->consulta->fresh()->version());
    });

    test('con la confirmación EXACTA se guarda, con quién y cuándo; con otro valor, no', function () {
        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '41,5'], 'confirmaciones' => ['temperatura' => '41,4']])->assertStatus(422);
        expect(guardadoTriajeExamen($this->consulta)['temperatura'] ?? null)->toBeNull();

        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '41,5'], 'confirmaciones' => ['temperatura' => '41,5']])->assertOk();

        $examen = guardadoTriajeExamen($this->consulta);
        expect($examen['temperatura'])->toBe('41.5')
            ->and($examen['signos_alertas']['temperatura'])->toMatchArray(['nivel' => 'MUY_ALTERADO', 'direccion' => 'alto', 'valor' => '41.5',
                'confirmado_valor' => '41.5', 'confirmado_por' => $this->enfermera->id]);

        // Volver a mandar el mismo valor ya confirmado no lo vuelve a pedir.
        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '41,5', 'frecuencia_cardiaca' => '90']])->assertOk();
    });

    test('la confirmación queda en la auditoría del EDITAR', function () {
        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '41,5'], 'confirmaciones' => ['temperatura' => '41,5']])->assertOk();

        $evento = LogAuditoria::where('tabla_afectada', 'consultas')->where('accion', 'EDITAR')->latest('id')->first();
        expect($evento->valor_nuevo['examenFisico'])->toMatchArray(['Confirmación: Temperatura' => 'Confirmó valor muy alto: Temperatura 41,5 °C'])
            ->and($evento->usuario_id)->toBe($this->enfermera->id);
    });

    test('fuera de lo normal se guarda sin confirmación y se marca', function () {
        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '38']])->assertOk();

        expect(guardadoTriajeExamen($this->consulta)['signos_alertas'])->toBe(['temperatura' => ['nivel' => 'FUERA_DE_RANGO', 'direccion' => 'alto', 'valor' => '38']]);
    });

    test('un valor imposible se rechaza con 422 y no se guarda nada', function () {
        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '46', 'frecuencia_cardiaca' => '88']])
            ->assertStatus(422)->assertJsonValidationErrors(['examen.temperatura' => 'El valor de TEMPERATURA no es posible.']);

        expect(guardadoTriajeExamen($this->consulta))->toBe([]);
    });

    test('sin JavaScript: vuelve con el error y una casilla por campo; marcándola, se guarda', function () {
        $formulario = route('admin.preparacion.formulario', $this->consulta);
        $this->from($formulario)->post(route('admin.preparacion.guardar', $this->consulta), [
            'version' => $this->consulta->version(), 'examen' => ['temperatura' => '41,5', 'frecuencia_cardiaca' => '88'],
        ])->assertRedirect($formulario)
            ->assertSessionHasErrors(['confirmaciones.temperatura' => 'Valor muy alto: 41,5 °C. ¿Está seguro de que es correcto?'])
            ->assertSessionHas('error', SignosVitales::MENSAJE);
        expect(guardadoTriajeExamen($this->consulta))->temperatura->toBeNull()->frecuencia_cardiaca->toBe(88);

        // La pantalla muestra el aviso y la casilla con el valor escrito.
        $this->get($formulario)->assertOk()->assertSee('Valor muy alto: 41,5 °C')
            ->assertSee('name="confirmaciones[temperatura]" value="41.5"', false)->assertSee('Sí, es correcto');

        $this->post(route('admin.preparacion.guardar', $this->consulta), [
            'version' => $this->consulta->fresh()->version(), 'examen' => ['temperatura' => '41.5'], 'confirmaciones' => ['temperatura' => '41.5'],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Triaje guardado.');
        expect(guardadoTriajeExamen($this->consulta)['temperatura'])->toBe('41.5');
    });

    test('Finalizar con un muy alterado sin confirmar no guarda nada y pide la casilla', function () {
        $this->actingAs($this->medico)->post(route('admin.atencion.atender', $this->consulta->turno));
        $consulta = $this->consulta->fresh();

        hcFinalizar($consulta, hcDatos(['examen' => ['temperatura' => '41,5']]))
            ->assertSessionHasErrors(['confirmaciones.temperatura' => 'Valor muy alto: 41,5 °C. ¿Está seguro de que es correcto?']);
        expect($consulta->fresh()->enCurso())->toBeTrue()->and(guardadoTriajeExamen($consulta))->toBe([]);

        hcFinalizar($consulta, [...hcDatos(['examen' => ['temperatura' => '41,5']]), 'confirmaciones' => ['temperatura' => '41,5']])->assertSessionHasNoErrors();
        expect($consulta->fresh()->finalizada())->toBeTrue()
            ->and(guardadoTriajeExamen($consulta)['signos_alertas']['temperatura']['confirmado_por'])->toBe($this->medico->id);
    });
});

describe('signos_alertas: al guardar y congelado al cerrar', function () {
    test('se recalcula en cada guardado mientras la consulta está abierta', function () {
        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '38']])->assertOk();
        expect(guardadoTriajeExamen($this->consulta)['signos_alertas']['temperatura']['nivel'])->toBe('FUERA_DE_RANGO');

        // Cambian los rangos y se vuelven a aprobar (38 pasa a ser normal; 39 sigue fuera): el próximo guardado,
        // con la consulta abierta, lo recalcula.
        $temperatura = ParametroClinico::where('codigo', 'TEMPERATURA')->sole();
        $temperatura->update(['normal_max' => 38.5]);
        expect($temperatura->fresh()->aprobado())->toBeFalse(); // editar lo devolvió a PENDIENTE
        $temperatura->fresh()->aprobar($this->medico);
        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '38', 'frecuencia_cardiaca' => '80']])->assertOk();
        expect(guardadoTriajeExamen($this->consulta)['signos_alertas'])->toBeNull();

        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '39', 'frecuencia_cardiaca' => '80']])->assertOk();
        expect(guardadoTriajeExamen($this->consulta)['signos_alertas']['temperatura']['nivel'])->toBe('FUERA_DE_RANGO');
    });

    test('con la consulta cerrada el examen (y sus alertas) no se escribe más', function () {
        hcAutoguardar($this->consulta, ['examen' => ['temperatura' => '38']])->assertOk();
        $this->actingAs($this->medico)->post(route('admin.atencion.atender', $this->consulta->turno));
        hcFinalizar($this->consulta->fresh(), hcDatos(['examen' => ['temperatura' => '38']]))->assertSessionHasNoErrors();
        $congelado = guardadoTriajeExamen($this->consulta)['signos_alertas'];

        // Los rangos cambian después (y se aprueban): la historia cerrada no se reescribe (ni se puede, ProtegidoPorCierre).
        $temperatura = ParametroClinico::where('codigo', 'TEMPERATURA')->sole();
        $temperatura->update(['normal_max' => 39.5]);
        $temperatura->fresh()->aprobar($this->medico);
        expect(guardadoTriajeExamen($this->consulta)['signos_alertas'])->toBe($congelado)
            ->and(fn () => $this->consulta->fresh()->examenFisico->update(['signos_alertas' => null]))->toThrow(ConsultaCerrada::class);
    });
});

describe('urgente y observación', function () {
    test('se marca y se desmarca con quién y cuándo; queda en la auditoría', function () {
        hcAutoguardar($this->consulta, ['con_triaje' => '1', 'triaje_urgente' => '1', 'triaje_observacion' => 'Dolor torácico desde hace una hora.'])->assertOk();
        $consulta = $this->consulta->fresh();
        expect($consulta)->triaje_urgente->toBeTrue()->triaje_observacion->toBe('Dolor torácico desde hace una hora.')
            ->triaje_urgente_por->toBe($this->enfermera->id)
            ->and($consulta->triaje_urgente_en->equalTo(now()))->toBeTrue();

        hcAutoguardar($consulta, ['con_triaje' => '1', 'triaje_urgente' => '0', 'triaje_observacion' => 'Dolor torácico desde hace una hora.'])->assertOk();
        expect($consulta->fresh())->triaje_urgente->toBeFalse()->triaje_urgente_por->toBeNull()->triaje_urgente_en->toBeNull();

        $cambios = LogAuditoria::where('tabla_afectada', 'consultas')->where('accion', 'EDITAR')->orderBy('id')->get()
            ->map(fn ($e) => $e->valor_nuevo['triaje_urgente'] ?? null)->filter(fn ($v) => $v !== null)->values()->all();
        expect($cambios)->toBe([true, false]);
    });

    test('un pedido sin el bloque (sin con_triaje) no borra lo marcado', function () {
        hcAutoguardar($this->consulta, ['con_triaje' => '1', 'triaje_urgente' => '1', 'triaje_observacion' => 'Mareos.'])->assertOk();
        hcAutoguardar($this->consulta, ['examen' => ['frecuencia_cardiaca' => '90']])->assertOk();

        expect($this->consulta->fresh())->triaje_urgente->toBeTrue()->triaje_observacion->toBe('Mareos.');
    });

    test('la observación tiene hasta 1000 caracteres; sirve sin urgente', function () {
        hcAutoguardar($this->consulta, ['con_triaje' => '1', 'triaje_observacion' => str_repeat('a', 1001)])
            ->assertStatus(422)->assertJsonValidationErrors('triaje_observacion');
        hcAutoguardar($this->consulta, ['con_triaje' => '1', 'triaje_observacion' => 'Viene acompañada.'])->assertOk();

        expect($this->consulta->fresh())->triaje_urgente->toBeFalse()->triaje_observacion->toBe('Viene acompañada.');
    });

    test('el profesional de la consulta EN_CURSO también los edita; con la consulta cerrada, nadie', function () {
        $this->actingAs($this->medico)->post(route('admin.atencion.atender', $this->consulta->turno));
        hcAutoguardar($this->consulta->fresh(), ['con_triaje' => '1', 'triaje_urgente' => '1'])->assertOk();
        expect($this->consulta->fresh()->triaje_urgente_por)->toBe($this->medico->id);

        hcFinalizar($this->consulta->fresh(), [...hcDatos(), 'con_triaje' => '1', 'triaje_urgente' => '1'])->assertSessionHasNoErrors();
        hcAutoguardar($this->consulta->fresh(), ['con_triaje' => '1', 'triaje_urgente' => '0'])->assertStatus(409);
        expect(fn () => $this->consulta->fresh()->update(['triaje_urgente' => false]))->toThrow(ConsultaCerrada::class)
            ->and($this->consulta->fresh()->triaje_urgente)->toBeTrue();
    });
});
