<?php

use App\Http\Controllers\Admin\Api\AdminApiController;
use App\Http\Controllers\Admin\Api\MarketingApiController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Business\AffiliationController;
use App\Http\Controllers\Business\BusinessController;
use App\Http\Controllers\Business\CategoryBusinessController;
use App\Http\Controllers\Business\FavoriteController;
use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Domiciliary\DomiciliaryController;
use App\Http\Controllers\Marketing\AdsController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\Payment\PaymentController;
use App\Http\Controllers\Payment\BoldWebhookController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Review\ReviewController;
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RUTAS PÚBLICAS (sin token)
|--------------------------------------------------------------------------
| Regla: acá solo va lo que un usuario SIN sesión necesita. Todo lo demás
| vive en el grupo auth:sanctum de abajo. Cada bloque público lleva rate
| limiting (throttle:intentos,minutos) porque son la superficie de ataque.
*/

// --- Autenticación: throttle agresivo contra fuerza bruta ---
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// --- Recuperación de cuenta: pocos intentos, ventana larga (envían correo) ---
Route::middleware('throttle:5,10')->group(function () {
    Route::post('/forgot-password', [AuthController::class, 'resetPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPasswordConfirm']);
    Route::post('/resend-verification-email', [AuthController::class, 'resendVerificationEmail']);
    Route::post('/email/resend-verification', [AuthController::class, 'resendVerificationEmail']);
});

// --- Catálogo público: lo que la app muestra antes de loguearse ---
// 300/min y no menos: la app dispara varias de estas por pantalla, y en
// producción muchos usuarios móviles comparten IP (CGNAT del operador).
Route::middleware('throttle:300,1')->group(function () {
    Route::get('categories-business/indexFree', [CategoryBusinessController::class, 'index']);
    Route::get('categories-free', [CategoryController::class, 'index']);
    Route::get('top-businesses-free', [BusinessController::class, 'indexByQualification']);
    // Las reseñas de un negocio se ven sin sesión (la respuesta no expone
    // datos de contacto del reseñador).
    Route::post('reviews/business/by', [ReviewController::class, 'listReviewsByBusiness']);
});

/*
 * --- Publicidad: la ve todo el mundo, con sesión y sin ella ---
 *
 * Va en la zona pública a propósito: exigir token dejaría sin banners
 * justamente a quien todavía no se ha registrado, que es el público al que más
 * interesa alcanzar. Cuando SÍ llega un token, el controlador lo aprovecha por
 * el guard `sanctum` para segmentar por rol y atribuir el evento.
 *
 * El registro de eventos lleva su propio throttle, mucho más alto que el resto:
 * cada banner que aparece en pantalla dispara una impresión, así que una sola
 * sesión de scroll genera decenas de llamadas legítimas.
 */
Route::middleware('throttle:300,1')->group(function () {
    Route::get('ads/banners', [AdsController::class, 'banners']);
    Route::get('ads/featured', [AdsController::class, 'featured']);
    Route::post('ads/coupons/validate', [AdsController::class, 'validateCoupon']);
});

Route::post('ads/banners/{id}/track', [AdsController::class, 'track'])
    ->middleware('throttle:600,1');

// --- Webhooks de terceros: sin token de usuario, protegidos por firma HMAC ---
Route::post('webhooks/bold', [BoldWebhookController::class, 'handle'])
    ->middleware('throttle:120,1');

/*
|--------------------------------------------------------------------------
| RUTAS AUTENTICADAS (auth:sanctum)
|--------------------------------------------------------------------------
| Todo endpoint nuevo se agrega AQUÍ por defecto. Solo se saca al bloque
| público con una razón explícita (y con throttle).
|
| audit.api registra cada escritura (POST/PUT/PATCH/DELETE) en
| storage/logs/audit-*.log con usuario, ruta, resultado e IP. Los cambios
| de datos además quedan en la tabla `audits` (auditoría de modelos).
*/
Route::middleware(['auth:sanctum', 'audit.api'])->group(function () {
    //Auth
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    //Pagos (Bold): el usuario siempre está logueado cuando paga.
    //Antes eran públicos: cualquiera podía crear intents de pago.
    Route::prefix('bold')->group(function () {
        Route::post('/intent', [PaymentController::class, 'createIntent']);
        Route::post('/payment', [PaymentController::class, 'makePayment']);
        Route::get('/status/{ref}', [PaymentController::class, 'checkStatus']);
    });
    // Alias estilo dev97 que usa la app (GET /payment/status?referenceId=)
    Route::get('payment/status', [PaymentController::class, 'checkStatusByReference']);

    Route::prefix('users')->group(function () {
        // Listar usuarios
        Route::get('/', [UserController::class, 'index']);
        // Detalle usuario
        Route::post('/show', [UserController::class, 'show']);
        // Actualizar el perfil propio (solo datos de contacto)
        Route::put('/update', [UserController::class, 'update']);
        // Cambiar la contraseña propia (exige la actual)
        Route::put('/password', [UserController::class, 'updatePassword'])
            ->middleware('throttle:5,1');
        // Eliminar usuario
        Route::delete('/delete', [UserController::class, 'desactivate']);
        // Direcciones
        Route::post('/addresses', [UserController::class, 'getAddresses']);
        Route::post('/addresses/add', [UserController::class, 'addAddress']);
        // Perfil buyer
        Route::post('/buyer', [UserController::class, 'getBuyerProfile']);
    });

    //Productos tendero
    Route::prefix('product')->group(function () {
        // Formulario dinámico: campos y categorías según el tipo del negocio
        Route::get('schema', [ProductController::class, 'schema']);
        Route::post('index', [ProductController::class, 'index']);
        Route::post('create', [ProductController::class, 'store']);
        Route::post('show', [ProductController::class, 'show']);
        Route::put('update', [ProductController::class, 'update']);
        Route::post('top-products', [ProductController::class, 'topRated']);
        Route::post('topProductsBusiness', [ProductController::class, 'mostPopularProducts']);
    });

    Route::prefix('categories')->group(function () {
        Route::post('/create', [CategoryController::class, 'store']);         // Crear categoría
        Route::get('/show', [CategoryController::class, 'show']); // Mostrar categoría específica
        Route::put('/update', [CategoryController::class, 'update']); // Actualizar categoría
        Route::delete('/delete', [CategoryController::class, 'destroy']); // Eliminar categoría
    });

    Route::prefix('categories-business')->group(function () {
        Route::get('index', [CategoryBusinessController::class, 'index']);
        Route::post('store', [CategoryBusinessController::class, 'store']);
        Route::post('show', [CategoryBusinessController::class, 'show']);
        Route::post('update', [CategoryBusinessController::class, 'update']);
        Route::post('destroy', [CategoryBusinessController::class, 'destroy']);
    });

    //Ordenes
    Route::prefix('orders')->group(function () {
        Route::post('user', [OrderController::class, 'ordersUser']); // Usuario comprador
        Route::post('business', [OrderController::class, 'ordersBusiness']); // Tendero / negocio
        Route::post('IncomeBusiness', [OrderController::class, 'incomeBusiness']); // Tendero / negocio
        Route::post('orders', [OrderController::class, 'store']); // Crear orden
        Route::put('update', [OrderController::class, 'updateStatus']); // Crear orden
        Route::post('geolocation', [OrderController::class, 'storeGeolocation']);
        Route::get('geolocation/latest', [OrderController::class, 'latest']);
        Route::get('/pending-review', [OrderController::class, 'ordersPendingReview']);
    });

    Route::get('paymentMethods', [OrderController::class, 'paymentMethods']); // Listar metodos de pago
    Route::get('paymentForms', [OrderController::class, 'paymentForms']); // Listar formas de pago

    //Chat
    Route::prefix('chats')->group(function () {
        Route::post('/create', [ChatController::class, 'createChat']);     // crear chat
        Route::post('/user-chats', [ChatController::class, 'getUserChats']); // listar chats de un user
        Route::post('/messages', [ChatController::class, 'getMessages']);   // listar mensajes
        Route::post('/updateMessage', [ChatController::class, 'updateMessage']); // Actualizar mensaje estado
        Route::post('/send-message', [ChatController::class, 'sendMessage']); // enviar mensaje
    });

    //Negocios
    Route::prefix('businesses')->group(function () {
        Route::get('index', [BusinessController::class, 'index']);
        Route::get('top-businesses', [BusinessController::class, 'indexByQualification']);
        Route::post('store', [BusinessController::class, 'store']);
        Route::post('show', [BusinessController::class, 'show']);
        Route::put('update', [BusinessController::class, 'update']);
        Route::prefix('affiliations')->group(function () {
            Route::post('/AfiliationUser', [AffiliationController::class, 'AfiliationUser']);
            Route::post('/getAffiliatedUsers', [AffiliationController::class, 'getAffiliatedUsers']);
            Route::post('/DesafiliationUser', [AffiliationController::class, 'DesafiliationUser']);
            Route::post('toggleBusinesses', [AffiliationController::class, 'toggle']);
            Route::get('usersBusinesses', [AffiliationController::class, 'listUsers']);
            Route::get('searchBusinesses', [AffiliationController::class, 'searchBuyerByPhone']);
        });
    });

    Route::prefix('favorites')->group(function () {
        Route::post('toggle', [FavoriteController::class, 'toggleFavorite']);
        Route::post('index', [FavoriteController::class, 'myFavorites']);
    });

    //Domiciliario
    Route::prefix('domiciliaries')->group(function () {
        Route::get('/listDomiciliary', [DomiciliaryController::class, 'listDomiciliary']);      // Listar todos
        Route::get('/listDomiciliariesByBusiness', [DomiciliaryController::class, 'listDomiciliariesByBusiness']);      // Listar todos
        Route::post('/createDomiciliary', [DomiciliaryController::class, 'createDomiciliary']);  // Crear
        Route::post('/showDomiciliary', [DomiciliaryController::class, 'showDomiciliary']);      // Obtener uno
        Route::post('/updateDomiciliary', [DomiciliaryController::class, 'updateDomiciliary']);  // Actualizar
        Route::post('/deleteDomiciliary', [DomiciliaryController::class, 'deleteDomiciliary']);  // Eliminar
        Route::post('/assignToBusiness', [DomiciliaryController::class, 'assignToBusiness']);
        Route::post('/listbussiness', [DomiciliaryController::class, 'listBusinessesByDomiciliary']);
        Route::post('/incomeDomiciliary', [DomiciliaryController::class, 'incomeDomiciliary']);
    });

    //reviews
    Route::prefix('reviews')->group(function () {
        Route::post('store', [ReviewController::class, 'store']);

        // Negocios
        // (business/by es público, está arriba en la zona de catálogo)
        Route::get('business/all', [ReviewController::class, 'listBusinessReviews']);
        Route::post('business/create', [ReviewController::class, 'createBusinessReview']);
        Route::put('business/update', [ReviewController::class, 'updateBusinessReview']);
        Route::delete('business/delete', [ReviewController::class, 'deleteBusinessReview']);

        // Domiciliarios
        Route::get('domiciliaries/all', [ReviewController::class, 'listDomiciliaryReviews']);
        Route::post('domiciliary/by', [ReviewController::class, 'listReviewsByDomiciliary']);
        Route::post('domiciliary/create', [ReviewController::class, 'createDomiciliaryReview']);
        Route::put('domiciliary/update', [ReviewController::class, 'updateDomiciliaryReview']);
        Route::delete('domiciliary/delete', [ReviewController::class, 'deleteDomiciliaryReview']);

        // Usuarios
        Route::get('users/all', [ReviewController::class, 'listAllUserReviews']);
        Route::get('user/by', [ReviewController::class, 'listUserReviewsByUser']);
        Route::post('user/create', [ReviewController::class, 'createUserReview']);
        Route::put('user/update', [ReviewController::class, 'updateUserReview']);
        Route::delete('user/delete', [ReviewController::class, 'deleteUserReview']);
    });

    /*
    |----------------------------------------------------------------------
    | SUPERADMINISTRACIÓN (rol 4)
    |----------------------------------------------------------------------
    | Superficie JSON para el panel de React (EnviaYa_Admin). Los
    | controladores de Admin\* que ya existían sirven al panel Blade de
    | routes/web.php: devuelven vistas y dependen de la sesión, así que no
    | le sirven a un SPA con token.
    |
    | Todo el bloque pasa por el middleware `admin`, que exige rol 4. La
    | validación del cliente es comodidad; esta es la que cuenta.
    */
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('overview', [AdminApiController::class, 'overview']);

        Route::get('users', [AdminApiController::class, 'users']);
        Route::post('users', [AdminApiController::class, 'storeUser']);
        Route::get('users/{id}', [AdminApiController::class, 'showUser']);
        Route::put('users/{id}', [AdminApiController::class, 'updateUser']);

        Route::get('locations', [AdminApiController::class, 'locations']);

        // Archivos de cualquier entidad: negocios, productos, usuarios, conjuntos.
        Route::get('storage', [AdminApiController::class, 'storageStatus']);
        Route::get('media/{entidad}/{id}', [AdminApiController::class, 'media']);
        Route::post('media/{entidad}/{id}', [AdminApiController::class, 'uploadMedia']);
        Route::delete('media/{entidad}/{id}', [AdminApiController::class, 'deleteMedia']);
        Route::put('media/{entidad}/{id}/principal', [AdminApiController::class, 'setPrimaryMedia']);

        Route::get('businesses', [AdminApiController::class, 'businesses']);
        Route::post('businesses', [AdminApiController::class, 'storeBusiness']);
        Route::get('businesses/{id}', [AdminApiController::class, 'showBusiness']);
        Route::put('businesses/{id}', [AdminApiController::class, 'updateBusiness']);

        // Medios en Cloudflare R2. La subida pasa por el backend porque las
        // llaves del bucket no pueden salir del servidor.
        Route::get('businesses/{id}/media', [AdminApiController::class, 'businessMedia']);
        Route::post('businesses/{id}/media', [AdminApiController::class, 'uploadBusinessMedia']);
        Route::delete('businesses/{id}/media', [AdminApiController::class, 'deleteBusinessMedia']);

        Route::get('products', [AdminApiController::class, 'products']);
        Route::post('products', [AdminApiController::class, 'storeProduct']);
        Route::get('products/{id}', [AdminApiController::class, 'showProduct']);
        Route::put('products/{id}', [AdminApiController::class, 'updateProduct']);

        Route::get('orders', [AdminApiController::class, 'orders']);
        Route::get('orders/{id}', [AdminApiController::class, 'showOrder']);

        Route::get('domiciliaries', [AdminApiController::class, 'domiciliaries']);
        Route::post('domiciliaries', [AdminApiController::class, 'storeDomiciliary']);
        Route::get('domiciliaries/{id}', [AdminApiController::class, 'showDomiciliary']);
        Route::put('domiciliaries/{id}', [AdminApiController::class, 'updateDomiciliary']);
        Route::post('domiciliaries/{id}/contrato', [AdminApiController::class, 'signContract']);

        Route::get('payments', [AdminApiController::class, 'payments']);

        Route::get('reviews', [AdminApiController::class, 'reviews']);
        Route::delete('reviews', [AdminApiController::class, 'deleteReview']);

        Route::get('categories', [AdminApiController::class, 'categories']);
        Route::post('categories', [AdminApiController::class, 'storeCategory']);
        Route::put('categories', [AdminApiController::class, 'updateCategory']);
        Route::delete('categories', [AdminApiController::class, 'deleteCategory']);

        Route::get('complexes', [AdminApiController::class, 'complexes']);
        Route::post('complexes', [AdminApiController::class, 'storeComplex']);
        Route::put('complexes/{id}', [AdminApiController::class, 'updateComplex']);
        Route::delete('complexes/{id}', [AdminApiController::class, 'deleteComplex']);

        Route::get('owners', [AdminApiController::class, 'owners']);
        Route::get('owners/options', [AdminApiController::class, 'ownerOptions']);
        Route::post('owners', [AdminApiController::class, 'storeOwner']);
        Route::put('owners/{id}', [AdminApiController::class, 'updateOwner']);

        Route::get('chats', [AdminApiController::class, 'chats']);
        Route::get('chats/{chatId}/messages', [AdminApiController::class, 'chatMessages']);

        Route::get('audits', [AdminApiController::class, 'audits']);

        Route::get('reports/{kind}', [AdminApiController::class, 'report']);

        /*
        |------------------------------------------------------------------
        | MARKETING
        |------------------------------------------------------------------
        | Mismo prefijo y mismo middleware `admin` que el resto del panel:
        | desde afuera es la misma API. Cambia solo el controlador, porque
        | AdminApiController ya es demasiado grande para seguir creciendo.
        |
        | La contraparte pública de estos endpoints —lo que consultan la app
        | y la web— está arriba, bajo `ads/*`.
        */
        Route::prefix('marketing')->group(function () {
            Route::get('overview', [MarketingApiController::class, 'overview']);

            Route::get('advertisers', [MarketingApiController::class, 'advertisers']);
            Route::post('advertisers', [MarketingApiController::class, 'storeAdvertiser']);
            Route::put('advertisers/{id}', [MarketingApiController::class, 'updateAdvertiser']);
            Route::delete('advertisers/{id}', [MarketingApiController::class, 'deleteAdvertiser']);

            Route::get('campaigns', [MarketingApiController::class, 'campaigns']);
            Route::post('campaigns', [MarketingApiController::class, 'storeCampaign']);
            Route::put('campaigns/{id}', [MarketingApiController::class, 'updateCampaign']);
            Route::delete('campaigns/{id}', [MarketingApiController::class, 'deleteCampaign']);

            Route::get('banners', [MarketingApiController::class, 'banners']);
            Route::post('banners', [MarketingApiController::class, 'storeBanner']);
            Route::get('banners/{id}', [MarketingApiController::class, 'showBanner']);
            Route::put('banners/{id}', [MarketingApiController::class, 'updateBanner']);
            Route::delete('banners/{id}', [MarketingApiController::class, 'deleteBanner']);
            Route::get('banners/{id}/metrics', [MarketingApiController::class, 'bannerMetrics']);

            Route::get('coupons', [MarketingApiController::class, 'coupons']);
            Route::post('coupons', [MarketingApiController::class, 'storeCoupon']);
            Route::put('coupons/{id}', [MarketingApiController::class, 'updateCoupon']);
            Route::delete('coupons/{id}', [MarketingApiController::class, 'deleteCoupon']);
            Route::get('coupons/{id}/redemptions', [MarketingApiController::class, 'couponRedemptions']);

            Route::get('featured', [MarketingApiController::class, 'featured']);
            Route::post('featured', [MarketingApiController::class, 'storeFeatured']);
            Route::put('featured/{id}', [MarketingApiController::class, 'updateFeatured']);
            Route::delete('featured/{id}', [MarketingApiController::class, 'deleteFeatured']);

            Route::get('push', [MarketingApiController::class, 'pushCampaigns']);
            Route::post('push', [MarketingApiController::class, 'storePushCampaign']);
            Route::post('push/preview', [MarketingApiController::class, 'pushPreview']);
            Route::put('push/{id}', [MarketingApiController::class, 'updatePushCampaign']);
            Route::post('push/{id}/send', [MarketingApiController::class, 'sendPushCampaign']);
            Route::post('push/{id}/cancel', [MarketingApiController::class, 'cancelPushCampaign']);
        });
    });
});
