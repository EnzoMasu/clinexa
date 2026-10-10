<?php

/*
 * Un usuario con SOLO un perfil predefinido: lo que le corresponde funciona de punta a punta (sin 403
 * inesperados) y lo demás da 403. Escenario clínico de escenario.php (martes 06/10/2026 09:00), perfiles de
 * PerfilesPredefinidos. Datos inventados.
 */

use App\Models\Consulta;
use App\Models\Disponibilidad;
use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\Paciente;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Profesional;
use App\Models\Receta;
use App\Models\TipoDocumento;
use App\Models\Turno;
use App\Models\User;
use App\Support\PerfilesPredefinidos;

beforeEach(function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
});

/** Un usuario nuevo con solo ese perfil predefinido. */
function usuarioCon(string $codigo, array $persona = []): User
{
    return User::factory()->conPersona($persona ?: ['apellidos' => 'Prueba', 'nombres' => $codigo])
        ->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

/** El mismo usuario, ahora con solo ese perfil predefinido (en lugar de los de prueba). */
function soloCon(User $usuario, string $codigo): User
{
    $usuario->perfiles()->sync([PerfilAcceso::where('codigo', $codigo)->sole()->id]);

    return $usuario->fresh();
}

/** Las secciones con listado (index) y su módulo, para recorrer la matriz. */
function seccionesConListado(): array
{
    return [
        'usuarios' => 'USUARIOS', 'perfiles-acceso' => 'PERFILES_ACCESO', 'matriz-permisos' => 'PERFILES_ACCESO', 'auditoria' => 'AUDITORIA',
        'personas' => 'PERSONAS', 'pacientes' => 'PACIENTES', 'profesionales' => 'PROFESIONALES', 'proveedores' => 'PROVEEDORES',
        'categorias-proveedor' => 'CATEGORIAS_PROVEEDOR', 'tipos-red-social' => 'TIPOS_RED_SOCIAL', 'responsables-pago' => 'RESPONSABLES_PAGO',
        'turnos' => 'TURNOS', 'disponibilidades' => 'DISPONIBILIDAD', 'consultorios' => 'CONSULTORIOS', 'origenes-turno' => 'ORIGENES_TURNO',
        'atencion' => 'HISTORIA_CLINICA', 'preparacion' => 'PREPARACION', 'historias-clinicas' => 'HISTORIA_CLINICA',
        'especialidades' => 'ESPECIALIDADES', 'sucursales' => 'SUCURSALES', 'tipos-documento' => 'TIPOS_DOCUMENTO', 'procedimientos' => 'PROCEDIMIENTOS',
        'medios-pago' => 'MEDIOS_PAGO', 'categorias-gasto' => 'CATEGORIAS_GASTO', 'cie10' => 'CIE10', 'tipos-bloque-anamnesis' => 'TIPOS_BLOQUE_ANAMNESIS',
        'tipos-indicacion' => 'TIPOS_INDICACION', 'paises' => 'GEOGRAFIA', 'departamentos' => 'GEOGRAFIA', 'ciudades' => 'GEOGRAFIA',
    ];
}

test('cada perfil predefinido: abre los listados que su matriz tiene (VER) y el resto da 403; ve en el menú solo esos', function (string $codigo) {
    $this->actingAs(usuarioCon($codigo));
    $matriz = PerfilesPredefinidos::MATRIZ[$codigo];
    $menu = $this->get('/dashboard')->assertOk()->getContent();

    foreach (seccionesConListado() as $seccion => $modulo) {
        $puede = in_array('VER', $matriz[$modulo] ?? [], true);
        $respuesta = $this->get(route("admin.{$seccion}.index"));
        expect($respuesta->status())->toBe($puede ? 200 : 403, "{$codigo}: {$seccion}");
        expect(str_contains($menu, 'href="'.route("admin.{$seccion}.index").'"'))->toBe($puede, "{$codigo}: menú {$seccion}");
    }
})->with(array_keys(PerfilesPredefinidos::MATRIZ));

test('cada perfil: el alta (CREAR) de cada sección solo si su matriz la tiene', function (string $codigo) {
    $this->actingAs(usuarioCon($codigo));
    $matriz = PerfilesPredefinidos::MATRIZ[$codigo];

    foreach (seccionesConListado() as $seccion => $modulo) {
        if (! \Illuminate\Support\Facades\Route::has("admin.{$seccion}.create") || in_array($seccion, ['turnos', 'personas'], true)) {
            continue; // turnos y personas: en sus propios tests (el formulario pide datos previos)
        }
        $puede = in_array('CREAR', $matriz[$modulo] ?? [], true);
        expect($this->get(route("admin.{$seccion}.create"))->status())->toBe($puede ? 200 : 403, "{$codigo}: alta de {$seccion}");
    }
})->with(array_keys(PerfilesPredefinidos::MATRIZ));

describe('Médico', function () {
    beforeEach(function () {
        $this->medico = soloCon($this->medico, 'MEDICO');
        $this->actingAs($this->medico);
    });

    test('Consulta, atender, autoguardado, receta y finalizar, de punta a punta', function () {
        $turno = hcTurno();
        $this->get(route('admin.atencion.index'))->assertOk()->assertSee('Duarte, Carmen');

        $this->post(route('admin.atencion.atender', $turno))->assertRedirect();
        $consulta = Consulta::where('turno_id', $turno->id)->sole();
        $this->get(route('admin.consultas.atencion', $consulta))->assertOk();
        hcAutoguardar($consulta, hcDatos())->assertOk();
        $this->getJson(route('admin.historias-clinicas.cie10', ['q' => 'J06']))->assertOk();

        $this->get(route('admin.recetas.create', $consulta))->assertOk();
        $receta = hcReceta($consulta);
        $this->get(route('admin.recetas.vista-previa', $receta))->assertOk();
        hcEmitir($receta)->assertSessionHasNoErrors();
        $this->get(route('admin.recetas.imprimir', $receta))->assertOk();

        hcFinalizar($consulta, hcDatos())->assertSessionHasNoErrors();
        expect($consulta->fresh()->estado->codigo)->toBe(Estado::FINALIZADO)
            ->and($receta->fresh()->estado->codigo)->toBe(Estado::EMITIDO)
            ->and($turno->fresh()->estado->codigo)->toBe(Estado::ATENDIDO);

        $this->get(route('admin.historias-clinicas.show', $this->historia))->assertOk();
        $this->get(route('admin.consultas.show', $consulta))->assertOk();
    });

    test('atender sin turno, preparar, "No se presentó" y cerrar la jornada', function () {
        $this->get(route('admin.atencion.pacientes', ['q' => 'Duarte']))->assertOk();
        $this->post(route('admin.preparacion.preparar', hcTurno(['hora_inicio' => '10:00', 'hora_fin' => '10:30'])))->assertRedirect();
        $this->from(route('admin.atencion.index'))->post(route('admin.atencion.no-se-presento', hcTurno()))->assertSessionHasNoErrors();
        $this->get(route('admin.atencion.cerrar-jornada'))->assertOk();
        hcEnCurso();
    });

    test('sin Usuarios, Perfiles ni Auditoría (ni escribir en agenda, pacientes o personas)', function () {
        foreach (['admin.usuarios.index', 'admin.perfiles-acceso.index', 'admin.matriz-permisos.index', 'admin.auditoria.index',
            'admin.turnos.create', 'admin.pacientes.create', 'admin.personas.index', 'admin.disponibilidades.create'] as $ruta) {
            $this->get(route($ruta))->assertForbidden();
        }
    });
});

describe('Enfermería', function () {
    beforeEach(function () {
        $this->enfermera = usuarioCon('ENFERMERIA', ['apellidos' => 'Ortiz', 'nombres' => 'Nidia']);
        $this->actingAs($this->enfermera);
    });

    test('la preparación completa: lista, preparar, formulario, autoguardado, marcar como lista y reabrir', function () {
        $turno = hcTurno();
        $this->get(route('admin.preparacion.index'))->assertOk()->assertSee('Duarte, Carmen');

        $this->post(route('admin.preparacion.preparar', $turno))->assertRedirect();
        $consulta = Consulta::where('turno_id', $turno->id)->sole();
        $this->get(route('admin.preparacion.formulario', $consulta))->assertOk();
        hcAutoguardar($consulta, [
            'con_anamnesis' => '1',
            'anamnesis' => [['uid' => 'n-1', 'id' => '', 'activo' => '1', 'tipo_bloque_anamnesis_id' => $this->enfermedadActual->id, 'contenido' => 'Refiere fiebre.']],
            'examen' => ['presion_arterial' => '110/70', 'temperatura' => '37,9'],
        ])->assertOk();
        $this->post(route('admin.preparacion.lista', $consulta))->assertRedirect(route('admin.preparacion.formulario', $consulta));
        $this->post(route('admin.preparacion.reabrir', $consulta))->assertRedirect();

        expect($consulta->fresh()->bloquesAnamnesis()->sole()->usuario_id)->toBe($this->enfermera->id)
            ->and($consulta->fresh()->enPreparacion())->toBeTrue();
    });

    test('403 en Consulta, historias, recetas, atender y finalizar', function () {
        $turno = hcTurno();
        $this->post(route('admin.preparacion.preparar', $turno));
        $consulta = Consulta::where('turno_id', $turno->id)->sole();

        $this->get(route('admin.atencion.index'))->assertForbidden();
        $this->get(route('admin.historias-clinicas.index'))->assertForbidden();
        $this->get(route('admin.historias-clinicas.show', $this->historia))->assertForbidden();
        $this->get(route('admin.consultas.show', $consulta))->assertForbidden();
        $this->get(route('admin.consultas.atencion', $consulta))->assertForbidden();
        $this->get(route('admin.recetas.create', $consulta))->assertForbidden();
        $this->post(route('admin.atencion.atender', $turno))->assertForbidden();
        $this->post(route('admin.consultas.finalizar', $consulta))->assertForbidden();
        $this->get(route('admin.turnos.index'))->assertForbidden();
        expect($consulta->fresh()->enPreparacion())->toBeTrue();
    });
});

describe('Recepción', function () {
    beforeEach(function () {
        TipoDocumento::firstOrCreate(['codigo' => 'CI'], ['nombre' => 'Cédula']);
        ModuloSistema::where('codigo', 'PERSONAS')->sole()->configurarTiposDocumento(['CI']);
        Disponibilidad::create([
            'profesional_id' => $this->profesional->id, 'consultorio_id' => $this->consultorio->id, 'dia_semana' => 'MAR',
            'hora_desde' => '08:00', 'hora_hasta' => '12:00', 'duracion_turno_minutos' => 30, 'vigencia_desde' => '2026-01-01',
        ]);
        $this->actingAs(usuarioCon('RECEPCION', ['apellidos' => 'Acosta', 'nombres' => 'Mirna']));
    });

    test('alta de persona y de paciente, con sus selectores', function () {
        $this->get(route('admin.personas.create'))->assertOk();
        $this->getJson(route('verificar-unico', ['campo' => 'persona.documento', 'valor' => '4123456', 'tipo_documento_id' => TipoDocumento::where('codigo', 'CI')->value('id')]))->assertOk();
        $this->getJson(route('geografia.departamentos', ['pais_id' => 1]))->assertOk();
        $this->post(route('admin.personas.store'), [
            'tipo_persona' => 'FISICA', 'tipo_documento_id' => TipoDocumento::where('codigo', 'CI')->value('id'), 'nro_documento' => '4123456',
            'apellidos' => 'Galeano', 'nombres' => 'Rocío', 'fecha_nacimiento' => '12/02/1995', 'sexo' => 'F',
            'email' => 'rocio.galeano@clinexa.test', 'telefono' => '0981 111 222', 'direccion' => 'Asunción',
        ])->assertSessionHasNoErrors();
        $persona = Persona::where('nro_documento', '4123456')->sole();

        $this->get(route('admin.pacientes.create'))->assertOk();
        expect($this->getJson(route('admin.pacientes.personas-disponibles', ['q' => 'Galeano']))->assertOk()->json('*.id'))->toBe([$persona->id]);
        $this->post(route('admin.pacientes.store'), ['persona_id' => $persona->id, 'nro_ficha' => Paciente::siguienteNroFicha()])->assertSessionHasNoErrors();
        expect(Paciente::where('persona_id', $persona->id)->exists())->toBeTrue();

        $this->get(route('admin.responsables-pago.create'))->assertOk();
        $this->getJson(route('admin.responsables-pago.personas-disponibles', ['q' => 'Galeano']))->assertOk();
    });

    test('disponibilidad, turno, confirmar y cancelar', function () {
        $otro = Profesional::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Ramírez', 'nombres' => 'Ana'])->id, 'matricula' => 'MP-9']);
        $this->get(route('admin.disponibilidades.create'))->assertOk();
        $this->getJson(route('admin.disponibilidades.profesionales', ['q' => 'Ramírez']))->assertOk();
        $this->post(route('admin.disponibilidades.store'), [
            'profesional_id' => $otro->id, 'consultorio_id' => $this->consultorio->id, 'dia_semana' => 'JUE',
            'hora_desde' => '14:00', 'hora_hasta' => '18:00', 'duracion_turno_minutos' => 20, 'vigencia_desde' => '01/10/2026',
        ])->assertSessionHasNoErrors();

        $this->get(route('admin.turnos.create'))->assertOk();
        $this->getJson(route('admin.turnos.pacientes', ['q' => 'Duarte']))->assertOk();
        $this->getJson(route('admin.turnos.profesionales', ['q' => 'Benítez']))->assertOk();
        expect($this->getJson(route('admin.turnos.horarios-disponibles', ['profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026']))->assertOk()->json())->not->toBeEmpty();
        $this->post(route('admin.turnos.store'), ['paciente_id' => $this->paciente->id, 'profesional_id' => $this->profesional->id, 'fecha' => '06/10/2026', 'hora_inicio' => '10:00'])
            ->assertSessionHasNoErrors();
        $turno = Turno::latest('id')->sole();

        $this->from(route('admin.turnos.index'))->patch(route('admin.turnos.estado', $turno), ['accion' => 'confirmar'])->assertSessionHasNoErrors();
        expect($turno->fresh()->estado->codigo)->toBe(Estado::CONFIRMADO);
        $this->from(route('admin.turnos.index'))->patch(route('admin.turnos.estado', $turno), ['accion' => 'cancelar'])->assertSessionHasNoErrors();
        expect($turno->fresh()->estado->codigo)->toBe(Estado::CANCELADO);
    });

    test('403 en lo clínico, la auditoría y los usuarios', function () {
        $turno = hcTurno();
        foreach (['admin.historias-clinicas.index', 'admin.atencion.index', 'admin.preparacion.index', 'admin.auditoria.index',
            'admin.usuarios.index', 'admin.perfiles-acceso.index', 'admin.matriz-permisos.index'] as $ruta) {
            $this->get(route($ruta))->assertForbidden();
        }
        $this->get(route('admin.historias-clinicas.show', $this->historia))->assertForbidden();
        $this->post(route('admin.preparacion.preparar', $turno))->assertForbidden();
        $this->post(route('admin.atencion.atender', $turno))->assertForbidden();
        expect(Consulta::count())->toBe(0);
    });
});

describe('perfiles de lectura', function () {
    test('Gerencia, Supervisión médica y Auditor leen y no escriben', function (string $codigo) {
        $this->actingAs($this->medico);
        $consulta = hcConsulta();
        $this->actingAs(usuarioCon($codigo));

        $this->get(route('admin.auditoria.index'))->assertOk();
        foreach (['admin.turnos.create', 'admin.disponibilidades.create', 'admin.consultorios.create', 'admin.pacientes.create', 'admin.usuarios.create'] as $ruta) {
            $this->get(route($ruta))->assertForbidden();
        }
        $this->post(route('admin.atencion.atender-sin-turno', $this->historia))->assertForbidden();
        $this->post(route('admin.preparacion.preparar', hcTurno()))->assertForbidden();
        $this->patch(route('admin.turnos.estado', hcTurno(['hora_inicio' => '11:00', 'hora_fin' => '11:30'])), ['accion' => 'cancelar'])->assertForbidden();

        // La historia clínica: solo Supervisión médica la lee (y no escribe).
        $leeClinico = $codigo === 'SUPERVISION_MEDICA';
        $this->get(route('admin.historias-clinicas.show', $this->historia))->assertStatus($leeClinico ? 200 : 403);
        $this->get(route('admin.consultas.show', $consulta))->assertStatus($leeClinico ? 200 : 403);
        expect(Consulta::count())->toBe(1)->and(Receta::count())->toBe(0);
    })->with(['GERENCIA', 'SUPERVISION_MEDICA', 'AUDITOR']);

    test('en la auditoría, quien no tiene VER de la historia clínica no ve su contenido', function () {
        $this->actingAs($this->medico);
        hcConsulta(['motivo_consulta' => 'MOTIVO-CONFIDENCIAL']);

        $evento = \App\Models\LogAuditoria::where('tabla_afectada', 'consultas')->get()
            ->first(fn ($log) => str_contains(json_encode($log->valor_nuevo, JSON_UNESCAPED_UNICODE) ?: '', 'MOTIVO-CONFIDENCIAL'));
        expect($evento)->not->toBeNull();

        $this->actingAs(usuarioCon('AUDITOR'));
        $this->get(route('admin.auditoria.index'))->assertOk()->assertDontSee('MOTIVO-CONFIDENCIAL');
        $this->get(route('admin.auditoria.show', $evento))->assertOk()->assertDontSee('MOTIVO-CONFIDENCIAL');
        $this->actingAs(usuarioCon('GERENCIA'));
        $this->get(route('admin.auditoria.show', $evento))->assertOk()->assertDontSee('MOTIVO-CONFIDENCIAL');
        $this->actingAs(usuarioCon('SUPERVISION_MEDICA'));
        $this->get(route('admin.auditoria.show', $evento))->assertOk()->assertSee('MOTIVO-CONFIDENCIAL');
    });
});
