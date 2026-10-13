<?php

use App\Http\Controllers\Admin\AtencionController;
use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\CargoSaludController;
use App\Http\Controllers\Admin\CatalogoCIE10Controller;
use App\Http\Controllers\Admin\CategoriaGastoController;
use App\Http\Controllers\Admin\CategoriaProveedorController;
use App\Http\Controllers\Admin\CiudadController;
use App\Http\Controllers\Admin\ConsultaController;
use App\Http\Controllers\Admin\ConsultorioController;
use App\Http\Controllers\Admin\CupoEntrePacientesController;
use App\Http\Controllers\Admin\DepartamentoController;
use App\Http\Controllers\Admin\DisponibilidadController;
use App\Http\Controllers\Admin\EspecialidadController;
use App\Http\Controllers\Admin\HistoriaClinicaController;
use App\Http\Controllers\Admin\MatrizPermisosController;
use App\Http\Controllers\Admin\MedioPagoController;
use App\Http\Controllers\Admin\OrigenTurnoController;
use App\Http\Controllers\Admin\PacienteController;
use App\Http\Controllers\Admin\PaisController;
use App\Http\Controllers\Admin\ParametroClinicoController;
use App\Http\Controllers\Admin\PerfilAccesoController;
use App\Http\Controllers\Admin\PersonaController;
use App\Http\Controllers\Admin\PersonalSaludController;
use App\Http\Controllers\Admin\PreparacionController;
use App\Http\Controllers\Admin\ProcedimientoController;
use App\Http\Controllers\Admin\ProfesionalController;
use App\Http\Controllers\Admin\ProveedorController;
use App\Http\Controllers\Admin\RecetaController;
use App\Http\Controllers\Admin\ResponsablePagoController;
use App\Http\Controllers\Admin\SucursalController;
use App\Http\Controllers\Admin\TipoBloqueAnamnesisController;
use App\Http\Controllers\Admin\TipoDocumentoController;
use App\Http\Controllers\Admin\TipoIndicacionController;
use App\Http\Controllers\Admin\TipoRedSocialController;
use App\Http\Controllers\Admin\TurnoController;
use App\Http\Controllers\Admin\UrgenciaController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\GeografiaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VerificarUnicoController;
use App\Http\Middleware\SinAlmacenar;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Opciones del selector de ciudad en cascada (datos públicos, alcanza con estar logueado).
Route::middleware('auth')->prefix('geografia')->name('geografia.')->group(function () {
    Route::get('departamentos', [GeografiaController::class, 'departamentos'])->name('departamentos');
    Route::get('ciudades', [GeografiaController::class, 'ciudades'])->name('ciudades');
});

// Verificación al vuelo de campos únicos (el permiso lo controla App\Support\Unicidad según el campo).
Route::get('verificar-unico', VerificarUnicoController::class)->middleware('auth')->name('verificar-unico');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile/desactivar', [ProfileController::class, 'desactivar'])->name('profile.desactivar');
});

/**
 * Registra el CRUD de una sección de /admin con el permiso que exige cada acción sobre su
 * módulo (código de modulos_sistema): VER para el listado, CREAR para create/store, EDITAR
 * para edit/update y DESACTIVAR para la baja lógica. Nunca hay destroy: no se borra nada.
 */
$seccion = function (string $uri, string $controlador, string $modulo, string $parametro, bool $conBaja) {
    Route::resource($uri, $controlador)
        ->except(['show', 'destroy'])
        ->parameters([$uri => $parametro])
        ->middlewareFor('index', "permiso:{$modulo},VER")
        ->middlewareFor(['create', 'store'], "permiso:{$modulo},CREAR")
        ->middlewareFor(['edit', 'update'], "permiso:{$modulo},EDITAR");

    if ($conBaja) {
        Route::patch("{$uri}/{{$parametro}}/desactivar", [$controlador, 'desactivar'])
            ->name("{$uri}.desactivar")
            ->middleware("permiso:{$modulo},DESACTIVAR");
    }
};

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () use ($seccion) {
    Route::get('usuarios/personas-disponibles', [UsuarioController::class, 'personasDisponibles'])
        ->name('usuarios.personas-disponibles')
        ->middleware('permiso:USUARIOS,CREAR');
    $seccion('usuarios', UsuarioController::class, 'USUARIOS', 'usuario', conBaja: true);
    Route::post('usuarios/{usuario}/invitacion', [UsuarioController::class, 'enviarInvitacion'])
        ->name('usuarios.invitacion')
        ->middleware('permiso:USUARIOS,EDITAR');

    // Auditoría: solo lectura (no hay alta, edición ni baja del log).
    Route::middleware('permiso:AUDITORIA,VER')->group(function () {
        Route::get('auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');
        Route::get('auditoria/{log}', [AuditoriaController::class, 'show'])->name('auditoria.show')->whereNumber('log');
    });

    $seccion('perfiles-acceso', PerfilAccesoController::class, 'PERFILES_ACCESO', 'perfilAcceso', conBaja: true);
    // Matriz de permisos: mirar (VER) y editar los permisos de un perfil (EDITAR; pasa por una confirmación).
    // PERFILES_ACCESO es sensible: abrirla registra VER.
    Route::get('matriz-permisos', [MatrizPermisosController::class, 'index'])->name('matriz-permisos.index')
        ->middleware('permiso:PERFILES_ACCESO,VER');
    Route::put('matriz-permisos/{perfilAcceso}', [MatrizPermisosController::class, 'update'])->name('matriz-permisos.update')
        ->whereNumber('perfilAcceso')->middleware('permiso:PERFILES_ACCESO,EDITAR');
    $seccion('personas', PersonaController::class, 'PERSONAS', 'persona', conBaja: true);
    $seccion('especialidades', EspecialidadController::class, 'ESPECIALIDADES', 'especialidad', conBaja: true);
    $seccion('sucursales', SucursalController::class, 'SUCURSALES', 'sucursal', conBaja: true);
    $seccion('tipos-documento', TipoDocumentoController::class, 'TIPOS_DOCUMENTO', 'tipoDocumento', conBaja: true);
    $seccion('cie10', CatalogoCIE10Controller::class, 'CIE10', 'cie10', conBaja: true);
    $seccion('medios-pago', MedioPagoController::class, 'MEDIOS_PAGO', 'medioPago', conBaja: true);
    $seccion('categorias-gasto', CategoriaGastoController::class, 'CATEGORIAS_GASTO', 'categoriaGasto', conBaja: true);
    $seccion('categorias-proveedor', CategoriaProveedorController::class, 'CATEGORIAS_PROVEEDOR', 'categoriaProveedor', conBaja: true);
    $seccion('tipos-red-social', TipoRedSocialController::class, 'TIPOS_RED_SOCIAL', 'tipoRedSocial', conBaja: true);
    $seccion('procedimientos', ProcedimientoController::class, 'PROCEDIMIENTOS', 'procedimiento', conBaja: true);
    $seccion('tipos-bloque-anamnesis', TipoBloqueAnamnesisController::class, 'TIPOS_BLOQUE_ANAMNESIS', 'tipoBloqueAnamnesis', conBaja: true);
    $seccion('tipos-indicacion', TipoIndicacionController::class, 'TIPOS_INDICACION', 'tipoIndicacion', conBaja: true);
    $seccion('cargos-salud', CargoSaludController::class, 'CARGOS_SALUD', 'cargoSalud', conBaja: true);
    // Parámetros clínicos: un juego fijo (no se crean ni se desactivan); se editan y se aprueban con EDITAR (aprobar,
    // además, siendo Profesional activo: el controlador).
    Route::get('parametros-clinicos', [ParametroClinicoController::class, 'index'])->name('parametros-clinicos.index')->middleware('permiso:PARAMETROS_CLINICOS,VER');
    Route::middleware('permiso:PARAMETROS_CLINICOS,EDITAR')->group(function () {
        Route::get('parametros-clinicos/{parametroClinico}/edit', [ParametroClinicoController::class, 'edit'])->name('parametros-clinicos.edit')->whereNumber('parametroClinico');
        Route::put('parametros-clinicos/{parametroClinico}', [ParametroClinicoController::class, 'update'])->name('parametros-clinicos.update')->whereNumber('parametroClinico');
        Route::post('parametros-clinicos/{parametroClinico}/aprobar', [ParametroClinicoController::class, 'aprobar'])->name('parametros-clinicos.aprobar')->whereNumber('parametroClinico');
    });

    // Geografía: las tres pantallas comparten el módulo GEOGRAFIA.
    $seccion('paises', PaisController::class, 'GEOGRAFIA', 'pais', conBaja: true);
    $seccion('departamentos', DepartamentoController::class, 'GEOGRAFIA', 'departamento', conBaja: true);
    $seccion('ciudades', CiudadController::class, 'GEOGRAFIA', 'ciudad', conBaja: true);

    // Agenda: catálogos, disponibilidades de los profesionales y turnos.
    $seccion('consultorios', ConsultorioController::class, 'CONSULTORIOS', 'consultorio', conBaja: true);
    $seccion('origenes-turno', OrigenTurnoController::class, 'ORIGENES_TURNO', 'origenTurno', conBaja: true);
    Route::get('disponibilidades/profesionales', [DisponibilidadController::class, 'profesionales'])
        ->name('disponibilidades.profesionales')
        ->middleware('permiso:DISPONIBILIDAD,CREAR');
    $seccion('disponibilidades', DisponibilidadController::class, 'DISPONIBILIDAD', 'disponibilidad', conBaja: true);

    // Cupo "entre pacientes" de cada profesional por día (pantalla de Turnos): se mira con VER y se fija con
    // CREAR o EDITAR sobre DISPONIBILIDAD (sin módulo propio).
    Route::get('turnos/cupos', [CupoEntrePacientesController::class, 'index'])->name('turnos.cupos')
        ->middleware('permiso:DISPONIBILIDAD,VER');
    Route::put('turnos/cupos/{profesional}', [CupoEntrePacientesController::class, 'fijar'])->name('turnos.cupos.fijar')->whereNumber('profesional')
        ->middleware('permiso.alguno:DISPONIBILIDAD.CREAR,DISPONIBILIDAD.EDITAR');

    // Urgencias de recepción (sin nada clínico): ver las de hoy, registrar y cancelar una pendiente.
    Route::get('turnos/urgencias', [UrgenciaController::class, 'index'])->name('turnos.urgencias')->middleware('permiso:TURNOS,VER');
    Route::get('turnos/urgencias/create', [UrgenciaController::class, 'create'])->name('turnos.urgencias.create')->middleware('permiso:TURNOS,CREAR');
    Route::post('turnos/urgencias', [UrgenciaController::class, 'store'])->name('turnos.urgencias.store')->middleware('permiso:TURNOS,CREAR');
    Route::patch('turnos/urgencias/{turno}/cancelar', [UrgenciaController::class, 'cancelar'])->name('turnos.urgencias.cancelar')->whereNumber('turno')
        ->middleware('permiso:TURNOS,EDITAR');

    // Turnos: no se editan ni se borran; solo se dan de alta y cambian de estado.
    Route::middleware('permiso:TURNOS,CREAR')->group(function () {
        Route::get('turnos/horarios-disponibles', [TurnoController::class, 'horariosDisponibles'])->name('turnos.horarios-disponibles');
        Route::get('turnos/cupo-del-dia', [TurnoController::class, 'cupoDelDia'])->name('turnos.cupo-del-dia');
        Route::get('turnos/pacientes', [TurnoController::class, 'pacientes'])->name('turnos.pacientes');
        Route::get('turnos/profesionales', [TurnoController::class, 'profesionales'])->name('turnos.profesionales');
    });
    Route::resource('turnos', TurnoController::class)
        ->only(['index', 'create', 'store'])
        ->middlewareFor('index', 'permiso:TURNOS,VER')
        ->middlewareFor(['create', 'store'], 'permiso:TURNOS,CREAR');
    Route::patch('turnos/{turno}/estado', [TurnoController::class, 'cambiarEstado'])
        ->name('turnos.estado')
        ->middleware('permiso:TURNOS,EDITAR');

    // Historia clínica y flujo de atención: se lee con VER. Quién escribe qué (preparación o grupo clínico)
    // lo decide ConsultaPolicy; las acciones las hacen los servicios de App\Support\Atencion. Nada se borra.
    // Ninguna de estas respuestas se guarda en la caché del navegador (SinAlmacenar).
    Route::middleware(SinAlmacenar::class)->group(function () {
        Route::middleware('permiso:HISTORIA_CLINICA,VER')->group(function () {
            // Buscador de CIE-10 del formulario (el controlador exige CREAR o EDITAR).
            Route::get('historias-clinicas/cie10', [ConsultaController::class, 'cie10'])->name('historias-clinicas.cie10');
            Route::get('historias-clinicas', [HistoriaClinicaController::class, 'index'])->name('historias-clinicas.index');
            Route::get('historias-clinicas/{historiaClinica}', [HistoriaClinicaController::class, 'show'])->name('historias-clinicas.show')->whereNumber('historiaClinica');
            Route::get('consultas/{consulta}', [ConsultaController::class, 'show'])->name('consultas.show')->whereNumber('consulta');
        });
        // Detalle de una consulta para el popup de la historia (fragmento). "lectura-ajax": a diferencia de
        // los buscadores, esta petición AJAX es una lectura de contenido clínico y registra VER.
        Route::get('consultas/{consulta}/detalle', [ConsultaController::class, 'detalle'])->name('consultas.detalle')->whereNumber('consulta')
            ->middleware('permiso:HISTORIA_CLINICA,VER,lectura-ajax');
        // Pantalla "Consulta" (la lista del profesional) y sus acciones. Abrirla registra VER sobre consultas
        // ("tabla=consultas"); su actualización automática (AJAX) y el buscador de pacientes, no.
        Route::get('consulta', [AtencionController::class, 'index'])->name('atencion.index')
            ->middleware('permiso:HISTORIA_CLINICA,VER,tabla=consultas');
        Route::get('consulta/pacientes', [AtencionController::class, 'pacientes'])->name('atencion.pacientes')
            ->middleware('permiso:HISTORIA_CLINICA,CREAR');
        // Botones "Entre pacientes (N)" y "Urgencias (N)": los conteos (cada 15 s) y el popup de entre pacientes no
        // registran lecturas (AJAX sin marca); el popup de urgencias trae la observación del triaje y registra el VER
        // de cada consulta mostrada (AtencionController::urgencias).
        Route::middleware('permiso:HISTORIA_CLINICA,VER')->group(function () {
            Route::get('consulta/contadores', [AtencionController::class, 'contadores'])->name('atencion.contadores');
            Route::get('consulta/entre-pacientes', [AtencionController::class, 'entrePacientes'])->name('atencion.entre-pacientes');
            Route::get('consulta/urgencias', [AtencionController::class, 'urgencias'])->name('atencion.urgencias');
        });
        // Las acciones del profesional sobre SUS turnos desde la pantalla Consulta: CREAR sobre HISTORIA_CLINICA
        // (ConsultaPolicy exige además que sea el profesional activo del turno). Los botones manuales de la
        // pantalla Turnos (confirmar, cancelar, ausente) siguen con EDITAR sobre TURNOS.
        Route::middleware('permiso:HISTORIA_CLINICA,CREAR')->group(function () {
            Route::get('consulta/cerrar-jornada', [AtencionController::class, 'vistaCerrarJornada'])->name('atencion.cerrar-jornada');
            Route::post('consulta/cerrar-jornada', [AtencionController::class, 'cerrarJornada'])->name('atencion.cerrar-jornada.confirmar');
            Route::post('turnos/{turno}/no-se-presento', [AtencionController::class, 'noSePresento'])->name('atencion.no-se-presento')->whereNumber('turno');
            Route::post('turnos/{turno}/pasar-ausente', [AtencionController::class, 'pasarAusente'])->name('atencion.pasar-ausente')->whereNumber('turno');
        });
        Route::middleware('permiso:HISTORIA_CLINICA,CREAR')->group(function () {
            Route::post('turnos/{turno}/atender', [AtencionController::class, 'atender'])->name('atencion.atender')->whereNumber('turno');
            Route::post('historias-clinicas/{historiaClinica}/atender', [AtencionController::class, 'atenderSinTurno'])->name('atencion.atender-sin-turno')->whereNumber('historiaClinica');
        });
        // La pantalla de atención de una consulta EN_CURSO (registra VER de la consulta y de su historia).
        Route::get('consultas/{consulta}/atencion', [AtencionController::class, 'pantalla'])->name('consultas.atencion')->whereNumber('consulta')
            ->middleware('permiso:HISTORIA_CLINICA,VER');
        // Finalizar y deshacer: el profesional de la consulta (ConsultaPolicy).
        Route::middleware('permiso.alguno:HISTORIA_CLINICA.CREAR,HISTORIA_CLINICA.EDITAR')->group(function () {
            Route::post('consultas/{consulta}/finalizar', [AtencionController::class, 'finalizar'])->name('consultas.finalizar')->whereNumber('consulta')->middleware('consulta.abierta');
            Route::post('consultas/{consulta}/deshacer', [AtencionController::class, 'deshacer'])->name('consultas.deshacer')->whereNumber('consulta')->middleware('consulta.abierta');
        });
        // Autoguardado (preparación y atención): lo usan la enfermería (PREPARACION) y el profesional; qué
        // campos puede escribir cada uno lo decide ConsultaPolicy. Hasta 30 pedidos por minuto por usuario.
        Route::patch('consultas/{consulta}/autoguardado', [AtencionController::class, 'autoguardar'])->name('consultas.autoguardado')->whereNumber('consulta')
            ->middleware(['permiso.alguno:PREPARACION.EDITAR,HISTORIA_CLINICA.CREAR,HISTORIA_CLINICA.EDITAR', 'throttle:autoguardado', 'consulta.abierta']);
        // Una consulta FINALIZADA (o ANULADA) queda cerrada: no hay edición. Toda ruta de escritura sobre una
        // consulta o sus recetas lleva consulta.abierta (409 si está cerrada); docs/historia-clinica.md.

        // Preparación: la lista de turnos de hoy (VER sobre PREPARACION) y el formulario de anamnesis y signos
        // vitales, que usan la enfermería (PREPARACION) o el profesional del turno (HISTORIA_CLINICA).
        // En pantalla se llama "Triaje" (URLs /triaje); por dentro sigue siendo la "preparación": nombres de ruta
        // admin.preparacion.*, módulo PREPARACION y estado EN_PREPARACION (docs/triaje.md). Las URLs viejas de
        // lectura redirigen (301) a las nuevas: al final del archivo.
        Route::get('triaje', [PreparacionController::class, 'index'])->name('preparacion.index')->middleware('permiso:PREPARACION,VER');
        Route::post('turnos/{turno}/triaje', [PreparacionController::class, 'preparar'])->name('preparacion.preparar')->whereNumber('turno')
            ->middleware('permiso.alguno:PREPARACION.CREAR,HISTORIA_CLINICA.CREAR');
        Route::middleware('permiso.alguno:PREPARACION.EDITAR,HISTORIA_CLINICA.CREAR,HISTORIA_CLINICA.EDITAR')->group(function () {
            Route::get('consultas/{consulta}/triaje', [PreparacionController::class, 'formulario'])->name('preparacion.formulario')->whereNumber('consulta');
            // "Guardar triaje" sin JavaScript (el mismo autoguardado, por un formulario común).
            Route::post('consultas/{consulta}/triaje/guardar', [PreparacionController::class, 'guardar'])->name('preparacion.guardar')->whereNumber('consulta')
                ->middleware(['throttle:autoguardado', 'consulta.abierta']);
            Route::post('consultas/{consulta}/triaje/terminar',[PreparacionController::class, 'marcarLista'])->name('preparacion.lista')->whereNumber('consulta')->middleware('consulta.abierta');
            Route::post('consultas/{consulta}/triaje/reabrir', [PreparacionController::class, 'reabrir'])->name('preparacion.reabrir')->whereNumber('consulta')->middleware('consulta.abierta');
        });

        // Recetas de una consulta (RecetaPolicy: además, solo el profesional que la atiende escribe). VER: la
        // vista previa y la hoja; CREAR: crear el borrador y emitirlo; EDITAR: editar el borrador y anular.
        // No hay DELETE: una receta se anula. Todo menos anular lleva consulta.abierta: con la consulta cerrada,
        // lo único que se puede hacer con una receta es anularla (si está EMITIDA).
        Route::middleware('permiso:RECETAS,VER')->group(function () {
            Route::get('recetas/{receta}/vista-previa', [RecetaController::class, 'vistaPrevia'])->name('recetas.vista-previa')->whereNumber('receta');
            Route::get('recetas/{receta}/imprimir', [RecetaController::class, 'imprimir'])->name('recetas.imprimir')->whereNumber('receta');
        });
        Route::middleware('permiso:RECETAS,CREAR')->group(function () {
            Route::get('consultas/{consulta}/recetas/create', [RecetaController::class, 'create'])->name('recetas.create')->whereNumber('consulta')->middleware('consulta.abierta');
            Route::post('consultas/{consulta}/recetas', [RecetaController::class, 'store'])->name('recetas.store')->whereNumber('consulta')->middleware('consulta.abierta');
            Route::post('recetas/{receta}/emitir', [RecetaController::class, 'emitir'])->name('recetas.emitir')->whereNumber('receta')->middleware('consulta.abierta');
        });
        Route::middleware('permiso:RECETAS,EDITAR')->group(function () {
            Route::get('recetas/{receta}/edit', [RecetaController::class, 'edit'])->name('recetas.edit')->whereNumber('receta')->middleware('consulta.abierta');
            Route::put('recetas/{receta}', [RecetaController::class, 'update'])->name('recetas.update')->whereNumber('receta')->middleware('consulta.abierta');
            Route::post('recetas/{receta}/anular', [RecetaController::class, 'anular'])->name('recetas.anular')->whereNumber('receta');
            // Anular y corregir: además de EDITAR exige CREAR (RecetaPolicy::corregir).
            Route::post('recetas/{receta}/corregir', [RecetaController::class, 'corregir'])->name('recetas.corregir')->whereNumber('receta')->middleware('consulta.abierta');
        });
    });

    // Roles de negocio sobre Persona: además del CRUD, el buscador de personas del formulario de alta.
    foreach ([
        ['pacientes', PacienteController::class, 'PACIENTES', 'paciente'],
        ['profesionales', ProfesionalController::class, 'PROFESIONALES', 'profesional'],
        ['personal-salud', PersonalSaludController::class, 'PERSONAL_SALUD', 'personalSalud'],
        ['proveedores', ProveedorController::class, 'PROVEEDORES', 'proveedor'],
        ['responsables-pago', ResponsablePagoController::class, 'RESPONSABLES_PAGO', 'responsablePago'],
    ] as [$uri, $controlador, $modulo, $parametro]) {
        Route::get("{$uri}/personas-disponibles", [$controlador, 'personasDisponibles'])
            ->name("{$uri}.personas-disponibles")
            ->middleware("permiso:{$modulo},CREAR");
        $seccion($uri, $controlador, $modulo, $parametro, conBaja: true);
    }
});

// "Preparación" pasó a llamarse "Triaje": las URLs viejas de lectura redirigen para siempre (301) a las nuevas.
// Fuera del grupo admin.* (no son pantallas: solo redirigen; la nueva exige sesión y permiso como siempre).
// Solo GET (permanentRedirect registraría todos los verbos, también DELETE).
Route::get('admin/preparacion', fn () => redirect('/admin/triaje', 301));
Route::get('admin/consultas/{consulta}/preparacion', fn (string $consulta) => redirect("/admin/consultas/{$consulta}/triaje", 301))->whereNumber('consulta');

require __DIR__.'/auth.php';
