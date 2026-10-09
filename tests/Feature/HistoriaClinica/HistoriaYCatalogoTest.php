<?php

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\HistoriaClinica;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\TipoBloqueAnamnesis;
use App\Models\User;
use Database\Seeders\DatosRealesClinicaSeeder;
use Database\Seeders\ModulosSensiblesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(fn () => Carbon::setTestNow());

describe('historia automática y backfill', function () {
    test('al crear un paciente (por la pantalla o por código) se crea su historia, con apertura = fecha de alta', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        // Por código (seeders, consola): sin usuario ni auditoría.
        $porCodigo = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000010']);
        expect($porCodigo->historiaClinica->fecha_apertura->format('Y-m-d'))->toBe('2026-10-06');

        // Por la pantalla de Pacientes: misma transacción y queda en la auditoría.
        $this->actingAs(User::factory()->administrador()->create());
        $persona = Persona::factory()->create();
        $this->post(route('admin.pacientes.store'), ['persona_id' => $persona->id, 'nro_ficha' => 'FP-0000011'])->assertSessionHasNoErrors();

        $historia = Paciente::where('persona_id', $persona->id)->sole()->historiaClinica;
        expect($historia)->not->toBeNull()
            ->and(LogAuditoria::where('tabla_afectada', 'historias_clinicas')->where('accion', 'CREAR')->sole()->registro_afectado_id)->toBe((string) $historia->id);
    });

    test('la migración de backfill crea una historia por paciente existente, con su fecha de alta, y es idempotente', function () {
        $migracion = require database_path('migrations/2026_10_08_100003_crear_historias_de_pacientes_existentes.php');
        $pacientes = collect(['2025-01-10', '2026-03-05', '2026-09-30'])->map(fn ($alta, $i) => tap(
            Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-000002'.$i]),
            fn ($p) => $p->forceFill(['fecha_alta' => $alta])->save()));
        HistoriaClinica::query()->delete(); // como antes de la migración: pacientes sin historia

        $migracion->up();

        expect($pacientes->map(fn ($p) => $p->fresh()->historiaClinica->fecha_apertura->format('Y-m-d'))->all())
            ->toBe(['2025-01-10', '2026-03-05', '2026-09-30']);

        $migracion->up(); // otra vez: no duplica
        expect(HistoriaClinica::count())->toBe(3);
    });

    test('un paciente tiene una sola historia (unique en la base)', function () {
        $paciente = Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000030']);

        expect(fn () => DB::table('historias_clinicas')->insert(['paciente_id' => $paciente->id, 'fecha_apertura' => '2026-10-06']))
            ->toThrow(QueryException::class);
    });
});

describe('módulos, permisos y catálogo', function () {
    test('TIPOS_BLOQUE_ANAMNESIS con ACTIVO/INACTIVO; HISTORIA_CLINICA con los estados de la consulta; la historia es sensible y solo usa VER, CREAR y EDITAR', function () {
        $admin = User::factory()->administrador()->create();
        (new ModulosSensiblesSeeder)->run();

        expect(Estado::delModulo('TIPOS_BLOQUE_ANAMNESIS')->pluck('codigo')->sort()->values()->all())->toBe(['ACTIVO', 'INACTIVO'])
            ->and(Estado::inicialDe('TIPOS_BLOQUE_ANAMNESIS'))->toBe(estadoId('ACTIVO'));
        // Las consultas usan el estado_modulo de HISTORIA_CLINICA (la historia en sí no tiene estado).
        expect(Estado::delModulo('HISTORIA_CLINICA')->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'EN_CURSO', 'EN_PREPARACION', 'FINALIZADO'])
            ->and(Estado::inicialDe('HISTORIA_CLINICA'))->toBe(estadoId('EN_PREPARACION'));
        expect(ModuloSistema::where('codigo', 'HISTORIA_CLINICA')->value('es_sensible'))->toBeTrue()
            ->and(Permiso::accionesDe('HISTORIA_CLINICA'))->toBe(['VER', 'CREAR', 'EDITAR'])
            ->and($admin->tienePermiso('HISTORIA_CLINICA', 'EDITAR'))->toBeTrue();

        // En la matriz de perfiles, DESACTIVAR y EXPORTAR no aplican.
        $this->actingAs($admin)->get(route('admin.perfiles-acceso.create'))->assertOk()->assertSee('Historia clínica');
    });

    test('los tipos de bloque se siembran solo si la tabla está vacía', function () {
        $this->seed(DatosRealesClinicaSeeder::class);
        expect(TipoBloqueAnamnesis::orderBy('id')->pluck('nombre')->all())->toBe(DatosRealesClinicaSeeder::TIPOS_BLOQUE_ANAMNESIS);

        TipoBloqueAnamnesis::where('nombre', 'Hábitos')->update(['nombre' => 'Hábitos tóxicos']);
        $this->seed(DatosRealesClinicaSeeder::class);

        expect(TipoBloqueAnamnesis::count())->toBe(7)->and(TipoBloqueAnamnesis::where('nombre', 'Hábitos')->exists())->toBeFalse();
    });
});

describe('pantallas de lectura', function () {
    beforeEach(fn () => hcEscenario());

    test('listado: cantidad de consultas y última consulta, y busca por nombre, documento o ficha', function () {
        hcConsulta();
        Carbon::setTestNow(Carbon::parse('2026-10-06 15:30:00', 'UTC'));
        hcConsulta();
        $otro = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Zárate', 'nombres' => 'Luis', 'nro_documento' => '4567890'])->id, 'nro_ficha' => 'FP-0000099']);

        $this->get(route('admin.historias-clinicas.index'))->assertOk()
            ->assertSeeInOrder(['Duarte, Carmen', '2', '06/10/2026 12:30', 'Zárate, Luis', '—']);

        foreach (['Zára', '4567', 'FP-0000099'] as $busqueda) {
            $this->get(route('admin.historias-clinicas.index', ['q' => $busqueda]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
                ->assertSee('Zárate, Luis')->assertDontSee('Duarte, Carmen');
        }
    });

    test('historia: datos del paciente (edad calculada, sexo) y consultas de la más reciente a la más vieja, con sus códigos', function () {
        hcConsulta(['motivo_consulta' => 'Primera']);
        Carbon::setTestNow(now()->addDay());
        hcConsulta(['motivo_consulta' => 'Segunda', 'diagnosticos' => [
            ['id' => '', 'codigo_cie10' => 'R51', 'tipo' => 'CONFIRMADO'], ['id' => '', 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO'],
        ], 'diagnostico_principal' => '1']);

        $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk()
            ->assertSee('Duarte, Carmen')->assertSee('FP-0000001')->assertSee('36 años')->assertSee('Femenino')
            ->assertSeeInOrder(['07/10/2026', 'Segunda', 'J06.9', 'R51', '06/10/2026', 'Primera', 'J06.9']);
    });

    test('la historia pagina las consultas de a 20', function () {
        foreach (range(1, 21) as $n) {
            Consulta::create(['historia_clinica_id' => $this->historia->id, 'profesional_id' => $this->profesional->id, 'motivo_consulta' => "Motivo {$n}"]);
        }

        expect($this->get(route('admin.historias-clinicas.show', $this->historia))->viewData('consultas'))
            ->total()->toBe(21)->count()->toBe(20);
    });
});
