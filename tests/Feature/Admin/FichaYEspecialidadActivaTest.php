<?php

use App\Models\Especialidad;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->administrador()->create());
});

describe('ficha de paciente', function () {
    test('el número se propone con el formato FP- y 7 dígitos', function () {
        expect(Paciente::nroFicha(3))->toBe('FP-0000003')
            ->and(Paciente::siguienteNroFicha())->toBe('FP-0000001');

        $this->post(route('admin.pacientes.store'), ['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => Paciente::siguienteNroFicha()])
            ->assertSessionHasNoErrors();

        expect(Paciente::sole()->nro_ficha)->toBe('FP-0000001')
            ->and(Paciente::siguienteNroFicha())->toBe('FP-0000002');
    });

    test('la fecha de alta se completa sola con la fecha actual y no se edita desde el formulario', function () {
        Carbon::setTestNow('2026-10-01 10:00:00');

        $this->get(route('admin.pacientes.create'))->assertDontSee('name="fecha_alta"', false);
        $this->post(route('admin.pacientes.store'), [
            'persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000001', 'fecha_alta' => '2000-01-01',
        ])->assertSessionHasNoErrors();

        $paciente = Paciente::sole();
        expect($paciente->fecha_alta->format('Y-m-d'))->toBe('2026-10-01');

        // Editar no la cambia, aunque se mande.
        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->put(route('admin.pacientes.update', $paciente), [
            'nro_ficha' => 'FP-0000001', 'estado_id' => estadoId('ACTIVO'), 'fecha_alta' => '2000-01-01',
        ])->assertSessionHasNoErrors();
        expect($paciente->fresh()->fecha_alta->format('Y-m-d'))->toBe('2026-10-01');

        Carbon::setTestNow();
    });

    test('la fecha de alta se muestra en el listado y en la ficha', function () {
        $paciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000007']);
        $paciente->forceFill(['fecha_alta' => '2026-03-05'])->save();

        $this->get(route('admin.pacientes.index'))->assertOk()->assertSeeInOrder(['Fecha de alta', 'FP-0000007', '05/03/2026']);
        $this->get(route('admin.pacientes.edit', $paciente))->assertOk()->assertSee('Fecha de alta:')->assertSee('05/03/2026')
            ->assertDontSee('name="fecha_alta"', false);
    });

    test('la migración pasa las fichas viejas al formato FP- respetando su número', function () {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $migracion = require database_path('migrations/2026_10_01_100001_ficha_paciente_y_especialidad_activa.php');
        $migracion->down();

        $ids = collect(['000001', '000002', '12', 'PAPEL-7'])->map(fn ($nro) => DB::table('pacientes')->insertGetId([
            'persona_id' => Persona::factory()->create()->id, 'nro_ficha' => $nro, 'estado_id' => estadoId('ACTIVO'),
            'created_at' => '2026-09-20 08:00:00', 'updated_at' => '2026-09-20 08:00:00',
        ]));

        $migracion->up();

        expect($ids->map(fn ($id) => DB::table('pacientes')->where('id', $id)->value('nro_ficha'))->all())
            ->toBe(['FP-0000001', 'FP-0000002', 'FP-0000012', 'PAPEL-7'])
            ->and(DB::table('pacientes')->pluck('fecha_alta')->map(fn ($fecha) => substr($fecha, 0, 10))->unique()->all())->toBe(['2026-09-20'])
            ->and(Paciente::siguienteNroFicha())->toBe('FP-0000013');
    });

    test('la migración se detiene sin cambiar nada si dos fichas quedarían con el mismo número', function () {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $migracion = require database_path('migrations/2026_10_01_100001_ficha_paciente_y_especialidad_activa.php');
        $migracion->down();

        foreach (['2', '000002'] as $nro) {
            DB::table('pacientes')->insert([
                'persona_id' => Persona::factory()->create()->id, 'nro_ficha' => $nro, 'estado_id' => estadoId('ACTIVO'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Las dos fichas que chocan se listan (en cualquier orden).
        expect(fn () => $migracion->up())->toThrow(function (RuntimeException $e) {
            expect($e->getMessage())->toContain('FP-0000002 <- ')->toContain('000002')->toContain('No se modificó nada');
        });
        expect(DB::table('pacientes')->pluck('nro_ficha')->sort()->values()->all())->toBe(['000002', '2']);
    });
});

describe('especialidad activa del profesional', function () {
    beforeEach(function () {
        $this->gineco = Especialidad::create(['nombre' => 'Ginecología y Obstetricia']);
        $this->eco = Especialidad::create(['nombre' => 'Ecografía']);
        $this->profesional = Profesional::create(['persona_id' => Persona::factory()->create()->id, 'matricula' => 'MP-1']);
        $this->profesional->especialidades()->attach([
            $this->gineco->id => ['fecha_desde' => '2015-01-01'],
            $this->eco->id => ['fecha_desde' => '2020-01-01'],
        ]);
    });

    function guardarEspecialidades(array $filas)
    {
        return test()->put(route('admin.profesionales.update', test()->profesional), enFormulario([
            'matricula' => 'MP-1', 'estado_id' => estadoId('ACTIVO'), 'con_especialidades' => '1', 'especialidades' => $filas,
        ]));
    }

    function estadoEspecialidades(): array
    {
        return test()->profesional->especialidades()->orderBy('nombre')->get()
            ->mapWithKeys(fn ($especialidad) => [$especialidad->nombre => (bool) $especialidad->pivot->activa])->all();
    }

    test('las especialidades nuevas quedan activas', function () {
        expect(estadoEspecialidades())->toBe(['Ecografía' => true, 'Ginecología y Obstetricia' => true]);
    });

    test('deshabilitar conserva la fila con activa = false, y habilitar la vuelve a activar', function () {
        guardarEspecialidades([
            ['especialidad_id' => $this->gineco->id, 'fecha_desde' => '2015-01-01', 'activa' => '1'],
            ['especialidad_id' => $this->eco->id, 'fecha_desde' => '2020-01-01', 'activa' => '0'],
        ])->assertSessionHasNoErrors();

        expect(estadoEspecialidades())->toBe(['Ecografía' => false, 'Ginecología y Obstetricia' => true])
            ->and(DB::table('profesional_especialidad')->count())->toBe(2);

        guardarEspecialidades([
            ['especialidad_id' => $this->gineco->id, 'fecha_desde' => '2015-01-01', 'activa' => '1'],
            ['especialidad_id' => $this->eco->id, 'fecha_desde' => '2020-01-01', 'activa' => '1'],
        ])->assertSessionHasNoErrors();

        expect(estadoEspecialidades())->toBe(['Ecografía' => true, 'Ginecología y Obstetricia' => true]);
    });

    test('quitar sigue borrando la fila', function () {
        guardarEspecialidades([['especialidad_id' => $this->gineco->id, 'fecha_desde' => '2015-01-01', 'activa' => '1']])
            ->assertSessionHasNoErrors();

        expect(estadoEspecialidades())->toBe(['Ginecología y Obstetricia' => true]);
    });

    test('sin el campo activa (formularios viejos) la especialidad queda habilitada', function () {
        guardarEspecialidades([['especialidad_id' => $this->eco->id, 'fecha_desde' => '2020-01-01']])->assertSessionHasNoErrors();

        expect(estadoEspecialidades())->toBe(['Ecografía' => true]);
    });

    test('especialidadesActivas excluye las deshabilitadas y las de catálogo inactivo', function () {
        $pediatria = Especialidad::create(['nombre' => 'Pediatría']);
        $this->profesional->especialidades()->attach($pediatria->id, ['fecha_desde' => '2021-01-01']);
        $this->profesional->especialidades()->updateExistingPivot($this->eco->id, ['activa' => false]);
        $pediatria->desactivar();

        expect($this->profesional->especialidadesActivas()->pluck('nombre')->all())->toBe(['Ginecología y Obstetricia'])
            ->and($this->profesional->especialidades()->count())->toBe(3);
    });

    test('la edición muestra los botones y marca las deshabilitadas; el listado también', function () {
        $this->profesional->especialidades()->updateExistingPivot($this->eco->id, ['activa' => false]);

        $html = $this->get(route('admin.profesionales.edit', $this->profesional))->assertOk()
            ->assertSee("fila.activa ? 'Deshabilitar' : 'Habilitar'", false)
            ->assertSee('Quitar')
            ->assertSee('Deshabilitada')
            ->getContent();
        // Las filas iniciales llevan su estado: Ecografía deshabilitada, Ginecología habilitada.
        expect($html)->toMatch('/\\\\u0022especialidad_id\\\\u0022:\\\\u0022'.$this->eco->id.'\\\\u0022.*?\\\\u0022activa\\\\u0022:false/')
            ->toMatch('/\\\\u0022especialidad_id\\\\u0022:\\\\u0022'.$this->gineco->id.'\\\\u0022.*?\\\\u0022activa\\\\u0022:true/');

        $this->get(route('admin.profesionales.index'))->assertSee('Ecografía (deshabilitada)')->assertDontSee('Ginecología y Obstetricia (deshabilitada)');
    });
});
