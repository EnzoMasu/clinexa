<?php

/*
 * Una paciente con al menos una consulta FINALIZADA (cerrada) no pasa a INACTIVO por ningún camino
 * (formulario, botón Desactivar, modelo), ni tampoco su Persona. Las consultas EN_PREPARACION, EN_CURSO o
 * ANULADA no bloquean. Reactivar y corregir los datos de identidad y contacto, como siempre (con su
 * auditoría). La regla está en un solo lugar: Paciente::tieneConsultasCerradas (y el updating del modelo).
 * Datos inventados (escenario.php).
 */

use App\Exceptions\PacienteConConsultasCerradas;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\User;

beforeEach(function () {
    hcEscenario();
    $this->admin = User::factory()->administrador()->create();
    // El formulario de Personas acepta el tipo de documento de la paciente (tipo_documento_modulo).
    ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento([$this->paciente->persona->tipoDocumento->codigo]);
});

/** Lo que manda el formulario de Paciente con el estado elegido. */
function formularioPaciente(Paciente $paciente, string $estado): array
{
    return ['nro_ficha' => $paciente->nro_ficha, 'estado_id' => Estado::idDe($estado)];
}

/** Lo que manda el formulario de Persona (los datos actuales, con cambios). */
function formularioPersona(Persona $persona, array $cambios = []): array
{
    return [
        'tipo_persona' => $persona->tipo_persona, 'tipo_documento_id' => $persona->tipo_documento_id, 'nro_documento' => $persona->nro_documento,
        'apellidos' => $persona->apellidos, 'nombres' => $persona->nombres, 'fecha_nacimiento' => $persona->fecha_nacimiento?->format('d/m/Y'),
        'sexo' => $persona->sexo, 'email' => $persona->email, 'telefono' => $persona->telefono, 'direccion' => $persona->direccion,
        'estado_id' => $persona->estado_id, ...$cambios,
    ];
}

function estadoDelRegistro($modelo): string
{
    return $modelo->fresh()->estado->codigo;
}

describe('con una consulta FINALIZADA', function () {
    beforeEach(fn () => $this->consulta = hcConsulta());

    test('el formulario de Paciente no la deja pasar a INACTIVO: vuelve con el aviso y no cambia nada', function () {
        $this->actingAs($this->admin)->from(route('admin.pacientes.edit', $this->paciente))
            ->put(route('admin.pacientes.update', $this->paciente), formularioPaciente($this->paciente, Estado::INACTIVO))
            ->assertRedirect(route('admin.pacientes.edit', $this->paciente))
            ->assertSessionHas('error', PacienteConConsultasCerradas::MENSAJE)
            ->assertSessionHasErrors(['estado_id' => PacienteConConsultasCerradas::MENSAJE]);

        expect(estadoDelRegistro($this->paciente))->toBe(Estado::ACTIVO);
    });

    test('el botón Desactivar del listado tampoco', function () {
        $this->actingAs($this->admin)->from(route('admin.pacientes.index'))
            ->patch(route('admin.pacientes.desactivar', $this->paciente))
            ->assertRedirect(route('admin.pacientes.index'))->assertSessionHas('error', PacienteConConsultasCerradas::MENSAJE);

        $this->get(route('admin.pacientes.index'))->assertSee(PacienteConConsultasCerradas::MENSAJE);
        expect(estadoDelRegistro($this->paciente))->toBe(Estado::ACTIVO);
    });

    test('desde el modelo (servicio, tinker): ni update ni desactivar()', function () {
        expect(fn () => $this->paciente->fresh()->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]))->toThrow(PacienteConConsultasCerradas::class, PacienteConConsultasCerradas::MENSAJE)
            ->and(fn () => $this->paciente->fresh()->desactivar())->toThrow(PacienteConConsultasCerradas::class);
        expect(estadoDelRegistro($this->paciente))->toBe(Estado::ACTIVO);
    });

    test('su Persona tampoco se desactiva: ni por el formulario, ni por el botón, ni por el modelo', function () {
        $persona = $this->paciente->persona;
        $this->actingAs($this->admin);

        $this->from(route('admin.personas.edit', $persona))
            ->put(route('admin.personas.update', $persona), formularioPersona($persona, ['estado_id' => Estado::idDe(Estado::INACTIVO)]))
            ->assertSessionHas('error', PacienteConConsultasCerradas::MENSAJE);
        $this->from(route('admin.personas.index'))->patch(route('admin.personas.desactivar', $persona))
            ->assertSessionHas('error', PacienteConConsultasCerradas::MENSAJE);
        expect(fn () => $persona->fresh()->desactivar())->toThrow(PacienteConConsultasCerradas::class);

        expect(estadoDelRegistro($persona))->toBe(Estado::ACTIVO);
    });

    test('corregir sus datos de identidad y contacto sigue funcionando y deja su evento de auditoría', function () {
        $persona = $this->paciente->persona;
        $this->actingAs($this->admin)->from(route('admin.personas.edit', $persona))
            ->put(route('admin.personas.update', $persona), formularioPersona($persona, [
                'apellidos' => 'Duarte Rojas', 'nombres' => 'Carmen Liz', 'telefono' => '0981 222 333',
                'email' => 'carmen.nueva@clinexa.test', 'direccion' => 'Mcal. López 1234',
            ]))->assertSessionHasNoErrors()->assertSessionHas('status');

        $persona->refresh();
        expect([$persona->apellidos, $persona->nombres, $persona->telefono, $persona->email, $persona->direccion])
            ->toBe(['Duarte Rojas', 'Carmen Liz', '0981 222 333', 'carmen.nueva@clinexa.test', 'Mcal. López 1234']);
        $evento = LogAuditoria::where('tabla_afectada', 'personas')->where('registro_afectado_id', (string) $persona->id)->where('accion', 'EDITAR')->latest('id')->first();
        expect($evento->valor_nuevo)->toMatchArray(['apellidos' => 'Duarte Rojas', 'telefono' => '0981 222 333'])
            ->and($evento->usuario_id)->toBe($this->admin->id);

        // Y el número de ficha del rol de paciente también se corrige (sin tocar el estado).
        $this->put(route('admin.pacientes.update', $this->paciente), ['nro_ficha' => 'FP-0000099', 'estado_id' => Estado::idDe(Estado::ACTIVO)])->assertSessionHasNoErrors();
        expect($this->paciente->fresh()->nro_ficha)->toBe('FP-0000099');
    });
});

describe('sin consultas cerradas', function () {
    test('sin consultas: se desactiva y se reactiva como siempre', function () {
        $this->actingAs($this->admin);
        $this->put(route('admin.pacientes.update', $this->paciente), formularioPaciente($this->paciente, Estado::INACTIVO))->assertSessionHasNoErrors();
        expect(estadoDelRegistro($this->paciente))->toBe(Estado::INACTIVO);

        $this->put(route('admin.pacientes.update', $this->paciente), formularioPaciente($this->paciente, Estado::ACTIVO))->assertSessionHasNoErrors();
        expect(estadoDelRegistro($this->paciente))->toBe(Estado::ACTIVO);
    });

    test('con consultas EN_PREPARACION, EN_CURSO o ANULADA no se bloquea', function (string $estado) {
        match ($estado) {
            'EN_PREPARACION' => $this->post(route('admin.preparacion.preparar', hcTurno())),
            'EN_CURSO' => hcEnCurso(),
            'ANULADO' => $this->post(route('admin.consultas.deshacer', hcEnCurso())),
        };
        expect($this->paciente->tieneConsultasCerradas())->toBeFalse();

        $this->paciente->fresh()->desactivar();
        expect(estadoDelRegistro($this->paciente))->toBe(Estado::INACTIVO);
    })->with(['EN_PREPARACION', 'EN_CURSO', 'ANULADO']);

    test('la Persona de una paciente sin consultas cerradas se desactiva como siempre', function () {
        $this->paciente->persona->fresh()->desactivar();
        expect(estadoDelRegistro($this->paciente->persona))->toBe(Estado::INACTIVO);
    });
});

test('reactivar una paciente inactiva que ya tenía consultas cerradas sí se puede', function () {
    // Datos viejos (anteriores a la regla): inactiva con una consulta finalizada. Reactivarla no está prohibido.
    $consulta = hcConsulta();
    \Illuminate\Support\Facades\DB::table('pacientes')->where('id', $this->paciente->id)->update(['estado_id' => Estado::idDe(Estado::INACTIVO)]);

    $this->actingAs($this->admin)->put(route('admin.pacientes.update', $this->paciente), formularioPaciente($this->paciente, Estado::ACTIVO))->assertSessionHasNoErrors();
    expect(estadoDelRegistro($this->paciente))->toBe(Estado::ACTIVO);
});
