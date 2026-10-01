<?php

use App\Models\Especialidad;
use App\Models\ModuloSistema;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\TipoDocumento;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
    TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
    ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI']);
});

afterEach(fn () => Carbon::setTestNow());

function personaFisica(array $cambios = []): array
{
    return [
        'tipo_persona' => 'FISICA', 'tipo_documento_id' => TipoDocumento::where('codigo', 'CI')->value('id'),
        'nro_documento' => '7777777', 'apellidos' => 'Benítez', 'nombres' => 'Lucía', 'fecha_nacimiento' => '10/05/1990',
        'email' => 'lucia@example.com', 'telefono' => '0981 000 000', 'direccion' => 'Concepción', ...$cambios,
    ];
}

test('ninguna pantalla usa el input de fecha nativo del navegador', function () {
    $vistas = collect(File::allFiles(resource_path('views')))->filter(fn ($archivo) => str_contains($archivo->getContents(), 'type="date"'));

    expect($vistas->map->getRelativePathname()->all())->toBe([]);
});

describe('persona: fecha de nacimiento', function () {
    test('se muestra en dd/mm/aaaa al editar, con el campo de calendario', function () {
        $persona = Persona::factory()->create(['fecha_nacimiento' => '1990-05-10']);

        $this->get(route('admin.personas.edit', $persona))->assertOk()
            ->assertSee('value="10/05/1990"', false)
            ->assertSee('placeholder="dd/mm/aaaa"', false)
            ->assertSee('x-data="campoFecha(', false)
            ->assertDontSee('1990-05-10');
    });

    test('se carga en dd/mm/aaaa y se guarda como fecha', function () {
        $this->post(route('admin.personas.store'), personaFisica())->assertSessionHasNoErrors();

        expect(Persona::where('nro_documento', '7777777')->sole()->fecha_nacimiento->format('Y-m-d'))->toBe('1990-05-10');
    });

    test('rechaza otros formatos y fechas inexistentes con un mensaje claro', function (string $fecha) {
        $this->post(route('admin.personas.store'), personaFisica(['fecha_nacimiento' => $fecha]))
            ->assertSessionHasErrors(['fecha_nacimiento' => 'El campo fecha de nacimiento debe tener el formato dd/mm/aaaa (por ejemplo, 05/03/1990).']);
    })->with(['ISO' => '1990-05-10', 'mes/día' => '05/31/1990', 'sin ceros' => '1/5/1990', '31 de febrero' => '31/02/1990']);

    test('tras un error se vuelve a mostrar lo que se escribió', function () {
        $this->from(route('admin.personas.create'))->post(route('admin.personas.store'), personaFisica(['fecha_nacimiento' => '10/05/1990', 'email' => '']));

        $this->get(route('admin.personas.create'))->assertSee('value="10/05/1990"', false);
    });

    test('"hoy" es el día de Paraguay (UTC-3), no el de UTC', function () {
        // 01/10/2026 23:30 en Paraguay = 02/10/2026 02:30 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-02 02:30:00', 'UTC'));

        $this->post(route('admin.personas.store'), personaFisica(['fecha_nacimiento' => '02/10/2026']))
            ->assertSessionHasErrors(['fecha_nacimiento' => 'El campo fecha de nacimiento no puede ser posterior a hoy.']);
        $this->post(route('admin.personas.store'), personaFisica(['fecha_nacimiento' => '01/10/2026']))
            ->assertSessionHasNoErrors();
    });
});

describe('profesional: fecha desde de cada especialidad', function () {
    beforeEach(function () {
        $this->gineco = Especialidad::create(['nombre' => 'Ginecología y Obstetricia']);
        $this->profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);
        $this->profesional->especialidades()->attach($this->gineco->id, ['fecha_desde' => '2015-03-01']);
    });

    test('las existentes se muestran en dd/mm/aaaa y una nueva propone hoy (hora de Paraguay)', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-02 02:30:00', 'UTC')); // 01/10/2026 23:30 en Paraguay

        $html = $this->get(route('admin.profesionales.edit', $this->profesional))->assertOk()->getContent();

        // Filas iniciales (JSON dentro del x-data, con las barras escapadas): 01/03/2015, no ISO.
        preg_match("/filas: JSON\\.parse\\('(.*?)'\\)/", $html, $filas);
        $filas = json_decode(json_decode('"'.$filas[1].'"'), true); // string JS -> JSON -> array
        expect($filas[0]['fecha_desde'])->toBe('01/03/2015');

        expect($html)->not->toContain('2015-03-01')
            // La fila nueva viene con hoy en Paraguay (01/10), no con el día UTC (02/10).
            ->and($html)->toContain("fecha_desde: '01\\/10\\/2026', activa: true")
            ->and($html)->toContain('x-data="campoFecha({ valor: fila.fecha_desde');
    });

    test('se guarda la fecha cargada en dd/mm/aaaa', function () {
        $this->put(route('admin.profesionales.update', $this->profesional), [
            'matricula' => 'MP-1', 'estado_id' => estadoId('ACTIVO'), 'con_especialidades' => '1',
            'especialidades' => [['especialidad_id' => $this->gineco->id, 'fecha_desde' => '15/06/2020']],
        ])->assertSessionHasNoErrors();

        expect(substr($this->profesional->especialidades()->sole()->pivot->fecha_desde, 0, 10))->toBe('2020-06-15');

        $this->put(route('admin.profesionales.update', $this->profesional), [
            'matricula' => 'MP-1', 'estado_id' => estadoId('ACTIVO'), 'con_especialidades' => '1',
            'especialidades' => [['especialidad_id' => $this->gineco->id, 'fecha_desde' => '2020-06-15']],
        ])->assertSessionHasErrors('especialidades.0.fecha_desde');
    });
});

describe('listados', function () {
    test('el último acceso de los usuarios se ve en dd/mm/aaaa HH:mm, en hora de Paraguay', function () {
        User::factory()->conPersona(['apellidos' => 'Ruiz', 'nombres' => 'Liz'])->create(['ultimo_acceso' => '2026-10-02 02:30:00']);

        $this->get(route('admin.usuarios.index'))->assertOk()->assertSee('01/10/2026 23:30')->assertDontSee('2026-10-02');
    });

    test('la fecha de alta del paciente es el día de Paraguay y se ve en dd/mm/aaaa', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-02 02:30:00', 'UTC')); // 01/10/2026 23:30 en Paraguay
        $paciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000001']);

        expect($paciente->fresh()->fecha_alta->format('Y-m-d'))->toBe('2026-10-01');
        $this->get(route('admin.pacientes.index'))->assertSee('01/10/2026');
        $this->get(route('admin.pacientes.edit', $paciente))->assertSee('01/10/2026');
    });
});
