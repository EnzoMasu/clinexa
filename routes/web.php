<?php

use App\Http\Controllers\Admin\CatalogoCIE10Controller;
use App\Http\Controllers\Admin\CategoriaGastoController;
use App\Http\Controllers\Admin\EspecialidadController;
use App\Http\Controllers\Admin\MedioPagoController;
use App\Http\Controllers\Admin\PacienteController;
use App\Http\Controllers\Admin\PerfilAccesoController;
use App\Http\Controllers\Admin\PersonaController;
use App\Http\Controllers\Admin\ProcedimientoController;
use App\Http\Controllers\Admin\ProfesionalController;
use App\Http\Controllers\Admin\PropietarioEquipoController;
use App\Http\Controllers\Admin\ProveedorController;
use App\Http\Controllers\Admin\ResponsablePagoController;
use App\Http\Controllers\Admin\SucursalController;
use App\Http\Controllers\Admin\TipoDocumentoController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\GeografiaController;
use App\Http\Controllers\ProfileController;
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

    $seccion('perfiles-acceso', PerfilAccesoController::class, 'PERFILES_ACCESO', 'perfilAcceso', conBaja: true);
    $seccion('personas', PersonaController::class, 'PERSONAS', 'persona', conBaja: true);
    $seccion('especialidades', EspecialidadController::class, 'ESPECIALIDADES', 'especialidad', conBaja: true);
    $seccion('sucursales', SucursalController::class, 'SUCURSALES', 'sucursal', conBaja: true);
    $seccion('tipos-documento', TipoDocumentoController::class, 'TIPOS_DOCUMENTO', 'tipoDocumento', conBaja: true);
    $seccion('cie10', CatalogoCIE10Controller::class, 'CIE10', 'cie10', conBaja: true);
    $seccion('medios-pago', MedioPagoController::class, 'MEDIOS_PAGO', 'medioPago', conBaja: true);
    $seccion('categorias-gasto', CategoriaGastoController::class, 'CATEGORIAS_GASTO', 'categoriaGasto', conBaja: true);
    $seccion('procedimientos', ProcedimientoController::class, 'PROCEDIMIENTOS', 'procedimiento', conBaja: true);

    // Roles de negocio sobre Persona: además del CRUD, el buscador de personas del formulario de alta.
    foreach ([
        ['pacientes', PacienteController::class, 'PACIENTES', 'paciente'],
        ['profesionales', ProfesionalController::class, 'PROFESIONALES', 'profesional'],
        ['proveedores', ProveedorController::class, 'PROVEEDORES', 'proveedor'],
        ['propietarios-equipo', PropietarioEquipoController::class, 'PROPIETARIOS_EQUIPO', 'propietarioEquipo'],
        ['responsables-pago', ResponsablePagoController::class, 'RESPONSABLES_PAGO', 'responsablePago'],
    ] as [$uri, $controlador, $modulo, $parametro]) {
        Route::get("{$uri}/personas-disponibles", [$controlador, 'personasDisponibles'])
            ->name("{$uri}.personas-disponibles")
            ->middleware("permiso:{$modulo},CREAR");
        $seccion($uri, $controlador, $modulo, $parametro, conBaja: true);
    }
});

require __DIR__.'/auth.php';
