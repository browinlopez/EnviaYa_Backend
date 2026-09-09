<?php

use App\Http\Controllers\Auth\AccesoBiometricoController;
use App\Http\Controllers\Domiciliary\PedidosDelDomiciliarioController;
use App\Http\Controllers\Admin\Api\AdminApiController;
use App\Http\Controllers\Admin\Api\AuditoriaApiController;
use App\Http\Controllers\Admin\Api\CategoriasApiController;
use App\Http\Controllers\Admin\Api\ChatsApiController;
use App\Http\Controllers\Admin\Api\ConjuntosApiController;
use App\Http\Controllers\Admin\Api\DomiciliariosApiController;
use App\Http\Controllers\Admin\Api\MediosApiController;
use App\Http\Controllers\Admin\Api\NegociosApiController;
use App\Http\Controllers\Admin\Api\PagosApiController;
use App\Http\Controllers\Admin\Api\PedidosApiController;
use App\Http\Controllers\Admin\Api\ProductosApiController;
use App\Http\Controllers\Admin\Api\PropietariosApiController;
use App\Http\Controllers\Admin\Api\ReportesApiController;
use App\Http\Controllers\Admin\Api\ResenasApiController;
use App\Http\Controllers\Admin\Api\UsuariosApiController;
use App\Http\Controllers\Admin\Api\FacturasApiController;
use App\Http\Controllers\Admin\Api\AjustesApiController;
use App\Http\Controllers\Admin\Api\AreasApiController;
use App\Http\Controllers\Admin\Api\MarketingApiController;
use App\Http\Controllers\Admin\Api\AnunciantesApiController;
use App\Http\Controllers\Admin\Api\BannersApiController;
use App\Http\Controllers\Admin\Api\CampanasApiController;
use App\Http\Controllers\Admin\Api\CuponesApiController;
use App\Http\Controllers\Admin\Api\DestacadosApiController;
use App\Http\Controllers\Admin\Api\OperacionApiController;
use App\Http\Controllers\Admin\Api\DocumentosApiController;
use App\Http\Controllers\Admin\Api\IncidentesApiController;
use App\Http\Controllers\Admin\Api\PqrsApiController;
use App\Http\Controllers\Admin\Api\SolicitudesApiController;
use App\Http\Controllers\Admin\Api\ReportesExcelController;
use App\Http\Controllers\Admin\Api\SeguridadApiController;
use App\Http\Controllers\AppConfigController;
use App\Http\Controllers\ErroresDeLaAppController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ClaveController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\Auth\VerificacionDeCorreoController;
use App\Http\Controllers\Buyer\ResidentialComplexController;
use App\Http\Controllers\Business\AffiliationController;
use App\Http\Controllers\Business\BusinessController;
use App\Http\Controllers\Business\ConsultaDeNegociosController;
use App\Http\Controllers\Business\CategoryBusinessController;
use App\Http\Controllers\Business\FavoriteController;
use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\CoberturaController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\Domiciliary\DomiciliaryController;
use App\Http\Controllers\Domiciliary\IngresosDelDomiciliarioController;
use App\Http\Controllers\Domiciliary\VinculosDelDomiciliarioController;
use App\Http\Controllers\Operacion\EfectivoController;
use App\Http\Controllers\Conjunto\PorteriaController;
use App\Http\Controllers\Conjunto\MiConjuntoController;
use App\Http\Controllers\Conjunto\CeladoresController;
use App\Http\Controllers\Conjunto\ResidentesController;
use App\Http\Controllers\Conjunto\ResumenDelConjuntoController;
use App\Http\Controllers\Conjunto\VisitantesController;
use App\Http\Controllers\Negocio\CargaDeCatalogoController;
use App\Http\Controllers\Negocio\CatalogoController;
use App\Http\Controllers\Negocio\MiNegocioController;
use App\Http\Controllers\Negocio\ProductosDelNegocioController;
use App\Http\Controllers\Negocio\PromocionesController;
use App\Http\Controllers\Order\CotizacionController;
use App\Http\Controllers\LandingRequestController;
use App\Http\Controllers\Marketing\AdsController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\Order\ConsultaDePedidosController;
use App\Http\Controllers\Order\GeolocalizacionDePedidoController;
use App\Http\Controllers\Order\MediosDePagoController;
use App\Http\Controllers\Payment\PaymentController;
use App\Http\Controllers\Payment\BoldWebhookController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Product\DestacadosDeProductoController;
use App\Http\Controllers\Product\EsquemaDeProductoController;
use App\Http\Controllers\Review\ReviewController;
use App\Http\Controllers\Review\ResenasDeDomiciliarioController;
use App\Http\Controllers\Review\ResenasDeNegocioController;
use App\Http\Controllers\Review\ResenasDeUsuarioController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\User\DireccionesController;
use App\Http\Controllers\User\NotificacionesController;
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
    Route::post('/register', [RegistroController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// --- Recuperación de cuenta: pocos intentos, ventana larga (envían correo) ---
Route::middleware('throttle:5,10')->group(function () {
    Route::post('/forgot-password', [ClaveController::class, 'resetPassword']);
    Route::post('/reset-password', [ClaveController::class, 'resetPasswordConfirm']);
    Route::post('/resend-verification-email', [VerificacionDeCorreoController::class, 'resendVerificationEmail']);
    Route::post('/email/resend-verification', [VerificacionDeCorreoController::class, 'resendVerificationEmail']);
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
    Route::get('top-businesses-free', [ConsultaDeNegociosController::class, 'indexByQualification']);
    /*
     * Los puntos del mapa de cobertura de la web pública. Va acá y no bajo
     * `/admin` porque lo consume un sitio sin cuenta; devuelve lo justo
     * para pintar un pin y nada de personas. Ver CoberturaController.
     */
    Route::get('cobertura-free', CoberturaController::class);
    // Las reseñas de un negocio se ven sin sesión (la respuesta no expone
    // datos de contacto del reseñador).
    Route::post('reviews/business/by', [ResenasDeNegocioController::class, 'listReviewsByBusiness']);
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
 * Donde la app avisa de que se rompio.
 *
 * Publico porque puede reventar ANTES de iniciar sesion —resolviendo la sesion
 * guardada, que es justo uno de los sitios donde puede pasar—. El limite es
 * bajo a proposito: escribir sin autenticacion es superficie de ataque, y una
 * app que manda mas de veinte fallos por minuto no esta informando, esta en un
 * bucle.
 */
Route::post('app/errores', ErroresDeLaAppController::class)
    ->middleware('throttle:20,1');

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
     * ENTRAR CON LA HUELLA.
     *
     * `habilitar` se llama con una sesion normal ya abierta —o sea despues de
     * escribir la contrasena— y devuelve el token que el telefono guarda en su
     * llavero, detras de la huella. `entrar` lo cambia por una sesion de
     * verdad. `olvidar` lo mata: es "saca mi cuenta de este telefono".
     *
     * Ese token NO sirve para nada mas, y quien lo impide es
     * `SoloParaCambiarPorSesion`, en el grupo `api` entero: Sanctum guarda las
     * habilidades pero no las aplica por su cuenta.
     */
    Route::post('/biometrico', [AccesoBiometricoController::class, 'habilitar']);
    Route::post('/biometrico/entrar', [AccesoBiometricoController::class, 'entrar']);
    Route::delete('/biometrico', [AccesoBiometricoController::class, 'olvidar']);

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

    /*
     * Pagos (Bold): el usuario siempre está logueado cuando paga.
     * Antes eran públicos: cualquiera podía crear intents de pago.
     *
     * AQUÍ HABÍA DOS RUTAS QUE NO PODÍAN FUNCIONAR, y se retiraron:
     *
     *   POST /bold/payment → PaymentController::makePayment()
     *        Ese método NO EXISTE en el controlador —`makePayment` es de
     *        `BoldService`—, así que la ruta reventaba con un error fatal en
     *        cualquier llamada. No se rompió a nadie al quitarla: pasa de dar
     *        500 a dar 404, que es lo que siempre debió dar.
     *
     *   POST /bold/intent → PaymentController::createIntent()
     *        El método sí existe, pero NO es un manejador de ruta: recibe
     *        `OrdersSales $order` y la URL no lleva parámetro, así que Laravel
     *        le inyectaba un modelo VACÍO. Habría creado una intención de pago
     *        con orden nula y montos en cero, o reventado en `$order->buyer`.
     *
     * Las dos son internas: `OrderController::store` las llama con sus
     * argumentos, que es como están escritas. Exponerlas como endpoint fue un
     * descuido, y nadie las llamaba —ni la app, ni los paneles, ni las pruebas.
     *
     * `status/{ref}` sí es un manejador de verdad y se queda.
     */
    Route::prefix('bold')->group(function () {
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
        Route::get('/notifications', [NotificacionesController::class, 'getNotifications']);
        Route::put('/notifications/read', [NotificacionesController::class, 'markNotificationAsRead']);
        Route::put('/notifications/read-all', [NotificacionesController::class, 'markAllNotificationsAsRead']);

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
        Route::post('/addresses', [DireccionesController::class, 'getAddresses']);
        Route::post('/addresses/add', [DireccionesController::class, 'addAddress']);
        // La comprobación de que la dirección es de quien pide va dentro del
        // controlador: el identificador solo no basta para autorizar.
        Route::delete('/addresses/{id}', [DireccionesController::class, 'deleteAddress']);
        // Perfil buyer
        Route::post('/buyer', [UserController::class, 'getBuyerProfile']);
    });

    //Productos tendero
    Route::prefix('product')->group(function () {
        // Formulario dinámico: campos y categorías según el tipo del negocio
        Route::get('schema', [EsquemaDeProductoController::class, 'schema']);
        Route::post('index', [ProductController::class, 'index']);
        Route::post('create', [ProductController::class, 'store']);
        Route::post('show', [ProductController::class, 'show']);
        Route::put('update', [ProductController::class, 'update']);
        Route::post('top-products', [DestacadosDeProductoController::class, 'topRated']);
        Route::post('topProductsBusiness', [DestacadosDeProductoController::class, 'mostPopularProducts']);
    });

    /*
     * LAS CATEGORIAS SON DE LA PLATAFORMA, NO DE UNA TIENDA.
     *
     * Escribirlas estaba abierto a cualquier sesion: con la cuenta de un
     * comprador se creaban, renombraban y BORRABAN las categorias con las que
     * se organiza el catalogo de todos los negocios. Borrar una deja sin
     * seccion a los productos que colgaban de ella.
     *
     * Leerlas se deja como estaba: la app las necesita para pintar el
     * formulario de un producto, y no dicen nada de nadie.
     */
    Route::prefix('categories')->group(function () {
        Route::get('/show', [CategoryController::class, 'show']);

        Route::middleware('admin')->group(function () {
            Route::post('/create', [CategoryController::class, 'store']);
            Route::put('/update', [CategoryController::class, 'update']);
            Route::delete('/delete', [CategoryController::class, 'destroy']);
        });
    });

    Route::prefix('categories-business')->group(function () {
        Route::get('index', [CategoryBusinessController::class, 'index']);
        Route::post('show', [CategoryBusinessController::class, 'show']);

        Route::middleware('admin')->group(function () {
            Route::post('store', [CategoryBusinessController::class, 'store']);
            Route::post('update', [CategoryBusinessController::class, 'update']);
            Route::post('destroy', [CategoryBusinessController::class, 'destroy']);
        });
    });

    //Ordenes
    Route::prefix('orders')->group(function () {
        Route::post('user', [ConsultaDePedidosController::class, 'ordersUser']); // Usuario comprador
        Route::post('business', [ConsultaDePedidosController::class, 'ordersBusiness']); // Tendero / negocio
        Route::post('IncomeBusiness', [ConsultaDePedidosController::class, 'incomeBusiness']); // Tendero / negocio
        Route::post('orders', [OrderController::class, 'store']); // Crear orden

        /*
         * Cuanto costaria este carrito, sin crear nada.
         *
         * El carrito sumaba precios y anadia la tarifa por su cuenta. Con el
         * descuento por promociones eso ya no se puede: depende de que
         * promociones estan vigentes y cual rebaja mas, que solo sabe el
         * servidor. Devuelve el mismo desglose que se congela al pedir.
         */
        Route::post('cotizar', [CotizacionController::class, 'cotizar']);
        Route::put('update', [OrderController::class, 'updateStatus']); // Crear orden
        // Cancelar es del comprador dueño del pedido y solo antes de que la
        // tienda lo acepte; la comprobación va dentro del controlador.
        Route::put('{id}/cancel', [OrderController::class, 'cancel']);
        Route::post('geolocation', [GeolocalizacionDePedidoController::class, 'storeGeolocation']);
        Route::get('geolocation/latest', [ConsultaDePedidosController::class, 'latest']);
        Route::get('/pending-review', [ConsultaDePedidosController::class, 'ordersPendingReview']);
    });

    Route::get('paymentMethods', [MediosDePagoController::class, 'paymentMethods']); // Listar metodos de pago
    Route::get('paymentForms', [MediosDePagoController::class, 'paymentForms']); // Listar formas de pago

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
        Route::get('index', [ConsultaDeNegociosController::class, 'index']);
        Route::get('top-businesses', [ConsultaDeNegociosController::class, 'indexByQualification']);
        // Dar de alta un aliado es una operacion del equipo: implica
        // contrato y verificacion, no un formulario abierto a cualquiera.
        Route::post('store', [BusinessController::class, 'store'])->middleware('admin');
        Route::post('show', [ConsultaDeNegociosController::class, 'show']);
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

        // El tablero lo ven los dos: el celador también necesita saber cuánto
        // movimiento lleva el turno.
        Route::get('resumen', [ResumenDelConjuntoController::class, 'resumen']);

        /*
         * Los reportes son del ADMINISTRADOR. Un celador tiene delante a quien
         * entra; no le corresponde el histórico del edificio ni con qué
         * frecuencia pide cada torre.
         */
        Route::get('reportes', [ResumenDelConjuntoController::class, 'reporte'])
            ->middleware('conjunto:dueno');
        Route::get('reportes/excel', [ResumenDelConjuntoController::class, 'excel'])
            ->middleware('conjunto:dueno');

        Route::post('porteria/verificar', [PorteriaController::class, 'verificar']);
        Route::get('porteria/entradas', [PorteriaController::class, 'entradas']);

        /*
         * CONTROL DE ACCESO DE QUIEN NO ES USUARIO DE LA PLATAFORMA.
         *
         * Visitas, personal de servicio y domicilios de otras plataformas. No
         * se crea ninguna cuenta: quien viene hoy a ver a su hermana no tiene
         * por que ser usuario de VeciPa'Ya. Sus datos viven en la entrada, que
         * es el hecho que importa.
         *
         * Las registra el CELADOR, que es quien esta en la puerta, asi que no
         * llevan `conjunto:dueno`.
         */
        Route::post('porteria/visitantes', [VisitantesController::class, 'registrar']);
        Route::put('porteria/entradas/{id}/salida', [VisitantesController::class, 'salida']);
        Route::get('porteria/adentro', [VisitantesController::class, 'adentro']);

        // Los celadores los administra el dueño, no el equipo interno: es
        // quien sabe quién trabaja en su portería.
        Route::get('residentes', [ResidentesController::class, 'residentes'])
            ->middleware('conjunto:dueno');
        // El detalle: quienes son, no cuantos. Sin correo ni telefono.
        Route::get('residentes/detalle', [ResidentesController::class, 'residentesDetalle'])
            ->middleware('conjunto:dueno');

        // La ficha del conjunto y su foto: del administrador.
        Route::put('perfil', [MiConjuntoController::class, 'actualizar'])
            ->middleware('conjunto:dueno');
        Route::post('perfil/foto', [MiConjuntoController::class, 'subirFoto'])
            ->middleware('conjunto:dueno');

        Route::get('celadores', [CeladoresController::class, 'celadores'])
            ->middleware('conjunto:dueno');
        Route::post('celadores', [CeladoresController::class, 'crearCelador'])
            ->middleware('conjunto:dueno');
        Route::put('celadores/{id}', [CeladoresController::class, 'cambiarCelador'])
            ->middleware('conjunto:dueno');
    });

    /*
     * PANEL DEL TENDERO. Vive en el mismo panel de aliados que los conjuntos:
     * las dos son gente que trabaja CON la plataforma y no EN ella.
     *
     * Todas estas rutas apuntan a los MISMOS controladores que ya atienden a
     * la app movil. La diferencia esta en la puerta: `negocio` resuelve el
     * local de quien pide contra la cadena de propiedad y lo escribe en la
     * peticion, pisando lo que haya mandado el cliente.
     *
     * Eso importa porque los endpoints originales (`/orders/business`,
     * `/product/index`, `/businesses/update`) reciben el `business_id` en el
     * cuerpo y le creen: con el numero de otra tienda devuelven sus pedidos,
     * con nombre, telefono y direccion de cada comprador. Aca ese numero no se
     * puede elegir.
     */
    Route::prefix('negocio')->middleware('negocio')->group(function () {
        Route::get('me', [MiNegocioController::class, 'mio']);
        Route::put('me', [MiNegocioController::class, 'actualizar']);

        // Pedidos. `updateStatus` ya comprobaba pertenencia por su cuenta
        // —es la unica del grupo que lo hacia— y se reutiliza tal cual.
        Route::get('pedidos', [ConsultaDePedidosController::class, 'ordersBusiness']);
        Route::put('pedidos/estado', [OrderController::class, 'updateStatus']);
        Route::get('ingresos', [ConsultaDePedidosController::class, 'incomeBusiness']);

        /*
         * EL CATÁLOGO, DESDE UNA TIENDA.
         *
         * El tendero ENCUENTRA productos, no los inventa. Antes `POST
         * productos` iba a `ProductController@store`, que recibía nombre y
         * categoría en texto libre y creaba una fila en `products` —el catálogo
         * maestro de toda la plataforma— mientras el `update` de al lado le
         * impedía, con razón, cambiarle una tilde a un producto compartido. Se
         * defendía una puerta y la otra estaba abierta.
         *
         * Ahora `POST productos` recibe `products_id`, `price` y `amount`. Para
         * lo que de verdad no existe está `catalogo/proponer`, que es un camino
         * aparte y deja marcado de dónde salió.
         */
        Route::get('catalogo', [CatalogoController::class, 'buscar']);
        Route::get('catalogo/codigo/{barcode}', [CatalogoController::class, 'porCodigo']);
        Route::post('catalogo/proponer', [CatalogoController::class, 'proponer']);

        Route::get('productos', [ProductosDelNegocioController::class, 'index']);
        Route::post('productos', [ProductosDelNegocioController::class, 'agregar']);

        /*
         * Las rutas fijas van ANTES que `productos/{id}`: si no, «precios» y
         * «copiar» entran por el comodín y llegan al controlador como un id que
         * no es un número.
         */
        Route::put('productos/precios', [ProductosDelNegocioController::class, 'precios']);
        Route::get('productos/tiendas-para-copiar', [ProductosDelNegocioController::class, 'tiendasParaCopiar']);
        Route::post('productos/copiar', [ProductosDelNegocioController::class, 'copiar']);
        Route::get('productos/esquema', [EsquemaDeProductoController::class, 'schema']);

        Route::get('productos/cargas', [CargaDeCatalogoController::class, 'historial']);
        Route::post('productos/cargas', [CargaDeCatalogoController::class, 'subir']);
        Route::get('productos/cargas/plantilla', [CargaDeCatalogoController::class, 'plantilla']);
        Route::get('productos/cargas/{id}', [CargaDeCatalogoController::class, 'ver']);

        Route::put('productos/{id}', [ProductosDelNegocioController::class, 'update']);
        Route::delete('productos/{id}', [ProductosDelNegocioController::class, 'quitar']);

        Route::get('domiciliarios', [DomiciliaryController::class, 'listDomiciliariesByBusiness']);

        Route::get('resenas', [ResenasDeNegocioController::class, 'listReviewsByBusiness']);

        /*
         * Promociones escritas.
         *
         * Corregir y retirar alcanzan al AVISO EN LA CAMPANA del cliente —esas
         * filas son nuestras y se reescriben o se borran—, pero no al zumbido
         * que ya sonó en su teléfono. Los mensajes de la app lo dicen así en
         * vez de prometer que la promoción desaparece del todo.
         */
        Route::get('promociones', [PromocionesController::class, 'index']);
        Route::post('promociones', [PromocionesController::class, 'store']);
        Route::put('promociones/{id}', [PromocionesController::class, 'update']);
        Route::delete('promociones/{id}', [PromocionesController::class, 'destroy']);
    });

    //Domiciliario
    Route::prefix('domiciliaries')->group(function () {
        Route::get('/listDomiciliary', [DomiciliaryController::class, 'listDomiciliary']);      // Listar todos
        Route::get('/listDomiciliariesByBusiness', [DomiciliaryController::class, 'listDomiciliariesByBusiness']);      // Listar todos
        // Dar de alta a un domiciliario ata una persona a la plataforma
        // —contrato, documentos, cuenta para cobrar—: lo hace el equipo.
        Route::post('/createDomiciliary', [DomiciliaryController::class, 'createDomiciliary'])
            ->middleware('admin');
        Route::post('/showDomiciliary', [DomiciliaryController::class, 'showDomiciliary']);      // Obtener uno
        Route::post('/updateDomiciliary', [DomiciliaryController::class, 'updateDomiciliary']);  // Actualizar
        Route::post('/deleteDomiciliary', [DomiciliaryController::class, 'deleteDomiciliary']);  // Eliminar
        Route::post('/assignToBusiness', [VinculosDelDomiciliarioController::class, 'assignToBusiness']);
        Route::post('/listbussiness', [VinculosDelDomiciliarioController::class, 'listBusinessesByDomiciliary']);
        Route::post('/incomeDomiciliary', [IngresosDelDomiciliarioController::class, 'incomeDomiciliary']);

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
        Route::post('/access-code', [IngresosDelDomiciliarioController::class, 'codigoDeAcceso']);
        Route::post('/deposits', [EfectivoController::class, 'declararDeposito']);

        /*
         * SUS pedidos: los que le asignaron y los que puede tomar.
         *
         * Existe porque la app los pedia a `/orders/business`, o sea que se
         * bajaba la lista ENTERA de la tienda —con nombre, telefono y
         * direccion de cada comprador— y filtraba en el telefono. Al cerrar
         * aquel endpoint a quien no es dueno del negocio, el domiciliario se
         * quedo viendo "0 de 3" con cuatro entregas encima.
         *
         * Aca el ambito sale de la sesion, y los datos de la persona solo
         * viajan en los pedidos que YA son suyos.
         */
        Route::get('/pedidos', [PedidosDelDomiciliarioController::class, 'mios']);
    });

    /*
     * RESEÑAS.
     *
     * De las quince de este bloque, los clientes de hoy usan TRES: `store`,
     * `business/by` (público, más arriba) y `domiciliary/by`. Las otras doce
     * son el CRUD completo de antes de que se reescribiera la capa de datos de
     * la app.
     *
     * No se retiran porque la 1.0.8 está publicada en las tiendas y no se puede
     * leer qué llama: borrar una que todavía use le rompe la pantalla a alguien
     * que no puede actualizar hasta que la tienda apruebe la versión nueva.
     *
     * La lista completa, y cómo decidir con el registro de auditoría en la
     * mano, está en `Documentacion/03-Operacion/SUPERFICIE-DE-API-SIN-USO.md`.
     */
    Route::prefix('reviews')->group(function () {
        Route::post('store', [ReviewController::class, 'store']);

        // Negocios
        // (business/by es público, está arriba en la zona de catálogo)
        Route::get('business/all', [ResenasDeNegocioController::class, 'listBusinessReviews']);
        Route::post('business/create', [ResenasDeNegocioController::class, 'createBusinessReview']);
        Route::put('business/update', [ResenasDeNegocioController::class, 'updateBusinessReview']);
        Route::delete('business/delete', [ResenasDeNegocioController::class, 'deleteBusinessReview']);

        // Domiciliarios
        Route::get('domiciliaries/all', [ResenasDeDomiciliarioController::class, 'listDomiciliaryReviews']);
        Route::post('domiciliary/by', [ResenasDeDomiciliarioController::class, 'listReviewsByDomiciliary']);
        Route::post('domiciliary/create', [ResenasDeDomiciliarioController::class, 'createDomiciliaryReview']);
        Route::put('domiciliary/update', [ResenasDeDomiciliarioController::class, 'updateDomiciliaryReview']);
        Route::delete('domiciliary/delete', [ResenasDeDomiciliarioController::class, 'deleteDomiciliaryReview']);

        // Usuarios
        Route::get('users/all', [ResenasDeUsuarioController::class, 'listAllUserReviews']);
        Route::get('user/by', [ResenasDeUsuarioController::class, 'listUserReviewsByUser']);
        Route::post('user/create', [ResenasDeUsuarioController::class, 'createUserReview']);
        Route::put('user/update', [ResenasDeUsuarioController::class, 'updateUserReview']);
        Route::delete('user/delete', [ResenasDeUsuarioController::class, 'deleteUserReview']);
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

        Route::get('users', [UsuariosApiController::class, 'users'])->middleware('modulo:usuarios');
        Route::post('users', [UsuariosApiController::class, 'storeUser'])->middleware('modulo:usuarios,gestionar');
        Route::get('users/{id}', [UsuariosApiController::class, 'showUser'])->middleware('modulo:usuarios');
        Route::put('users/{id}', [UsuariosApiController::class, 'updateUser'])->middleware('modulo:usuarios,gestionar');

        /*
         * Dar por bueno un correo sin que su dueño abra el enlace.
         *
         * Ruta aparte y no un campo más de `updateUser`: no es editar un dato,
         * es afirmar algo que nadie comprobó. Tener su propio verbo la deja
         * visible en el registro de auditoría y permite negarla sin negar el
         * resto de la edición.
         */
        Route::post('users/{id}/verify-email', [UsuariosApiController::class, 'verifyUserEmail'])->middleware('modulo:usuarios,gestionar');

        // Transversal: lo consumen los formularios de negocios, conjuntos y
        // segmentación de banners.
        Route::get('locations', [NegociosApiController::class, 'locations']);

        // Archivos de cualquier entidad: negocios, productos, usuarios, conjuntos.
        Route::get('storage', [MediosApiController::class, 'storageStatus'])->middleware('modulo:ajustes');

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
            Route::get('media/{entidad}/{id}', [MediosApiController::class, 'media']);
            Route::post('media/{entidad}/{id}', [MediosApiController::class, 'uploadMedia']);
            Route::delete('media/{entidad}/{id}', [MediosApiController::class, 'deleteMedia']);
            Route::put('media/{entidad}/{id}/principal', [MediosApiController::class, 'setPrimaryMedia']);
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

        Route::get('businesses', [NegociosApiController::class, 'businesses'])->middleware('modulo:negocios');
        Route::post('businesses', [NegociosApiController::class, 'storeBusiness'])->middleware('modulo:negocios,gestionar');
        Route::get('businesses/{id}', [NegociosApiController::class, 'showBusiness'])->middleware('modulo:negocios');
        Route::put('businesses/{id}', [NegociosApiController::class, 'updateBusiness'])->middleware('modulo:negocios,gestionar');

        // Medios en Cloudflare R2. La subida pasa por el backend porque las
        // llaves del bucket no pueden salir del servidor.
        Route::get('businesses/{id}/media', [NegociosApiController::class, 'businessMedia'])->middleware('modulo:negocios');
        Route::post('businesses/{id}/media', [NegociosApiController::class, 'uploadBusinessMedia'])->middleware('modulo:negocios,gestionar');
        Route::delete('businesses/{id}/media', [NegociosApiController::class, 'deleteBusinessMedia'])->middleware('modulo:negocios,gestionar');

        Route::get('products', [ProductosApiController::class, 'products'])->middleware('modulo:productos');
        // Va ANTES de 'products/{id}': con el orden invertido, Laravel toma
        // "categorias" como identificador y responde 404.
        Route::get('products/categorias', [ProductosApiController::class, 'productCategories'])->middleware('modulo:productos');
        Route::post('products', [ProductosApiController::class, 'storeProduct'])->middleware('modulo:productos,gestionar');
        Route::get('products/{id}', [ProductosApiController::class, 'showProduct'])->middleware('modulo:productos');
        Route::put('products/{id}', [ProductosApiController::class, 'updateProduct'])->middleware('modulo:productos,gestionar');

        Route::get('orders', [PedidosApiController::class, 'orders'])->middleware('modulo:ordenes');
        Route::get('orders/{id}', [PedidosApiController::class, 'showOrder'])->middleware('modulo:ordenes');

        Route::get('domiciliaries', [DomiciliariosApiController::class, 'domiciliaries'])->middleware('modulo:domiciliarios');
        Route::post('domiciliaries', [DomiciliariosApiController::class, 'storeDomiciliary'])->middleware('modulo:domiciliarios,gestionar');
        Route::get('domiciliaries/{id}', [DomiciliariosApiController::class, 'showDomiciliary'])->middleware('modulo:domiciliarios');
        Route::put('domiciliaries/{id}', [DomiciliariosApiController::class, 'updateDomiciliary'])->middleware('modulo:domiciliarios,gestionar');
        Route::post('domiciliaries/{id}/contrato', [DomiciliariosApiController::class, 'signContract'])->middleware('modulo:domiciliarios,gestionar');

        Route::get('payments', [PagosApiController::class, 'payments'])->middleware('modulo:pagos');

        Route::get('reviews', [ResenasApiController::class, 'reviews'])->middleware('modulo:resenas');
        Route::delete('reviews', [ResenasApiController::class, 'deleteReview'])->middleware('modulo:resenas,gestionar');
        // En lote: moderar es un trabajo por tandas, y de a una son cuatro clics
        // por reseña. Con tope de 100 por petición.
        Route::delete('reviews/lote', [ResenasApiController::class, 'deleteReviews'])->middleware('modulo:resenas,gestionar');

        /*
         * Las cuatro van detrás de `categorias`, que NO es `modulo:categorias`:
         * es el middleware que mira el `scope` de la peticion y exige
         * `categorias` o `categorias-negocio` segun cual de los dos catalogos
         * se este tocando. Con la clave fija, `categorias-negocio` era un
         * modulo de la matriz que no protegia ninguna ruta.
         */
        Route::middleware('categorias')->group(function () {
            Route::get('categories', [CategoriasApiController::class, 'categories']);
            Route::post('categories', [CategoriasApiController::class, 'storeCategory']);
            Route::put('categories', [CategoriasApiController::class, 'updateCategory']);
            Route::delete('categories', [CategoriasApiController::class, 'deleteCategory']);
        });

        Route::get('complexes', [ConjuntosApiController::class, 'complexes'])->middleware('modulo:conjuntos');
        Route::post('complexes', [ConjuntosApiController::class, 'storeComplex'])->middleware('modulo:conjuntos,gestionar');
        Route::put('complexes/{id}', [ConjuntosApiController::class, 'updateComplex'])->middleware('modulo:conjuntos,gestionar');
        Route::delete('complexes/{id}', [ConjuntosApiController::class, 'deleteComplex'])->middleware('modulo:conjuntos,gestionar');

        /*
         * El personal del conjunto: quién lo administra y quién está en la
         * portería. Va bajo el módulo `conjuntos` y no bajo `usuarios` a
         * propósito: quien administra los conjuntos es quien tiene que poder
         * darles un responsable, y hasta ahora eso solo se podía por consola
         * (`php artisan conjunto:dueno`).
         */
        Route::get('complexes/{id}/staff', [ConjuntosApiController::class, 'complexStaff'])->middleware('modulo:conjuntos');
        Route::post('complexes/{id}/owner', [ConjuntosApiController::class, 'assignComplexOwner'])->middleware('modulo:conjuntos,gestionar');

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

        Route::get('owners', [PropietariosApiController::class, 'owners'])->middleware('modulo:propietarios');
        Route::get('owners/options', [PropietariosApiController::class, 'ownerOptions'])->middleware('modulo:propietarios');
        Route::post('owners', [PropietariosApiController::class, 'storeOwner'])->middleware('modulo:propietarios,gestionar');
        Route::put('owners/{id}', [PropietariosApiController::class, 'updateOwner'])->middleware('modulo:propietarios,gestionar');

        Route::get('chats', [ChatsApiController::class, 'chats'])->middleware('modulo:chats');
        Route::get('chats/{chatId}/messages', [ChatsApiController::class, 'chatMessages'])->middleware('modulo:chats');

        Route::get('audits', [AuditoriaApiController::class, 'audits'])->middleware('modulo:auditoria');

        /*
         * Va ANTES que `reports/{kind}`: si fuera después, `{kind}` se comería
         * "desglose" y respondería "tipo de reporte no válido".
         */
        Route::get('reports-desglose', [ReportesApiController::class, 'desglose'])
            ->middleware('modulo:reportes');

        Route::get('reports/{kind}', [ReportesApiController::class, 'report'])->middleware('modulo:reportes');
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
            Route::get('documentos', [DocumentosApiController::class, 'documentos'])->middleware('modulo:sst.documentos');
            Route::post('documentos', [DocumentosApiController::class, 'storeDocumento'])->middleware('modulo:sst.documentos,gestionar');
            Route::put('documentos/{id}', [DocumentosApiController::class, 'updateDocumento'])->middleware('modulo:sst.documentos,gestionar');
            Route::delete('documentos/{id}', [DocumentosApiController::class, 'deleteDocumento'])->middleware('modulo:sst.documentos,gestionar');

            Route::get('incidentes', [IncidentesApiController::class, 'incidentes'])->middleware('modulo:sst.incidentes');
            Route::post('incidentes', [IncidentesApiController::class, 'storeIncidente'])->middleware('modulo:sst.incidentes,gestionar');
            Route::put('incidentes/{id}', [IncidentesApiController::class, 'updateIncidente'])->middleware('modulo:sst.incidentes,gestionar');
        });

        Route::prefix('pqrs')->group(function () {
            Route::get('/', [PqrsApiController::class, 'pqrs'])->middleware('modulo:pqrs');
            Route::post('/', [PqrsApiController::class, 'storePqrs'])->middleware('modulo:pqrs,gestionar');
            Route::get('{id}', [PqrsApiController::class, 'showPqrs'])->middleware('modulo:pqrs');
            Route::put('{id}', [PqrsApiController::class, 'updatePqrs'])->middleware('modulo:pqrs,gestionar');
            Route::post('{id}/notes', [PqrsApiController::class, 'addPqrsNote'])->middleware('modulo:pqrs,gestionar');
        });

        /*
         * Las solicitudes de la web. Sin `store`: nacen en el formulario
         * público y no tiene sentido crear una a mano desde el panel; lo que
         * se hace acá es atenderlas.
         */
        Route::prefix('solicitudes')->group(function () {
            Route::get('/', [SolicitudesApiController::class, 'solicitudes'])->middleware('modulo:solicitudes');
            Route::get('{id}', [SolicitudesApiController::class, 'showSolicitud'])->middleware('modulo:solicitudes');
            Route::put('{id}', [SolicitudesApiController::class, 'updateSolicitud'])->middleware('modulo:solicitudes,gestionar');
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

            Route::get('advertisers', [AnunciantesApiController::class, 'advertisers'])->middleware('modulo:marketing.anunciantes');
            Route::post('advertisers', [AnunciantesApiController::class, 'storeAdvertiser'])->middleware('modulo:marketing.anunciantes,gestionar');
            Route::put('advertisers/{id}', [AnunciantesApiController::class, 'updateAdvertiser'])->middleware('modulo:marketing.anunciantes,gestionar');
            Route::delete('advertisers/{id}', [AnunciantesApiController::class, 'deleteAdvertiser'])->middleware('modulo:marketing.anunciantes,gestionar');

            Route::get('campaigns', [CampanasApiController::class, 'campaigns'])->middleware('modulo:marketing.campanas');
            Route::post('campaigns', [CampanasApiController::class, 'storeCampaign'])->middleware('modulo:marketing.campanas,gestionar');
            Route::put('campaigns/{id}', [CampanasApiController::class, 'updateCampaign'])->middleware('modulo:marketing.campanas,gestionar');
            Route::delete('campaigns/{id}', [CampanasApiController::class, 'deleteCampaign'])->middleware('modulo:marketing.campanas,gestionar');

            Route::get('banners', [BannersApiController::class, 'banners'])->middleware('modulo:marketing.banners');
            Route::post('banners', [BannersApiController::class, 'storeBanner'])->middleware('modulo:marketing.banners,gestionar');
            Route::get('banners/{id}', [BannersApiController::class, 'showBanner'])->middleware('modulo:marketing.banners');
            Route::put('banners/{id}', [BannersApiController::class, 'updateBanner'])->middleware('modulo:marketing.banners,gestionar');
            Route::delete('banners/{id}', [BannersApiController::class, 'deleteBanner'])->middleware('modulo:marketing.banners,gestionar');
            Route::get('banners/{id}/metrics', [BannersApiController::class, 'bannerMetrics'])->middleware('modulo:marketing.banners');

            Route::get('coupons', [CuponesApiController::class, 'coupons'])->middleware('modulo:marketing.cupones');
            Route::post('coupons', [CuponesApiController::class, 'storeCoupon'])->middleware('modulo:marketing.cupones,gestionar');
            Route::put('coupons/{id}', [CuponesApiController::class, 'updateCoupon'])->middleware('modulo:marketing.cupones,gestionar');
            Route::delete('coupons/{id}', [CuponesApiController::class, 'deleteCoupon'])->middleware('modulo:marketing.cupones,gestionar');
            Route::get('coupons/{id}/redemptions', [CuponesApiController::class, 'couponRedemptions'])->middleware('modulo:marketing.cupones');

            Route::get('featured', [DestacadosApiController::class, 'featured'])->middleware('modulo:marketing.destacados');
            Route::post('featured', [DestacadosApiController::class, 'storeFeatured'])->middleware('modulo:marketing.destacados,gestionar');
            Route::put('featured/{id}', [DestacadosApiController::class, 'updateFeatured'])->middleware('modulo:marketing.destacados,gestionar');
            Route::delete('featured/{id}', [DestacadosApiController::class, 'deleteFeatured'])->middleware('modulo:marketing.destacados,gestionar');

            Route::get('push', [MarketingApiController::class, 'pushCampaigns'])->middleware('modulo:marketing.notificaciones');
            Route::post('push', [MarketingApiController::class, 'storePushCampaign'])->middleware('modulo:marketing.notificaciones,gestionar');
            Route::post('push/preview', [MarketingApiController::class, 'pushPreview'])->middleware('modulo:marketing.notificaciones');
            Route::put('push/{id}', [MarketingApiController::class, 'updatePushCampaign'])->middleware('modulo:marketing.notificaciones,gestionar');
            Route::post('push/{id}/send', [MarketingApiController::class, 'sendPushCampaign'])->middleware('modulo:marketing.notificaciones,gestionar');
            Route::post('push/{id}/cancel', [MarketingApiController::class, 'cancelPushCampaign'])->middleware('modulo:marketing.notificaciones,gestionar');
        });
    });
});
