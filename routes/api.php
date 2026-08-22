<?php

use App\Http\Controllers\Admin\Api\AdminApiController;
use App\Http\Controllers\Admin\Api\FacturasApiController;
use App\Http\Controllers\Admin\Api\AjustesApiController;
use App\Http\Controllers\Admin\Api\AreasApiController;
use App\Http\Controllers\Admin\Api\MarketingApiController;
use App\Http\Controllers\Admin\Api\OperacionApiController;
use App\Http\Controllers\Admin\Api\ReportesExcelController;
use App\Http\Controllers\Admin\Api\SeguridadApiController;
use App\Http\Controllers\AppConfigController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Buyer\ResidentialComplexController;
use App\Http\Controllers\Business\AffiliationController;
use App\Http\Controllers\Business\BusinessController;
use App\Http\Controllers\Business\CategoryBusinessController;
use App\Http\Controllers\Business\FavoriteController;
use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\CoberturaController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\Domiciliary\DomiciliaryController;
use App\Http\Controllers\Operacion\EfectivoController;
use App\Http\Controllers\Conjunto\PorteriaController;
use App\Http\Controllers\Conjunto\MiConjuntoController;
use App\Http\Controllers\LandingRequestController;
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
    /*
     * Los conjuntos van acá y no bajo `/admin` porque la pantalla de registro
     * los necesita ANTES de que exista la cuenta. Solo nombre y dirección: lo
     * justo para elegir uno.
     */
    Route::get('complexes-free', [ResidentialComplexController::class, 'index']);
    Route::get('top-businesses-free', [BusinessController::class, 'indexByQualification']);
    /*
     * Los puntos del mapa de cobertura de la web pública. Va acá y no bajo
     * `/admin` porque lo consume un sitio sin cuenta; devuelve lo justo
     * para pintar un pin y nada de personas. Ver CoberturaController.
     */
    Route::get('cobertura-free', CoberturaController::class);
    // Las reseñas de un negocio se ven sin sesión (la respuesta no expone
    // datos de contacto del reseñador).
    Route::post('reviews/business/by', [ReviewController::class, 'listReviewsByBusiness']);
});

/*
 * --- Los formularios de la web pública ---
 *
 * Van fuera del grupo de arriba porque su límite es otro: aquello son
 * lecturas de catálogo que la app dispara a decenas por pantalla, y esto es
 * una persona escribiendo una vez. Seis por minuto deja margen para
 * reintentar cuando falla la red y corta el envío automático.
 *
 * No exige token a propósito: quien escribe todavía no tiene cuenta, que es
 * justamente el punto. Las defensas están en el controlador.
 */
Route::middleware('throttle:6,1')->group(function () {
    Route::post('solicitudes-free', [LandingRequestController::class, 'store']);
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
 * Lo que la app pregunta ANTES de iniciar sesión: si la plataforma está en
 * mantenimiento y si su versión sigue sirviendo.
 *
 * Público a propósito. Detrás del token, una app vieja que ya no puede
 * autenticarse tampoco podría enterarse de que tiene que actualizarse: se
 * quedaría en un error sin explicación. No devuelve nada sensible —dos banderas,
 * dos versiones y dos mensajes escritos para leerse en pantalla—.
 *
 * Con throttle alto: la app lo consulta al abrir y al volver del fondo, y en
 * producción muchos usuarios móviles comparten IP por el CGNAT del operador.
 */
Route::get('app/config', AppConfigController::class)
    ->middleware('throttle:600,1');

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

    /*
     * El teléfono se registra para poder recibir notificaciones.
     *
     * La app llama al POST al iniciar sesión y cada vez que el proveedor le rota
     * el token; al DELETE al cerrar sesión. Sin esto, el módulo de
     * Notificaciones resuelve segmentos y no le llega a nadie.
     */
    Route::post('devices', [DeviceTokenController::class, 'store']);
    Route::delete('devices', [DeviceTokenController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | Seguridad de la cuenta PROPIA: segundo factor y sesiones abiertas.
    |----------------------------------------------------------------------
    | Sin `modulo:` a propósito: no es una sección del panel, es la cuenta de
    | quien está entrando. No hay forma de tocar el segundo factor de otra
    | persona ni de ver sus sesiones, y eso es deliberado — quien administra
    | accesos reparte áreas, no factores de autenticación ajenos.
    |
    | El throttle es estricto: probar códigos de seis dígitos a ciegas es
    | justamente el ataque contra el que sirve el segundo factor.
    */
    Route::prefix('admin/security')->middleware('throttle:20,1')->group(function () {
        Route::get('/', [SeguridadApiController::class, 'estado']);

        Route::post('two-factor', [SeguridadApiController::class, 'iniciarDosFactores']);
        Route::post('two-factor/confirm', [SeguridadApiController::class, 'confirmarDosFactores']);
        Route::delete('two-factor', [SeguridadApiController::class, 'desactivarDosFactores']);
        Route::post('two-factor/recovery-codes', [SeguridadApiController::class, 'regenerarCodigos']);

        Route::delete('sessions/others', [SeguridadApiController::class, 'cerrarLasDemas']);
        Route::delete('sessions/{id}', [SeguridadApiController::class, 'cerrarSesion']);
    });

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
        /*
         * La campana. Los métodos existían comentados y sin ruta, así que la
         * tabla `notifications` llevaba desde el principio sin usar. Siempre
         * del usuario en sesión: no reciben `user_id`.
         */
        Route::get('/notifications', [UserController::class, 'getNotifications']);
        Route::put('/notifications/read', [UserController::class, 'markNotificationAsRead']);
        Route::put('/notifications/read-all', [UserController::class, 'markAllNotificationsAsRead']);

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
        // La comprobación de que la dirección es de quien pide va dentro del
        // controlador: el identificador solo no basta para autorizar.
        Route::delete('/addresses/{id}', [UserController::class, 'deleteAddress']);
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
        // Cancelar es del comprador dueño del pedido y solo antes de que la
        // tienda lo acepte; la comprobación va dentro del controlador.
        Route::put('{id}/cancel', [OrderController::class, 'cancel']);
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

    /*
     * PANEL DE ALIADOS: dueños de conjunto y celadores.
     *
     * Puerta propia (`conjunto`) y no `admin`: son dos edificios distintos.
     * El middleware deja el conjunto de quien pide en la petición, así que
     * ningún controlador de acá tiene que acordarse de acotar leyendo un
     * parámetro — que es justo como se filtran los datos de otro sin querer.
     */
    Route::prefix('conjunto')->middleware('conjunto')->group(function () {
        Route::get('me', [MiConjuntoController::class, 'mio']);

        Route::post('porteria/verificar', [PorteriaController::class, 'verificar']);
        Route::get('porteria/entradas', [PorteriaController::class, 'entradas']);

        // Los celadores los administra el dueño, no el equipo interno: es
        // quien sabe quién trabaja en su portería.
        Route::get('celadores', [MiConjuntoController::class, 'celadores'])
            ->middleware('conjunto:dueno');
        Route::post('celadores', [MiConjuntoController::class, 'crearCelador'])
            ->middleware('conjunto:dueno');
        Route::put('celadores/{id}', [MiConjuntoController::class, 'cambiarCelador'])
            ->middleware('conjunto:dueno');
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

        /*
         * El efectivo que ESTE domiciliario tiene encima.
         *
         * Sin identificador en la ruta a propósito: sale de la sesión. Con un
         * parámetro, cualquiera podría consultar —o intentar saldar— el saldo
         * de otro.
         */
        Route::get('/cash-balance', [EfectivoController::class, 'miSaldo']);

        /*
         * Su código de entrada a los conjuntos. Caduca en cinco minutos: lo
         * que se tarda en llegar de la moto a la portería.
         */
        Route::post('/access-code', [DomiciliaryController::class, 'codigoDeAcceso']);
        Route::post('/deposits', [EfectivoController::class, 'declararDeposito']);
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
    |
    | Encima va `modulo:<clave>` —y `modulo:<clave>,gestionar` en lo que
    | escribe—, que comprueba el área de la persona contra el catálogo de
    | App\Support\PanelModules. `admin` responde "¿es del equipo?" y `modulo`
    | responde "¿le toca esta sección, y puede tocarla o solo mirarla?".
    |
    | Los endpoints transversales (ubicaciones, medios, mis permisos) no llevan
    | puerta de módulo: son auxiliares de otras pantallas, y ponerles una
    | obligaría a conceder módulos sueltos solo para que cargue un selector.
    */
    Route::prefix('admin')->middleware('admin')->group(function () {
        // Lo que puede el usuario actual. Sin puerta: todo el que entra al
        // panel necesita esto para saber qué menú dibujar.
        Route::get('me/permissions', [AreasApiController::class, 'mios']);

        Route::get('overview', [AdminApiController::class, 'overview'])
            ->middleware('modulo:panel');

        Route::get('users', [AdminApiController::class, 'users'])->middleware('modulo:usuarios');
        Route::post('users', [AdminApiController::class, 'storeUser'])->middleware('modulo:usuarios,gestionar');
        Route::get('users/{id}', [AdminApiController::class, 'showUser'])->middleware('modulo:usuarios');
        Route::put('users/{id}', [AdminApiController::class, 'updateUser'])->middleware('modulo:usuarios,gestionar');

        // Transversal: lo consumen los formularios de negocios, conjuntos y
        // segmentación de banners.
        Route::get('locations', [AdminApiController::class, 'locations']);

        // Archivos de cualquier entidad: negocios, productos, usuarios, conjuntos.
        Route::get('storage', [AdminApiController::class, 'storageStatus'])->middleware('modulo:ajustes');

        /*
        | Ajustes de la plataforma: reglas de la operación y control de la app.
        |
        | Guardar mueve plata (el reparto del domicilio entra en cada pedido
        | nuevo) y puede parar la operación entera (el mantenimiento), así que
        | pide `gestionar` y queda auditado con autor y valor anterior.
        */
        Route::get('settings', [AjustesApiController::class, 'index'])->middleware('modulo:ajustes');
        Route::put('settings', [AjustesApiController::class, 'update'])->middleware('modulo:ajustes,gestionar');
        Route::put('settings/restablecer', [AjustesApiController::class, 'restablecer'])->middleware('modulo:ajustes,gestionar');
        // `medios` traduce la entidad de la URL a su módulo y exige `ver` para
        // consultar y `gestionar` para tocar. Sin esto eran las únicas rutas de
        // /admin sin puerta: cualquiera del equipo podía borrar el logo de un
        // negocio, y el reparto por áreas se saltaba por acá.
        Route::middleware('medios')->group(function () {
            Route::get('media/{entidad}/{id}', [AdminApiController::class, 'media']);
            Route::post('media/{entidad}/{id}', [AdminApiController::class, 'uploadMedia']);
            Route::delete('media/{entidad}/{id}', [AdminApiController::class, 'deleteMedia']);
            Route::put('media/{entidad}/{id}/principal', [AdminApiController::class, 'setPrimaryMedia']);
        });

        /*
        | Comprobantes de entrega.
        |
        | Sin POST: se emiten solos cuando un pedido llega a "entregado". La
        | única escritura es ANULAR, y pide `gestionar` porque deja constancia
        | permanente en la contabilidad.
        */
        Route::get('invoices', [FacturasApiController::class, 'index'])->middleware('modulo:facturas');
        // Recupera las entregas que se quedaron sin comprobante. No crea nada
        // nuevo —solo emite lo que ya está entregado— pero escribe, así que pide
        // `gestionar`.
        Route::post('invoices/emitir-pendientes', [FacturasApiController::class, 'emitirPendientes'])->middleware('modulo:facturas,gestionar');
        Route::get('invoices/{id}', [FacturasApiController::class, 'show'])->middleware('modulo:facturas');
        Route::get('invoices/{id}/pdf', [FacturasApiController::class, 'pdf'])->middleware('modulo:facturas');
        Route::put('invoices/{id}/anular', [FacturasApiController::class, 'anular'])->middleware('modulo:facturas,gestionar');
        // En lote, con un motivo compartido: el error que lleva a anular casi
        // nunca es de un solo comprobante. Tope de 50 por petición.
        Route::put('invoices/anular-lote', [FacturasApiController::class, 'anularLote'])->middleware('modulo:facturas,gestionar');

        Route::get('businesses', [AdminApiController::class, 'businesses'])->middleware('modulo:negocios');
        Route::post('businesses', [AdminApiController::class, 'storeBusiness'])->middleware('modulo:negocios,gestionar');
        Route::get('businesses/{id}', [AdminApiController::class, 'showBusiness'])->middleware('modulo:negocios');
        Route::put('businesses/{id}', [AdminApiController::class, 'updateBusiness'])->middleware('modulo:negocios,gestionar');

        // Medios en Cloudflare R2. La subida pasa por el backend porque las
        // llaves del bucket no pueden salir del servidor.
        Route::get('businesses/{id}/media', [AdminApiController::class, 'businessMedia'])->middleware('modulo:negocios');
        Route::post('businesses/{id}/media', [AdminApiController::class, 'uploadBusinessMedia'])->middleware('modulo:negocios,gestionar');
        Route::delete('businesses/{id}/media', [AdminApiController::class, 'deleteBusinessMedia'])->middleware('modulo:negocios,gestionar');

        Route::get('products', [AdminApiController::class, 'products'])->middleware('modulo:productos');
        // Va ANTES de 'products/{id}': con el orden invertido, Laravel toma
        // "categorias" como identificador y responde 404.
        Route::get('products/categorias', [AdminApiController::class, 'productCategories'])->middleware('modulo:productos');
        Route::post('products', [AdminApiController::class, 'storeProduct'])->middleware('modulo:productos,gestionar');
        Route::get('products/{id}', [AdminApiController::class, 'showProduct'])->middleware('modulo:productos');
        Route::put('products/{id}', [AdminApiController::class, 'updateProduct'])->middleware('modulo:productos,gestionar');

        Route::get('orders', [AdminApiController::class, 'orders'])->middleware('modulo:ordenes');
        Route::get('orders/{id}', [AdminApiController::class, 'showOrder'])->middleware('modulo:ordenes');

        Route::get('domiciliaries', [AdminApiController::class, 'domiciliaries'])->middleware('modulo:domiciliarios');
        Route::post('domiciliaries', [AdminApiController::class, 'storeDomiciliary'])->middleware('modulo:domiciliarios,gestionar');
        Route::get('domiciliaries/{id}', [AdminApiController::class, 'showDomiciliary'])->middleware('modulo:domiciliarios');
        Route::put('domiciliaries/{id}', [AdminApiController::class, 'updateDomiciliary'])->middleware('modulo:domiciliarios,gestionar');
        Route::post('domiciliaries/{id}/contrato', [AdminApiController::class, 'signContract'])->middleware('modulo:domiciliarios,gestionar');

        Route::get('payments', [AdminApiController::class, 'payments'])->middleware('modulo:pagos');

        Route::get('reviews', [AdminApiController::class, 'reviews'])->middleware('modulo:resenas');
        Route::delete('reviews', [AdminApiController::class, 'deleteReview'])->middleware('modulo:resenas,gestionar');
        // En lote: moderar es un trabajo por tandas, y de a una son cuatro clics
        // por reseña. Con tope de 100 por petición.
        Route::delete('reviews/lote', [AdminApiController::class, 'deleteReviews'])->middleware('modulo:resenas,gestionar');

        Route::get('categories', [AdminApiController::class, 'categories'])->middleware('modulo:categorias');
        Route::post('categories', [AdminApiController::class, 'storeCategory'])->middleware('modulo:categorias,gestionar');
        Route::put('categories', [AdminApiController::class, 'updateCategory'])->middleware('modulo:categorias,gestionar');
        Route::delete('categories', [AdminApiController::class, 'deleteCategory'])->middleware('modulo:categorias,gestionar');

        Route::get('complexes', [AdminApiController::class, 'complexes'])->middleware('modulo:conjuntos');
        Route::post('complexes', [AdminApiController::class, 'storeComplex'])->middleware('modulo:conjuntos,gestionar');
        Route::put('complexes/{id}', [AdminApiController::class, 'updateComplex'])->middleware('modulo:conjuntos,gestionar');
        Route::delete('complexes/{id}', [AdminApiController::class, 'deleteComplex'])->middleware('modulo:conjuntos,gestionar');

        /*
        |------------------------------------------------------------------
        | ROLES Y ACCESOS
        |------------------------------------------------------------------
        | La llave que reparte todas las demás. Exige `gestionar` incluso para
        | listar: ver el mapa completo de quién puede qué ya es información
        | que solo le corresponde a Tecnología.
        */
        Route::middleware('modulo:areas,gestionar')->group(function () {
            Route::get('areas', [AreasApiController::class, 'index']);
            Route::post('areas', [AreasApiController::class, 'store']);
            Route::put('areas/{id}', [AreasApiController::class, 'update']);
            Route::delete('areas/{id}', [AreasApiController::class, 'destroy']);

            Route::get('areas-members', [AreasApiController::class, 'miembros']);
            Route::put('areas-members/{userId}', [AreasApiController::class, 'asignarArea']);
        });

        Route::get('owners', [AdminApiController::class, 'owners'])->middleware('modulo:propietarios');
        Route::get('owners/options', [AdminApiController::class, 'ownerOptions'])->middleware('modulo:propietarios');
        Route::post('owners', [AdminApiController::class, 'storeOwner'])->middleware('modulo:propietarios,gestionar');
        Route::put('owners/{id}', [AdminApiController::class, 'updateOwner'])->middleware('modulo:propietarios,gestionar');

        Route::get('chats', [AdminApiController::class, 'chats'])->middleware('modulo:chats');
        Route::get('chats/{chatId}/messages', [AdminApiController::class, 'chatMessages'])->middleware('modulo:chats');

        Route::get('audits', [AdminApiController::class, 'audits'])->middleware('modulo:auditoria');

        /*
         * Va ANTES que `reports/{kind}`: si fuera después, `{kind}` se comería
         * "desglose" y respondería "tipo de reporte no válido".
         */
        Route::get('reports-desglose', [AdminApiController::class, 'desglose'])
            ->middleware('modulo:reportes');

        Route::get('reports/{kind}', [AdminApiController::class, 'report'])->middleware('modulo:reportes');
        // El libro de Excel multi-hoja. Vivía en el panel anterior en Blade, con
        // sesión web y sin autorización por área; acá queda detrás del módulo.
        Route::get('reports/{kind}/excel', ReportesExcelController::class)->middleware('modulo:reportes');

        /*
        |------------------------------------------------------------------
        | SST · CALIDAD · CONTABILIDAD
        |------------------------------------------------------------------
        | Comparten controlador porque comparten forma, pero NO permisos: cada
        | bloque va detrás de su propio módulo, así que SST no alcanza las
        | liquidaciones aunque el código viva al lado.
        */
        Route::prefix('sst')->group(function () {
            Route::get('documentos', [OperacionApiController::class, 'documentos'])->middleware('modulo:sst.documentos');
            Route::post('documentos', [OperacionApiController::class, 'storeDocumento'])->middleware('modulo:sst.documentos,gestionar');
            Route::put('documentos/{id}', [OperacionApiController::class, 'updateDocumento'])->middleware('modulo:sst.documentos,gestionar');
            Route::delete('documentos/{id}', [OperacionApiController::class, 'deleteDocumento'])->middleware('modulo:sst.documentos,gestionar');

            Route::get('incidentes', [OperacionApiController::class, 'incidentes'])->middleware('modulo:sst.incidentes');
            Route::post('incidentes', [OperacionApiController::class, 'storeIncidente'])->middleware('modulo:sst.incidentes,gestionar');
            Route::put('incidentes/{id}', [OperacionApiController::class, 'updateIncidente'])->middleware('modulo:sst.incidentes,gestionar');
        });

        Route::prefix('pqrs')->group(function () {
            Route::get('/', [OperacionApiController::class, 'pqrs'])->middleware('modulo:pqrs');
            Route::post('/', [OperacionApiController::class, 'storePqrs'])->middleware('modulo:pqrs,gestionar');
            Route::get('{id}', [OperacionApiController::class, 'showPqrs'])->middleware('modulo:pqrs');
            Route::put('{id}', [OperacionApiController::class, 'updatePqrs'])->middleware('modulo:pqrs,gestionar');
            Route::post('{id}/notes', [OperacionApiController::class, 'addPqrsNote'])->middleware('modulo:pqrs,gestionar');
        });

        /*
         * Las solicitudes de la web. Sin `store`: nacen en el formulario
         * público y no tiene sentido crear una a mano desde el panel; lo que
         * se hace acá es atenderlas.
         */
        Route::prefix('solicitudes')->group(function () {
            Route::get('/', [OperacionApiController::class, 'solicitudes'])->middleware('modulo:solicitudes');
            Route::get('{id}', [OperacionApiController::class, 'showSolicitud'])->middleware('modulo:solicitudes');
            Route::put('{id}', [OperacionApiController::class, 'updateSolicitud'])->middleware('modulo:solicitudes,gestionar');
        });

        /*
         * Efectivo en la calle. `saldos` responde la pregunta que antes no se
         * podía hacer: cuánto dinero nuestro tiene encima cada domiciliario.
         */
        Route::middleware('modulo:efectivo')->group(function () {
            Route::get('cash-deposits', [EfectivoController::class, 'index']);
            Route::get('cash-balances', [EfectivoController::class, 'saldos']);
        });

        Route::put('cash-deposits/{id}', [EfectivoController::class, 'resolver'])
            ->middleware('modulo:efectivo,gestionar');

        Route::prefix('settlements')->group(function () {
            Route::get('/', [OperacionApiController::class, 'liquidaciones'])->middleware('modulo:liquidaciones');
            Route::post('/', [OperacionApiController::class, 'generarLiquidacion'])->middleware('modulo:liquidaciones,gestionar');
            Route::get('{id}', [OperacionApiController::class, 'showLiquidacion'])->middleware('modulo:liquidaciones');
            Route::put('{id}', [OperacionApiController::class, 'updateLiquidacion'])->middleware('modulo:liquidaciones,gestionar');
            Route::delete('{id}', [OperacionApiController::class, 'deleteLiquidacion'])->middleware('modulo:liquidaciones,gestionar');
        });

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
            Route::get('overview', [MarketingApiController::class, 'overview'])
                ->middleware('modulo:marketing');

            Route::get('advertisers', [MarketingApiController::class, 'advertisers'])->middleware('modulo:marketing.anunciantes');
            Route::post('advertisers', [MarketingApiController::class, 'storeAdvertiser'])->middleware('modulo:marketing.anunciantes,gestionar');
            Route::put('advertisers/{id}', [MarketingApiController::class, 'updateAdvertiser'])->middleware('modulo:marketing.anunciantes,gestionar');
            Route::delete('advertisers/{id}', [MarketingApiController::class, 'deleteAdvertiser'])->middleware('modulo:marketing.anunciantes,gestionar');

            Route::get('campaigns', [MarketingApiController::class, 'campaigns'])->middleware('modulo:marketing.campanas');
            Route::post('campaigns', [MarketingApiController::class, 'storeCampaign'])->middleware('modulo:marketing.campanas,gestionar');
            Route::put('campaigns/{id}', [MarketingApiController::class, 'updateCampaign'])->middleware('modulo:marketing.campanas,gestionar');
            Route::delete('campaigns/{id}', [MarketingApiController::class, 'deleteCampaign'])->middleware('modulo:marketing.campanas,gestionar');

            Route::get('banners', [MarketingApiController::class, 'banners'])->middleware('modulo:marketing.banners');
            Route::post('banners', [MarketingApiController::class, 'storeBanner'])->middleware('modulo:marketing.banners,gestionar');
            Route::get('banners/{id}', [MarketingApiController::class, 'showBanner'])->middleware('modulo:marketing.banners');
            Route::put('banners/{id}', [MarketingApiController::class, 'updateBanner'])->middleware('modulo:marketing.banners,gestionar');
            Route::delete('banners/{id}', [MarketingApiController::class, 'deleteBanner'])->middleware('modulo:marketing.banners,gestionar');
            Route::get('banners/{id}/metrics', [MarketingApiController::class, 'bannerMetrics'])->middleware('modulo:marketing.banners');

            Route::get('coupons', [MarketingApiController::class, 'coupons'])->middleware('modulo:marketing.cupones');
            Route::post('coupons', [MarketingApiController::class, 'storeCoupon'])->middleware('modulo:marketing.cupones,gestionar');
            Route::put('coupons/{id}', [MarketingApiController::class, 'updateCoupon'])->middleware('modulo:marketing.cupones,gestionar');
            Route::delete('coupons/{id}', [MarketingApiController::class, 'deleteCoupon'])->middleware('modulo:marketing.cupones,gestionar');
            Route::get('coupons/{id}/redemptions', [MarketingApiController::class, 'couponRedemptions'])->middleware('modulo:marketing.cupones');

            Route::get('featured', [MarketingApiController::class, 'featured'])->middleware('modulo:marketing.destacados');
            Route::post('featured', [MarketingApiController::class, 'storeFeatured'])->middleware('modulo:marketing.destacados,gestionar');
            Route::put('featured/{id}', [MarketingApiController::class, 'updateFeatured'])->middleware('modulo:marketing.destacados,gestionar');
            Route::delete('featured/{id}', [MarketingApiController::class, 'deleteFeatured'])->middleware('modulo:marketing.destacados,gestionar');

            Route::get('push', [MarketingApiController::class, 'pushCampaigns'])->middleware('modulo:marketing.notificaciones');
            Route::post('push', [MarketingApiController::class, 'storePushCampaign'])->middleware('modulo:marketing.notificaciones,gestionar');
            Route::post('push/preview', [MarketingApiController::class, 'pushPreview'])->middleware('modulo:marketing.notificaciones');
            Route::put('push/{id}', [MarketingApiController::class, 'updatePushCampaign'])->middleware('modulo:marketing.notificaciones,gestionar');
            Route::post('push/{id}/send', [MarketingApiController::class, 'sendPushCampaign'])->middleware('modulo:marketing.notificaciones,gestionar');
            Route::post('push/{id}/cancel', [MarketingApiController::class, 'cancelPushCampaign'])->middleware('modulo:marketing.notificaciones,gestionar');
        });
    });
});
