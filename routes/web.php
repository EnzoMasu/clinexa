<?php

use App\Http\Controllers\Admin\AtencionSinTurnoController;
use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\CatalogoCIE10Controller;
use App\Http\Controllers\Admin\CategoriaGastoController;
use App\Http\Controllers\Admin\CategoriaProveedorController;
use App\Http\Controllers\Admin\CiudadController;
use App\Http\Controllers\Admin\ConsultaController;
use App\Http\Controllers\Admin\ConsultorioController;
use App\Http\Controllers\Admin\DepartamentoController;
use App\Http\Controllers\Admin\DisponibilidadController;
use App\Http\Controllers\Admin\EspecialidadController;
use App\Http\Controllers\Admin\HistoriaClinicaController;
use App\Http\Controllers\Admin\MedioPagoController;
use App\Http\Controllers\Admin\OrigenTurnoController;
use App\Http\Controllers\Admin\PacienteController;
use App\Http\Controllers\Admin\PaisController;
use App\Http\Controllers\Admin\PerfilAccesoController;
use App\Http\Controllers\Admin\PersonaController;
use App\Http\Controllers\Admin\ProcedimientoController;
use App\Http\Controllers\Admin\ProfesionalController;
use App\Http\Controllers\Admin\ProveedorController;
use App\Http\Controllers\Admin\ResponsablePagoController;
use App\Http\Controllers\Admin\SucursalController;
use App\Http\Controllers\Admin\TipoBloqueAnamnesisController;
use App\Http\Controllers\Admin\TipoDocumentoController;
use App\Http\Controllers\Admin\TipoRedSocialController;
use App\Http\Controllers\Admin\TurnoController;
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

    // Turnos: no se editan ni se borran; solo se dan de alta y cambian de estado.
    Route::middleware('permiso:TURNOS,CREAR')->group(function () {
        Route::get('turnos/horarios-disponibles', [TurnoController::class, 'horariosDisponibles'])->name('turnos.horarios-disponibles');
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

    // Historia clínica: se lee con VER. Las consultas se crean con CREAR y se modifican con EDITAR, y
    // además ConsultaPolicy exige que sea el profesional que atiende. Nada se desactiva ni se borra.
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
        // Atención sin turno (urgencias): el listado es una lectura de consultas ("tabla=consultas"); el
        // buscador de pacientes para iniciar la atención exige CREAR (y como no es VER, no registra).
        Route::get('atencion-sin-turno', [AtencionSinTurnoController::class, 'index'])->name('atencion-sin-turno.index')
            ->middleware('permiso:HISTORIA_CLINICA,VER,tabla=consultas');
        Route::middleware('permiso:HISTORIA_CLINICA,CREAR')->group(function () {
            Route::get('atencion-sin-turno/pacientes', [AtencionSinTurnoController::class, 'pacientes'])->name('atencion-sin-turno.pacientes');
            Route::get('historias-clinicas/{historiaClinica}/consultas/create', [ConsultaController::class, 'create'])->name('consultas.create');
            Route::post('historias-clinicas/{historiaClinica}/consultas', [ConsultaController::class, 'store'])->name('consultas.store');
        });
        Route::middleware('permiso:HISTORIA_CLINICA,EDITAR')->group(function () {
            Route::get('consultas/{consulta}/edit', [ConsultaController::class, 'edit'])->name('consultas.edit');
            Route::put('consultas/{consulta}', [ConsultaController::class, 'update'])->name('consultas.update');
        });
    });

    // Roles de negocio sobre Persona: además del CRUD, el buscador de personas del formulario de alta.
    foreach ([
        ['pacientes', PacienteController::class, 'PACIENTES', 'paciente'],
        ['profesionales', ProfesionalController::class, 'PROFESIONALES', 'profesional'],
        ['proveedores', ProveedorController::class, 'PROVEEDORES', 'proveedor'],
        ['responsables-pago', ResponsablePagoController::class, 'RESPONSABLES_PAGO', 'responsablePago'],
    ] as [$uri, $controlador, $modulo, $parametro]) {
        Route::get("{$uri}/personas-disponibles", [$controlador, 'personasDisponibles'])
            ->name("{$uri}.personas-disponibles")
            ->middleware("permiso:{$modulo},CREAR");
        $seccion($uri, $controlador, $modulo, $parametro, conBaja: true);
    }
});

require __DIR__.'/auth.php';
