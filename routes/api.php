<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Business\BusinessController;
use App\Http\Controllers\Business\CategoryBusinessController;
use App\Http\Controllers\Business\FavoriteController;
use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Domiciliary\DomiciliaryController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\Owner\OwnerController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Review\ReviewController;
use App\Http\Controllers\User\UserController;
use App\Models\OrdersSales;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    //Auth
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('users')->group(function () {
        // Listar usuarios
        Route::get('/', [UserController::class, 'index']);
        // Detalle usuario
        Route::post('/show', [UserController::class, 'show']);
        // Actualizar usuario
        Route::put('/update', [UserController::class, 'update']);
        // Eliminar usuario
        Route::delete('/delete', [UserController::class, 'destroy']);
        // Direcciones
        Route::post('/addresses', [UserController::class, 'getAddresses']);
        Route::post('/addresses/add', [UserController::class, 'addAddress']);
        // Perfil buyer
        Route::post('/buyer', [UserController::class, 'getBuyerProfile']);
    });

    //Productos tendero
    Route::prefix('product')->group(function () {
        Route::post('index', [ProductController::class, 'index']);
        Route::post('create', [ProductController::class, 'store']);
        Route::post('show', [ProductController::class, 'show']);
        Route::put('update', [ProductController::class, 'update']);
        Route::post('top-products', [ProductController::class, 'topRated']);
    });

    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);          // Listar todas las categorías
        Route::post('/create', [CategoryController::class, 'store']);         // Crear categoría
        Route::get('/show', [CategoryController::class, 'show']); // Mostrar categoría específica
        Route::put('/update', [CategoryController::class, 'update']); // Actualizar categoría
        Route::delete('/delete', [CategoryController::class, 'destroy']); // Eliminar categoría
    });

    Route::prefix('categories-business')->group(function () {
        Route::post('index', [CategoryBusinessController::class, 'index']);
        Route::post('store', [CategoryBusinessController::class, 'store']);
        Route::post('show', [CategoryBusinessController::class, 'show']);
        Route::post('update', [CategoryBusinessController::class, 'update']);
        Route::post('destroy', [CategoryBusinessController::class, 'destroy']);
    });

    //Ordenes
    Route::prefix('orders')->group(function () {
        Route::post('user', [OrderController::class, 'ordersUser']); // Usuario comprador
        Route::post('business', [OrderController::class, 'ordersBusiness']); // Tendero / negocio
        Route::post('weeklyIncomeBusiness', [OrderController::class, 'weeklyIncomeBusiness']); // Tendero / negocio
        Route::post('orders', [OrderController::class, 'store']); // Crear orden
        Route::put('update', [OrderController::class, 'updateStatus']); // Crear orden
    });

    Route::get('paymentMethods', [OrderController::class, 'paymentMethods']); // Listar metodos de pago
    Route::get('paymentForms', [OrderController::class, 'paymentForms']); // Listar formas de pago

    //Chat
    Route::prefix('chats')->group(function () {
        Route::post('/create', [ChatController::class, 'createChat']);     // crear chat
        Route::post('/user-chats', [ChatController::class, 'getUserChats']); // listar chats de un user
        Route::post('/messages', [ChatController::class, 'getMessages']);   // listar mensajes
        Route::post('/send-message', [ChatController::class, 'sendMessage']); // enviar mensaje
    });

    //Negocios
    Route::prefix('businesses')->group(function () {
        Route::get('index', [BusinessController::class, 'index']);
        Route::get('top-businesses', [BusinessController::class, 'indexByQualification']);
        Route::post('store', [BusinessController::class, 'store']);
        Route::post('show', [BusinessController::class, 'show']);
        Route::put('update', [BusinessController::class, 'update']);
    });

    Route::prefix('favorites')->group(function () {
        Route::post('toggle', [FavoriteController::class, 'toggleFavorite']);
        Route::post('index', [FavoriteController::class, 'myFavorites']);
    });

    //Dueños
    Route::prefix('owner')->group(function () {
        Route::get('index', [OwnerController::class, 'index']);
        Route::post('store', [OwnerController::class, 'store']);
        Route::post('show', [OwnerController::class, 'show']);
        Route::put('update', [OwnerController::class, 'update']);
    });

    //Domiciliario
    Route::prefix('domiciliaries')->group(function () {
        Route::get('/listDomiciliary', [DomiciliaryController::class, 'listDomiciliary']);      // Listar todos
        Route::post('/createDomiciliary', [DomiciliaryController::class, 'createDomiciliary']);  // Crear
        Route::post('/showDomiciliary', [DomiciliaryController::class, 'showDomiciliary']);      // Obtener uno
        Route::post('/updateDomiciliary', [DomiciliaryController::class, 'updateDomiciliary']);  // Actualizar
        Route::post('/deleteDomiciliary', [DomiciliaryController::class, 'deleteDomiciliary']);  // Eliminar
        Route::post('/assignToBusiness', [DomiciliaryController::class, 'assignToBusiness']);  // Actualizar
        Route::post('/listbussiness', [DomiciliaryController::class, 'listBusinessesByDomiciliary']);  // Eliminar    
    });

    //reviews
    Route::prefix('reviews')->group(function () {
        //Negocios
        Route::get('business', [ReviewController::class, 'listBusinessReviews']); // Listar todas
        Route::post('businessBy', [ReviewController::class, 'listReviewsByBusiness']); // Listar todas
        Route::post('business', [ReviewController::class, 'createBusinessReview']); // Crear
        Route::put('business', [ReviewController::class, 'updateBusinessReview']); // Actualizar
        Route::delete('business/delete', [ReviewController::class, 'deleteBusinessReview']); // Eliminar

        //Domiciliario
        Route::get('domiciliaries', [ReviewController::class, 'listDomiciliaryReviews']); // Listar todas
        Route::get('domiciliary', [ReviewController::class, 'listReviewsByDomiciliary']); // Listar por domiciliario
        Route::post('domiciliary/create', [ReviewController::class, 'createDomiciliaryReview']); // Crear
        Route::put('domiciliary/update', [ReviewController::class, 'updateDomiciliaryReview']); // Actualizar
        Route::delete('domiciliary', [ReviewController::class, 'deleteDomiciliaryReview']); // Eliminar

        //usuario
        Route::get('users', [ReviewController::class, 'listAllUserReviews']); // Listar todas
        Route::get('user', [ReviewController::class, 'listUserReviewsByUser']); // Listar por usuario
        Route::post('user', [ReviewController::class, 'createUserReview']); // Crear
        Route::put('user', [ReviewController::class, 'updateUserReview']); // Actualizar
        Route::delete('user', [ReviewController::class, 'deleteUserReview']); // Eliminar
    });
});
