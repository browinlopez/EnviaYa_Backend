<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentForms;
use App\Models\Payment\PaymentIntent;
use App\Models\Payment\PaymentMethods;
use App\Models\Payment\PaymentTransaction;
use App\Models\User\UserAddress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class OrdersSales extends Audit
{
    use HasFactory;

    protected $table = 'orderssales';

    protected $primaryKey = 'orderSales_id';

    public $timestamps = true;

    /**
     * EL PIN NO VIAJA SOLO.
     *
     * Es la prueba de que el domiciliario estuvo frente al comprador: si
     * saliera en el JSON del pedido que recibe el domiciliario, se lo podría
     * leer de la respuesta y la prueba no probaría nada. Oculto por defecto;
     * quien debe verlo --el comprador y el negocio-- lo recibe a propósito.
     */
    protected $hidden = ['pin_entrega', 'pin_intentos'];

    protected $fillable = [
        'buyer_id',
        'busines_id',
        'domiciliary_id',
        'address_id',
        'methods_id',
        'forms_id',
        'total',
        // Desglose del total: productos + tarifa de domicilio. Se guarda al
        // crear la orden para no tener que reconstruirlo después.
        'subtotal',
        'domicilio',
        // Descuento por cupón y cuál se usó. Se guardan en la orden para que
        // `total` cuadre contra `subtotal + domicilio - discount`: sin esto la
        // diferencia no se puede explicar mirando el pedido.
        'discount',
        'coupon_id',
        // Parte del domicilio que le queda al domiciliario (el resto es de la
        // plataforma). Se calcula al crear la orden y no se recalcula después.
        'domiciliary_fee',
        // Lo que la plataforma le retiene al negocio de esta venta.
        'platform_fee',
        // Lo que la plataforma puso para que el cliente pagara menos domicilio
        // sin que el repartidor cobrara menos. Es el costo de la promoción.
        'delivery_subsidy',
        // Lo que el domiciliario quedó debiendo por cobrar en efectivo.
        'cash_due',
        // El PIN de entrega y sus intentos: ver `$hidden`.
        'pin_entrega',
        'pin_intentos',
        // Cuándo avisó el domiciliario que estaba en la puerta.
        'llego_at',
        // Con qué billete pagó el cliente y cuánto se le devolvió.
        'cash_received',
        'change_given',
        // Con cuánto dijo el cliente que va a pagar, para preparar el cambio.
        'cash_tendered',
        // Si lleva algo al peso y si ya se pesó. Ver la migración.
        'weighing_state',
        'weighed_at',
        'sale_date',
        'delivery_date',
        'is_scheduled',
        'pickup',
        'pickup_time',
        'has_review',
        // Plazo prometido, congelado al despachar. Ver la migración
        // `add_promised_minutes_to_orderssales` para el porqué.
        'promised_minutes',
        // La ruta trazada una sola vez al despachar. Ver `TrazoDeLaRuta`.
        'route_polyline',
        'route_traced_at',
        'state',
        /*
         * `payment_state` FALTABA, y no era un olvido inocuo.
         *
         * Sin estar acá, Eloquent descarta el valor en silencio: ni
         * `create([...])` ni `update([...])` lo escriben. El webhook de la
         * pasarela marca el pago con `$order->update(['payment_state' => ...])`,
         * así que NUNCA llegó a confirmar un pedido —solo funcionaba el camino
         * que asigna el campo directamente—.
         *
         * También explica los pedidos con `payment_state = 'pending'` en la
         * base: es el valor por defecto de la columna, el que queda cuando lo
         * que se pidió al crear se tira a la basura.
         *
         * Ahora que un pedido sin pagar no se le enseña a nadie, esto pasaría
         * de rareza a fallo grave: alguien paga, el webhook confirma, el campo
         * no cambia y el pedido se queda escondido para siempre.
         */
        'payment_state',
        'currency',
    ];

    protected $casts = [
        'is_scheduled' => 'boolean',
        'sale_date' => 'datetime',
        'delivery_date' => 'datetime',
        'dispatched_at' => 'datetime',
        'promised_minutes' => 'integer',
        'route_traced_at' => 'datetime',
        'platform_fee' => 'decimal:2',
        'delivery_subsidy' => 'decimal:2',
        'cash_due' => 'decimal:2',
        'cash_tendered' => 'decimal:2',
        /*
         * EL DINERO DEL PEDIDO, MOLDEADO IGUAL QUE SUS CUATRO HERMANAS.
         *
         * Las cuatro de arriba ya eran `decimal:2` y estas no, y esa
         * incoherencia era un contrato que cambiaba según el motor. Las cinco
         * columnas son `decimal(10,2)`; PDO de MySQL devuelve DECIMAL como
         * CADENA y SQLite lo devuelve numérico, así que sin molde
         * `total` llegaba como "15000.00" en producción y como 15000 en la
         * suite. La forma congelada de las respuestas se tomó sobre SQLite y
         * llevaba meses describiendo una API que no existe.
         *
         * El molde `decimal` de Laravel devuelve cadena en los dos motores, y
         * con el mismo formato que ya manda MySQL --`number_format($v, 2)`--,
         * así que LA RESPUESTA DE PRODUCCIÓN NO CAMBIA: lo que cambia es que
         * la suite por fin mide lo que recibe la app.
         */
        'total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'domicilio' => 'decimal:2',
        'domiciliary_fee' => 'decimal:2',
        'weighing_state' => 'integer',
        'weighed_at' => 'datetime',
    ];

    /**
     * CUMPLIMIENTO DE LA ENTREGA
     *
     * Tres cosas que se preguntan siempre juntas y que hasta ahora cada
     * consumidor calculaba por su cuenta —o no calculaba en absoluto—: cuánto
     * tardó, cuánto se había prometido y si se cumplió.
     *
     * El reloj corre desde `dispatched_at`, no desde que se hizo el pedido: lo
     * que se mide es el trabajo del domiciliario, y hasta que la tienda no
     * despacha no hay nada que entregar. El tiempo que la tienda tarda en
     * aceptar y preparar es de la tienda y se mide aparte.
     */

    /* =====================================================================
       LOS CINCO ESTADOS DE UN PEDIDO, EN UN SOLO SITIO.

       ESTO ESTABA REPARTIDO EN NUEVE DECLARACIONES, y el 14 de noviembre de
       2026 se juntó. `ENTREGADO = 4` vivía como constante PRIVADA en seis
       clases distintas --este modelo, `PedidosDelDomiciliarioController`,
       `HistorialDePedidosController`, `MasVendidosController`,
       `GeneradorDeReportes` y el rasgo `AyudasDeAdmin`--, `CANCELADO = 5` en
       tres y `EN_CAMINO = 3` en dos. Y además había `4` escrito a pelo en
       cinco ficheros más.

       Privada en seis sitios no es encapsulación: es la misma decisión tomada
       seis veces, y el día que un estado cambie de número habrá que acordarse
       de las seis. Lo mismo que pasaba con la ciudad del negocio y con las
       tres listas de `MediaService`.

       VAN EN EL MODELO y no en un catálogo aparte porque son datos DEL
       PEDIDO, que es lo que §07 dice que describe un modelo. Y son públicas
       porque el problema era justamente que no lo fueran.

       LOS NOMBRES NO SE INVENTARON: salen de `ESTADOS_DE_PEDIDO` en
       `EnviaYa_Aliados/src/lib/constants.js`, que es el vocabulario con el
       que el panel los pinta. Dos nombres para lo mismo habría sido cambiar
       una duplicación por otra.
       ===================================================================== */

    /** Recién creado: el cliente espera que la tienda lo acepte. */
    public const NUEVO = 1;

    /** Aceptado por la tienda, en preparación. */
    public const PREPARANDO = 2;

    /** Despachado: lo lleva un domiciliario. */
    public const EN_CAMINO = 3;

    /** Entregado. Es la transición que mueve dinero. */
    public const ENTREGADO = 4;

    /** Cancelado. La fila NO se borra: ver `CancelacionDelPedido`. */
    public const CANCELADO = 5;

    /**
     * Devolviendo: el domiciliario no pudo entregar --ver
     * `NovedadesDeEntrega`-- y lleva el producto y la plata de vuelta al
     * negocio. Termina en CANCELADO cuando el negocio confirma que los
     * recibió; mientras tanto sigue activo: el pedido existe y alguien lo
     * lleva encima.
     */
    public const DEVOLVIENDO = 6;

    /** Los cinco, para recorrerlos o validarlos. */
    public const ESTADOS = [
        self::NUEVO,
        self::PREPARANDO,
        self::EN_CAMINO,
        self::ENTREGADO,
        self::CANCELADO,
        self::DEVOLVIENDO,
    ];

    /**
     * A DONDE SE PUEDE MOVER UN PEDIDO desde el panel o la app.
     *
     * No son todos los estados, y las dos ausencias son deliberadas:
     *
     *   NUEVO      es el estado inicial. Un pedido no "vuelve" a nuevo: eso
     *              seria deshacer que la tienda lo acepto.
     *   CANCELADO  tiene su propio camino --`OrderController::cancel`--, que
     *              devuelve inventario, el uso de la promocion y el cupo de
     *              credito. Llegar ahi por "mover de estado" se saltaria las
     *              tres devoluciones.
     *
     * Existe porque `MoverPedidoRequest` escribia `in:2,3,4`: tres numeros a
     * mano al lado de tres constantes que dicen lo mismo. Que una transicion
     * concreta sea legal AHORA lo sigue decidiendo `ReglasDeDespacho`; esto
     * solo es el vocabulario que la validacion acepta.
     */
    public const DESTINOS_AL_MOVER = [self::PREPARANDO, self::EN_CAMINO, self::ENTREGADO];

    /**
     * LOS PEDIDOS QUE TODAVÍA ESTÁN PASANDO: ni entregados ni cancelados.
     *
     * Era la DÉCIMA declaración de estados, encontrada al mover
     * `AyudasDeAdmin`: vivía como `private const ACTIVOS = [1, 2, 3]` en el
     * rasgo --y por tanto en sus diez consumidores-- más dos literales
     * sueltos, en `ConsultaDePedidosController` y en `ResumenDeArea`.
     *
     * Va con las constantes y no con los tres números: `[1, 2, 3]` escrito a
     * mano no dice QUÉ tienen en común esos tres, y era justo lo que hacía
     * que las copias pasaran desapercibidas. Una lista de constantes es lo
     * más cerca que deja llegar PHP: una expresión constante no puede llamar
     * a `array_diff`, así que los tres hay que nombrarlos.
     */
    public const ACTIVOS = [self::NUEVO, self::PREPARANDO, self::EN_CAMINO, self::DEVOLVIENDO];

    /**
     * CUÁNTO TARDA LA COCINA EN SOLTAR ESTE PEDIDO.
     *
     * EL MÁXIMO Y NO LA SUMA, que es la decisión que importa acá: una cocina
     * no hace los platos en fila, los hace a la vez. El pedido sale cuando
     * está listo el más lento. Sumarlos le prometería dos horas a una mesa de
     * seis y marcaría tarde todo lo que entregara bien.
     *
     * Cero cuando ningún producto lo dice --una tienda, una droguería, o un
     * restaurante que todavía no ha llenado sus platos--, y entonces manda la
     * base de la plataforma, que es lo que pasaba antes de que esto existiera.
     */
    public function minutosDeCocina(): int
    {
        return (int) DB::table('orderssales_detail as d')
            ->join('restaurant_products as r', 'r.products_id', '=', 'd.product_id')
            ->where('d.orderSales_id', $this->orderSales_id)
            ->max('r.minutos_preparacion');
    }

    /**
     * Un pago en línea que todavía no se ha confirmado.
     *
     * Mientras el pedido esté así NO es un pedido: es un intento de compra.
     */
    public const ESPERANDO_PAGO = 'pending_online';

    /*
     * Pagado con el crédito de la tienda: nadie cobra en la puerta, la tienda
     * le cobra al comprador por fuera. NO está en SIN_PAGO: es un pedido de
     * verdad y entra a la liquidación, donde se le descuenta a la tienda.
     */
    public const A_CREDITO = 'store_credit';

    /** El pago en línea se intentó y la pasarela lo rechazó. */
    public const PAGO_RECHAZADO = 'rejected';

    /* =====================================================================
       EL PESAJE

       Una dimensión APARTE del estado del pedido, y no un `state` nuevo: un
       estado nuevo rompería la lista del panel, las columnas del tablero,
       `puedeMoverPedido`, `ReglasDeDespacho`, los informes que filtran por
       estado y las dos apps ya publicadas en las tiendas.
       ===================================================================== */

    /** No lleva nada al peso: la inmensa mayoría de los pedidos. */
    public const SIN_PESAJE = 0;

    /** Lleva algo al peso y todavía no se ha pesado. No se puede despachar. */
    public const POR_PESAR = 1;

    /** Ya se pesó: el pedido puede salir. */
    public const PESADO = 2;

    /** Estados en los que el pedido todavía no es —o ya no será— un pedido. */
    public const SIN_PAGO = [self::ESPERANDO_PAGO, self::PAGO_RECHAZADO];

    /**
     * Los pedidos de verdad. Deja fuera los que esperan un pago en línea.
     *
     * El pedido se crea ANTES de cobrar, porque la pasarela necesita una
     * referencia y porque si el dinero llegara a moverse tiene que haber dónde
     * apuntarlo —un cobro sin registro no se puede devolver ni reconciliar—.
     * Pero hasta que el pago se confirma no debe existir para nadie: la tienda
     * lo veía en su lista y podía ponerse a preparar comida que nadie pagó.
     *
     * Se descartan solo dos estados: el que espera confirmación y el que la
     * pasarela rechazó. Los pagos contra entrega nacen pendientes por
     * definición y son pedidos perfectamente válidos, y los históricos traen
     * valores antiguos (`approved`, `pending`) que no hay que tocar. Cualquier
     * regla del tipo "solo los pagados" los escondería todos.
     */
    public function scopeConfirmados($query)
    {
        // La columna es NOT NULL y con valor por defecto, así que basta con
        // excluir: no hay pedidos sin estado de pago que rescatar.
        return $query->whereNotIn('payment_state', self::SIN_PAGO);
    }

    /** ¿Sigue esperando que se confirme un pago en línea? */
    public function esperandoPago(): bool
    {
        return $this->payment_state === self::ESPERANDO_PAGO;
    }

    /**
     * Minutos reales entre el despacho y la entrega. Null si no se puede saber.
     *
     * Se exige que el pedido esté ENTREGADO, y no solo que tenga las dos
     * marcas. Hasta hace poco `delivery_date` se rellenaba con `now()` al crear
     * el pedido —ya no, pero los pedidos anteriores lo arrastran—, así que uno
     * en camino tenía fecha de entrega anterior a su despacho y esto devolvía
     * un tiempo negativo. En pantalla se leía "Entregado a tiempo · -1392 min"
     * sobre un pedido que todavía iba de camino.
     *
     * La duración negativa se rechaza igualmente: si la entrega precede al
     * despacho, el dato está mal y la respuesta honesta es "no se sabe".
     */
    public function getDeliveryMinutesAttribute(): ?int
    {
        if ((int) $this->state !== self::ENTREGADO) {
            return null;
        }

        if (! $this->dispatched_at || ! $this->delivery_date) {
            return null;
        }

        $minutos = (int) $this->dispatched_at->diffInMinutes($this->delivery_date);

        return $minutos >= 0 ? $minutos : null;
    }

    /**
     * ¿Llegó dentro del plazo prometido?
     *
     * Null —y no `false`— cuando no se puede saber: pedidos sin entregar,
     * entregas anteriores a que existiera el compromiso, o pedidos que el
     * cliente recogió en tienda. Un `false` ahí sería contar como incumplida
     * una entrega sobre la que no hay nada que decir.
     */
    public function getOnTimeAttribute(): ?bool
    {
        $minutos = $this->delivery_minutes;

        if ($minutos === null || ! $this->promised_minutes) {
            return null;
        }

        return $minutos <= $this->promised_minutes;
    }

    /** Minutos de retraso, o 0 si llegó a tiempo. Null si no se puede saber. */
    public function getDelayMinutesAttribute(): ?int
    {
        $minutos = $this->delivery_minutes;

        if ($minutos === null || ! $this->promised_minutes) {
            return null;
        }

        return max(0, $minutos - $this->promised_minutes);
    }

    /**
     * CUÁNTO CAMBIO HAY QUE LLEVARLE.
     *
     * Se calcula y no se guarda: el total puede moverse después de crear el
     * pedido —una promoción que se agota, una corrección— y una cifra
     * congelada acabaría diciendo un número que ya no es verdad, que es peor
     * que no decir ninguno.
     *
     * `null` en dos casos distintos, y la pantalla los distingue mirando
     * `cash_tendered`:
     *
     *   · no dijo con cuánto paga     → `cash_tendered` también es null;
     *   · paga con lo justo, o con    → `cash_tendered` tiene valor.
     *     menos de lo que vale
     *
     * No se devuelve un negativo: «lleva -$3.000 de cambio» no significa nada.
     * Que el cliente se quede corto se resuelve en la puerta, no en un
     * renglón.
     */
    public function getChangeDueAttribute(): ?float
    {
        if ($this->cash_tendered === null) {
            return null;
        }

        $cambio = round((float) $this->cash_tendered - (float) $this->total, 2);

        return $cambio > 0 ? $cambio : null;
    }

    /** @return HasMany<PaymentIntent, $this> */
    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class, 'orderSales_id');
    }

    /**
     * Los movimientos de la pasarela de ESTE pedido, pasando por el intento.
     *
     * Los genericos de `hasManyThrough` llevan TRES parametros y no dos: lo
     * que se alcanza, por donde se pasa, y desde donde. Es la unica relacion
     * del repositorio con esta forma, y por eso se escribio a mano.
     *
     * @return HasManyThrough<PaymentTransaction, PaymentIntent, $this>
     */
    public function paymentTransactions(): HasManyThrough
    {
        return $this->hasManyThrough(
            PaymentTransaction::class,
            PaymentIntent::class,
            'orderSales_id',
            'payment_intent_id'
        );
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        // el buyer_id de orderssales apunta al buyer_id de la tabla buyer
        return $this->belongsTo(Buyer::class, 'buyer_id', 'buyer_id');
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    /** @return BelongsTo<Domiciliary, $this> */
    public function domiciliary(): BelongsTo
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    /** @return HasMany<OrdersSalesDetail, $this> */
    public function details(): HasMany
    {
        return $this->hasMany(OrdersSalesDetail::class, 'orderSales_id', 'orderSales_id');
    }

    /** @return HasMany<OrderPromotion, $this> */
    public function promotions(): HasMany
    {
        return $this->hasMany(OrderPromotion::class, 'orderSales_id', 'orderSales_id');
    }

    /*
     * `hasOne` y la clave se llama `payments`, en plural. Se queda: es el
     * nombre que leen las apps publicadas en `'payments' => ...`, y
     * renombrarlo cambia la respuesta.
     */
    /** @return HasOne<Payment, $this> */
    public function payments(): HasOne
    {
        return $this->hasOne(Payment::class, 'orderSales_id', 'orderSales_id');
    }

    /** @return BelongsTo<PaymentMethods, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethods::class, 'methods_id');
    }

    /** @return BelongsTo<PaymentForms, $this> */
    public function paymentForm(): BelongsTo
    {
        return $this->belongsTo(PaymentForms::class, 'forms_id');
    }

    /** @return BelongsTo<UserAddress, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id', 'address_id');
    }
}
