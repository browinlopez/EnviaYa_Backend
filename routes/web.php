<?php

use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\BuyerController;
use App\Http\Controllers\Admin\CategoryBusinessController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DomiciliaryController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ResidentialComplexController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/* Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard'); */

//dashboard
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::prefix('admin')->name('admin.')->group(function () {

        //compradores
        Route::resource('compradores', BuyerController::class);

        //negocios
        // Listar todos los negocios
        Route::get('negocios', [BusinessController::class, 'index'])->name('negocios.index');

        // Mostrar formulario para crear un negocio
        Route::get('negocios/create', [BusinessController::class, 'create'])->name('negocios.create');

        // Guardar un negocio nuevo
        Route::post('negocios', [BusinessController::class, 'store'])->name('negocios.store');

        // Mostrar un negocio específico
        Route::get('negocios/{business}', [BusinessController::class, 'show'])->name('negocios.show');

        // Mostrar formulario para editar un negocio
        Route::get('negocios/{business}/edit', [BusinessController::class, 'edit'])->name('negocios.edit');

        // Actualizar un negocio
        Route::put('negocios/{business}', [BusinessController::class, 'update'])->name('negocios.update');

        // Eliminar un negocio
        Route::delete('negocios/{business}', [BusinessController::class, 'destroy'])->name('negocios.destroy');

        // domicialiarios
        Route::get('domiciliarios', [DomiciliaryController::class, 'index'])
            ->name('domiciliarios.index');

        Route::get('domiciliarios/create', [DomiciliaryController::class, 'create'])
            ->name('domiciliarios.create');
        Route::post('domiciliarios', [DomiciliaryController::class, 'store'])
            ->name('domiciliarios.store');

        Route::get('domiciliarios/{id}/edit', [DomiciliaryController::class, 'edit'])
            ->name('domiciliarios.edit');
        Route::put('domiciliarios/{id}', [DomiciliaryController::class, 'update'])
            ->name('domiciliarios.update');

        Route::delete('domiciliarios/{id}', [DomiciliaryController::class, 'destroy'])
            ->name('domiciliarios.destroy');

        Route::get('domiciliarios/{id}', [DomiciliaryController::class, 'show'])
            ->name('domiciliarios.show');

        // Categorías de negocio
        Route::get('category-business', [CategoryBusinessController::class, 'index'])
            ->name('category-business.index');

        Route::get('category-business/create', [CategoryBusinessController::class, 'create'])
            ->name('category-business.create');

        Route::post('category-business', [CategoryBusinessController::class, 'store'])
            ->name('category-business.store');

        Route::get('category-business/{id}/edit', [CategoryBusinessController::class, 'edit'])
            ->name('category-business.edit');

        Route::put('category-business/{id}', [CategoryBusinessController::class, 'update'])
            ->name('category-business.update');

        Route::delete('category-business/{id}', [CategoryBusinessController::class, 'destroy'])
            ->name('category-business.destroy');


        // Listar todos los conjuntos
        Route::get('conjuntos', [ResidentialComplexController::class, 'index'])
            ->name('conjuntos.index');

        // Crear conjunto
        Route::get('conjuntos/create', [ResidentialComplexController::class, 'create'])
            ->name('conjuntos.create');
        Route::post('conjuntos', [ResidentialComplexController::class, 'store'])
            ->name('conjuntos.store');

        // Editar conjunto
        Route::get('conjuntos/{id}/edit', [ResidentialComplexController::class, 'edit'])
            ->name('conjuntos.edit');
        Route::put('conjuntos/{id}', [ResidentialComplexController::class, 'update'])
            ->name('conjuntos.update');

        // Eliminar conjunto
        Route::delete('conjuntos/{id}', [ResidentialComplexController::class, 'destroy'])
            ->name('conjuntos.destroy');

        Route::get('productos', [ProductController::class, 'index'])->name('products.index');
        Route::get('productos/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('productos/store', [ProductController::class, 'store'])->name('products.store');
        Route::get('productos/edit/{id}', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('productos/update/{id}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('productos/destroy/{id}', [ProductController::class, 'destroy'])->name('products.destroy');
    });
});

require __DIR__ . '/auth.php';
