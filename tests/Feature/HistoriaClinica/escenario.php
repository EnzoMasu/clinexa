<?php

/*
 * Escenario común de los tests de historia clínica (lo carga tests/Pest.php). "Ahora" fijo: martes
 * 06/10/2026 09:00 en Paraguay (12:00 UTC).
 *
 * - medico: usuario de la Dra. Rosa Benítez, profesional ACTIVA, con VER, CREAR y EDITAR sobre la
 *   historia clínica (y VER sobre Turnos).
 * - otroMedico: el Dr. Insfrán, profesional con los mismos permisos.
 * - paciente: Carmen Duarte (su historia se crea sola).
 * - CIE-10: J06.9, R51 y Z00.0 activos; tipos de bloque Enfermedad actual y Alergias.
 */

use App\Models\CatalogoCIE10;
use App\Models\Consulta;
use App\Models\Consultorio;
use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Receta;
use App\Models\Sucursal;
use App\Models\TipoBloqueAnamnesis;
use App\Models\Turno;
use App\Models\User;
use Database\Seeders\ModulosSensiblesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const HC_PERMISOS_MEDICO = ['HISTORIA_CLINICA' => ['VER', 'CREAR', 'EDITAR'], 'TURNOS' => ['VER']];

function hcEscenario(): void
{
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
    $t = test();

    $t->consultorio = Consultorio::create([
        'sucursal_id' => Sucursal::create(['nombre' => 'Plenitud Mujer', 'direccion' => 'Iturbe', 'telefono' => '0975'])->id,
        'nombre' => 'Consultorio 1',
    ]);

    [$t->medico, $t->profesional] = hcMedico(['apellidos' => 'Benítez', 'nombres' => 'Rosa'], 'MP-1');
    [$t->otroMedico, $t->otroProfesional] = hcMedico(['apellidos' => 'Insfrán', 'nombres' => 'Laura'], 'MP-2');

    $t->paciente = Paciente::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Duarte', 'nombres' => 'Carmen', 'fecha_nacimiento' => '1990-03-05', 'sexo' => 'F'])->id, 'nro_ficha' => 'FP-0000001']);
    $t->historia = $t->paciente->historiaClinica;

    foreach ([['J06.9', 'Rinofaringitis aguda'], ['R51', 'Cefalea'], ['Z00.0', 'Examen médico general']] as [$codigo, $descripcion]) {
        CatalogoCIE10::create(['codigo' => $codigo, 'descripcion' => $descripcion, 'capitulo' => 'X']);
    }
    $t->enfermedadActual = TipoBloqueAnamnesis::create(['nombre' => 'Enfermedad actual']);
    $t->alergias = TipoBloqueAnamnesis::create(['nombre' => 'Alergias']);

    // Como en la base real: HISTORIA_CLINICA y AUDITORIA son sensibles (se registran sus lecturas).
    (new ModulosSensiblesSeeder)->run();

    test()->actingAs($t->medico);
}

/** Un usuario profesional ACTIVO con los permisos de médico: [User, Profesional]. */
function hcMedico(array $persona, string $matricula, array $permisos = HC_PERMISOS_MEDICO): array
{
    $usuario = User::factory()->conPermisos($permisos)->conPersona($persona)->create();

    return [$usuario, Profesional::create(['persona_id' => $usuario->persona_id, 'matricula' => $matricula])];
}

/** Turno de hoy de la Dra. Benítez con Carmen Duarte, CONFIRMADO. */
function hcTurno(array $cambios = []): Turno
{
    return Turno::create([
        'paciente_id' => test()->paciente->id, 'profesional_id' => test()->profesional->id, 'consultorio_id' => test()->consultorio->id,
        'fecha' => '2026-10-06', 'hora_inicio' => '08:00', 'hora_fin' => '08:30', 'estado_id' => Estado::idDe(Estado::CONFIRMADO),
        ...$cambios,
    ]);
}

/** Lo que manda el formulario de la consulta con JavaScript (con las marcas de las listas). */
function hcDatos(array $cambios = []): array
{
    return [
        'motivo_consulta' => 'Dolor de garganta y fiebre desde ayer.',
        'con_anamnesis' => '1',
        'anamnesis' => [
            ['id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => test()->enfermedadActual->id, 'contenido' => 'Odinofagia de 24 horas.'],
            ['id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => test()->alergias->id, 'contenido' => 'Penicilina.'],
        ],
        'examen' => ['presion_arterial' => '120/80', 'frecuencia_cardiaca' => '88', 'temperatura' => '38,2', 'peso' => '61,5', 'talla' => '165'],
        'con_diagnosticos' => '1',
        'diagnosticos' => [
            ['id' => '', 'activo' => '1', 'codigo_cie10' => 'J06.9', 'tipo' => 'PRESUNTIVO', 'descripcion_adicional' => ''],
        ],
        'diagnostico_principal' => '0',
        ...$cambios,
    ];
}

/** Atiende sin turno (o con el turno indicado) como el usuario logueado. */
function hcGuardarNueva(array $cambios = [], ?Turno $turno = null)
{
    return test()->post(route('admin.consultas.store', test()->historia), [...hcDatos($cambios), 'turno_id' => $turno?->id ?? '']);
}

/** Una consulta ya guardada de la Dra. Benítez, por el formulario. */
function hcConsulta(array $cambios = []): Consulta
{
    hcGuardarNueva($cambios)->assertSessionHasNoErrors();

    return Consulta::latest('id')->firstOrFail();
}

/** Edita la consulta mandando su versión actual (salvo que se indique otra). */
function hcActualizar(Consulta $consulta, array $datos, ?string $version = null)
{
    return test()->from(route('admin.consultas.edit', $consulta))
        ->put(route('admin.consultas.update', $consulta), [...$datos, 'version' => $version ?? $consulta->fresh()->version()]);
}

/** Las filas guardadas, como el formulario las volvería a mandar (para editar). */
function hcFilasGuardadas(Consulta $consulta): array
{
    $consulta->refresh();
    $diagnosticos = $consulta->diagnosticos()->orderBy('id')->get();

    return [
        'motivo_consulta' => $consulta->motivo_consulta,
        'con_anamnesis' => '1',
        'anamnesis' => $consulta->bloquesAnamnesis()->orderBy('orden')->get()
            ->map(fn ($b) => ['id' => (string) $b->id, 'activo' => $b->activo ? '1' : '0', 'tipo_bloque_anamnesis_id' => $b->tipo_bloque_anamnesis_id, 'contenido' => $b->contenido])->all(),
        'examen' => $consulta->examenFisico?->only(['presion_arterial', 'frecuencia_cardiaca', 'frecuencia_respiratoria', 'temperatura', 'peso', 'talla', 'saturacion_oxigeno', 'hallazgos']) ?? [],
        'con_diagnosticos' => '1',
        'diagnosticos' => $diagnosticos->map(fn ($d) => ['id' => (string) $d->id, 'activo' => $d->activo ? '1' : '0', 'codigo_cie10' => $d->codigo_cie10, 'tipo' => $d->tipo->value, 'descripcion_adicional' => $d->descripcion_adicional ?? ''])->all(),
        'diagnostico_principal' => (string) ($diagnosticos->search(fn ($d) => $d->principal) === false ? '' : $diagnosticos->search(fn ($d) => $d->principal)),
    ];
}

/** Consultas SQL de un pedido, después de uno de calentamiento (permisos y estados quedan en memoria). */
function consultasSql(string $url, array $encabezados = []): int
{
    test()->get($url, $encabezados)->assertOk(); // calentamiento (permisos, estados en memoria)
    DB::enableQueryLog();
    DB::flushQueryLog();
    test()->get($url, $encabezados)->assertOk();
    $cantidad = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $cantidad;
}

/* ---- Recetas ---- */

const HC_PERMISOS_RECETAS = ['RECETAS' => ['VER', 'CREAR', 'EDITAR']];

/** Suma permisos al perfil del usuario, como ['RECETAS' => ['VER']]. */
function hcDarPermisos(User $usuario, array $permisos): User
{
    foreach ($permisos as $codigo => $acciones) {
        $modulo = ModuloSistema::where('codigo', $codigo)->sole();
        foreach ($acciones as $accion) {
            $usuario->perfilAcceso->permisos()->syncWithoutDetaching([Permiso::firstOrCreate(['modulo_sistema_id' => $modulo->id, 'accion' => $accion])->id]);
        }
    }

    return $usuario->fresh();
}

/** Un renglón de medicamento como lo manda el formulario. */
function hcRenglon(array $cambios = []): array
{
    return ['id' => '', 'medicamento' => 'Amoxicilina 500 mg', 'cantidad' => '1 caja x 21', 'dosis' => '1 comprimido', 'via' => 'Oral',
        'frecuencia' => 'Cada 8 horas', 'duracion' => '7 días', 'observaciones' => 'Tomar con las comidas', ...$cambios];
}

/** Guarda un borrador de receta por el formulario, como el usuario logueado. */
function hcGuardarReceta(Consulta $consulta, array $renglones = [], array $datos = [])
{
    return test()->post(route('admin.recetas.store', $consulta), ['con_detalles' => '1', 'detalles' => $renglones ?: [hcRenglon()], 'observaciones' => '', ...$datos]);
}

function hcReceta(Consulta $consulta, array $renglones = [], array $datos = []): Receta
{
    hcGuardarReceta($consulta, $renglones, $datos)->assertSessionHasNoErrors();

    return Receta::latest('id')->firstOrFail();
}

/** Emite el borrador con la versión que se vio en la vista previa (o la actual). */
function hcEmitir(Receta $receta, ?string $version = null)
{
    return test()->post(route('admin.recetas.emitir', $receta), ['version' => $version ?? $receta->fresh()->version()]);
}

/** El mismo usuario con otro perfil, que tiene solo estos permisos (una persona tiene un solo usuario). */
function hcConPerfil(User $usuario, array $permisos): User
{
    $perfil = User::factory()->conPermisos($permisos)->make()->perfil_acceso_id;
    $usuario->forceFill(['perfil_acceso_id' => $perfil])->save();

    return $usuario->fresh();
}

/** El <article> del contenido de la consulta dentro de una respuesta. */
function articuloDe(string $html): string
{
    preg_match('#<article .*?</article>#s', $html, $coincidencia);

    return trim($coincidencia[0] ?? '');
}
