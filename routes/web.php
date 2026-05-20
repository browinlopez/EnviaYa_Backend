<?php

use App\Exports\Comercials\ReportGeneralComercial;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\BuyerController;
use App\Http\Controllers\Api\AdminCategoryBusinessController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AdminDomiciliaryController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ResidentialComplexController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationPromptController;
use App\Http\Controllers\Api\VerifyEmailController;
use App\Http\Controllers\Api\AdminOwnerController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home/index');
});
// --- Ruta de verificación para la app móvil (token personalizado) ---
Route::get('/verify-email', [AuthController::class, 'verify'])
    ->name('verify.email');

Route::get('/clear-session', function () {
    auth()->logout(); // cerrar sesión
    session()->flush(); // borrar toda la sesión
    return redirect('/'); // redirige a inicio
});

// --- Dashboard protegido con middleware verified de Laravel ---
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth'])
    ->name('dashboard');


Route::middleware('auth')->group(function () {

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // --- Rutas nativas de Laravel para email verification (solo web) ---
    Route::get('laravel-verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('laravel-verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [AuthController::class, 'resendVerificationEmail'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::prefix('admin')->name('admin.')->group(function () {
        // Aquí van todas tus rutas de admin como ya las tenías
        // Compradores
        Route::resource('compradores', BuyerController::class);

        // Negocios
        Route::prefix('negocios')->group(function () {
            Route::get('/', [BusinessController::class, 'index'])->name('negocios.index');
            Route::get('create', [BusinessController::class, 'create'])->name('negocios.create');
            Route::post('/', [BusinessController::class, 'store'])->name('negocios.store');
            Route::get('/{business}', [BusinessController::class, 'show'])->name('negocios.show');
            Route::get('/{business}/edit', [BusinessController::class, 'edit'])->name('negocios.edit');
            Route::put('/{business}', [BusinessController::class, 'update'])->name('negocios.update');
            Route::delete('/{business}', [BusinessController::class, 'destroy'])->name('negocios.destroy');
        });

        // Domiciliarios
        Route::prefix('domiciliarios')->group(function () {
            Route::get('/', [AdminDomiciliaryController::class, 'index'])->name('domiciliarios.index');
            Route::get('/create', [AdminDomiciliaryController::class, 'create'])->name('domiciliarios.create');
            Route::post('/', [AdminDomiciliaryController::class, 'store'])->name('domiciliarios.store');
            Route::get('/{id}', [AdminDomiciliaryController::class, 'show'])->name('domiciliarios.show');
            Route::get('/{id}/edit', [AdminDomiciliaryController::class, 'edit'])->name('domiciliarios.edit');
            Route::put('/{id}', [AdminDomiciliaryController::class, 'update'])->name('domiciliarios.update');
            Route::delete('/{id}', [AdminDomiciliaryController::class, 'destroy'])->name('domiciliarios.destroy');
        });
        // Categorías de negocio
        Route::prefix('category-business')->group(function () {
            Route::get('/', [AdminCategoryBusinessController::class, 'index'])->name('category-business.index');
            Route::get('/create', [AdminCategoryBusinessController::class, 'create'])->name('category-business.create');
            Route::post('/', [AdminCategoryBusinessController::class, 'store'])->name('category-business.store');
            Route::get('/{id}/edit', [AdminCategoryBusinessController::class, 'edit'])->name('category-business.edit');
            Route::put('/{id}', [AdminCategoryBusinessController::class, 'update'])->name('category-business.update');
            Route::delete('/{id}', [AdminCategoryBusinessController::class, 'destroy'])->name('category-business.destroy');
        });
        // Conjuntos residenciales
        Route::prefix('conjuntos')->group(function () {
            Route::get('/', [ResidentialComplexController::class, 'index'])->name('conjuntos.index');
            Route::get('/create', [ResidentialComplexController::class, 'create'])->name('conjuntos.create');
            Route::post('/', [ResidentialComplexController::class, 'store'])->name('conjuntos.store');
            Route::get('/{id}/edit', [ResidentialComplexController::class, 'edit'])->name('conjuntos.edit');
            Route::put('/{id}', [ResidentialComplexController::class, 'update'])->name('conjuntos.update');
            Route::delete('/{id}', [ResidentialComplexController::class, 'destroy'])->name('conjuntos.destroy');
        });
        // Productos
        Route::prefix('productos')->group(function () {
            Route::get('/', [AdminProductController::class, 'index'])->name('products.index');
            Route::get('/ajax', [AdminProductController::class, 'indexAjax'])->name('product.ajax');
            Route::get('/create', [AdminProductController::class, 'create'])->name('products.create');
            Route::post('/store', [AdminProductController::class, 'store'])->name('products.store');
            Route::get('/edit/{id}', [AdminProductController::class, 'edit'])->name('products.edit');
            Route::put('/update/{id}', [AdminProductController::class, 'update'])->name('products.update');
            Route::delete('/destroy/{id}', [AdminProductController::class, 'destroy'])->name('products.destroy');
        });
        // Importación productos
        Route::prefix('admin/products')->group(function () {
            Route::post('/import', [AdminProductController::class, 'import'])->name('admin.products.import');
            Route::get('/import/preview', [AdminProductController::class, 'importPreview'])->name('admin.products.importPreview');
            Route::post('/import/store', [AdminProductController::class, 'importStore'])->name('admin.products.importStore');
        });
        // Relación propietarios - negocios
        Route::prefix('owners')->group(function () {
            Route::get('/', [AdminOwnerController::class, 'index'])->name('owners.index');
            Route::get('/create', [AdminOwnerController::class, 'create'])->name('owners.create');
            Route::post('/', [AdminOwnerController::class, 'store'])->name('owners.store');
            Route::get('/{owner}/edit', [AdminOwnerController::class, 'edit'])->name('owners.edit');
            Route::put('/{owner}', [AdminOwnerController::class, 'update'])->name('owners.update');
            Route::post('/{owner}/businesses', [AdminOwnerController::class, 'syncBusinesses'])->name('admin.owners.businesses.sync');
        });
        // Reportes
        Route::prefix('reportes')->group(function () {
            Route::get('/financieros', [ReportController::class, 'generalFinancial'])->name('report.general');
            Route::get('/financieros/export', [ReportController::class, 'exportFinancial'])->name('report.export');
            Route::get('/comerciales', [ReportController::class, 'generalCommercials'])->name('reportes.comerciales');
            Route::get('/comerciales/export', [ReportController::class, 'exportComercial'])->name('reportes.comerciales.export');
            Route::get('/operacional', [ReportController::class, 'OperationalCommercials'])->name('reportes.operacional');
            Route::get('/operacional/export', [ReportController::class, 'exportOperational'])->name('reportes.operacional.export');
        });
    });
});

require __DIR__ . '/auth.php';
