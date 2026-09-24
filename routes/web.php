<?php

use App\Http\Controllers\Admin\CatalogoCIE10Controller;
use App\Http\Controllers\Admin\CategoriaGastoController;
use App\Http\Controllers\Admin\EspecialidadController;
use App\Http\Controllers\Admin\MedioPagoController;
use App\Http\Controllers\Admin\PersonaController;
use App\Http\Controllers\Admin\ProcedimientoController;
use App\Http\Controllers\Admin\SucursalController;
use App\Http\Controllers\Admin\TipoDocumentoController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::resource('personas', PersonaController::class)
        ->except(['show', 'destroy']);
    Route::patch('personas/{persona}/desactivar', [PersonaController::class, 'desactivar'])
        ->name('personas.desactivar');

    // Catálogos sin columna estado: sin baja por ahora.
    Route::resource('especialidades', EspecialidadController::class)
        ->except(['show', 'destroy'])->parameters(['especialidades' => 'especialidad']);
    Route::resource('cie10', CatalogoCIE10Controller::class)
        ->except(['show', 'destroy'])->parameters(['cie10' => 'cie10']);
    Route::resource('medios-pago', MedioPagoController::class)
        ->except(['show', 'destroy'])->parameters(['medios-pago' => 'medioPago']);
    Route::resource('categorias-gasto', CategoriaGastoController::class)
        ->except(['show', 'destroy'])->parameters(['categorias-gasto' => 'categoriaGasto']);

    // Catálogos con estado: la baja es lógica (pasa a INACTIVO), nunca se borra.
    Route::resource('sucursales', SucursalController::class)
        ->except(['show', 'destroy'])->parameters(['sucursales' => 'sucursal']);
    Route::patch('sucursales/{sucursal}/desactivar', [SucursalController::class, 'desactivar'])
        ->name('sucursales.desactivar');

    Route::resource('tipos-documento', TipoDocumentoController::class)
        ->except(['show', 'destroy'])->parameters(['tipos-documento' => 'tipoDocumento']);
    Route::patch('tipos-documento/{tipoDocumento}/desactivar', [TipoDocumentoController::class, 'desactivar'])
        ->name('tipos-documento.desactivar');

    Route::resource('procedimientos', ProcedimientoController::class)
        ->except(['show', 'destroy']);
    Route::patch('procedimientos/{procedimiento}/desactivar', [ProcedimientoController::class, 'desactivar'])
        ->name('procedimientos.desactivar');
});

require __DIR__.'/auth.php';
