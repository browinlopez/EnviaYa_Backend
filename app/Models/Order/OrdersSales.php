<?php

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
use App\Models\User;
use App\Models\User\UserAddress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrdersSales extends Audit
{
    use HasFactory;

    protected $table = 'orderssales';
    protected $primaryKey = 'orderSales_id';
    public $timestamps = true;

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
        'sale_date',
        'delivery_date',
        'is_scheduled',
        'pickup',
        'pickup_time',
        'has_review',
        // Plazo prometido, congelado al despachar. Ver la migración
        // `add_promised_minutes_to_orderssales` para el porqué.
        'promised_minutes',
        'state'
    ];

    protected $casts = [
        'is_scheduled' => 'boolean',
        'sale_date' => 'datetime',
        'delivery_date' => 'datetime',
        'dispatched_at' => 'datetime',
        'promised_minutes' => 'integer',
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

    /** Estado 4: entregado. */
    private const ENTREGADO = 4;

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

        if (!$this->dispatched_at || !$this->delivery_date) {
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

        if ($minutos === null || !$this->promised_minutes) {
            return null;
        }

        return $minutos <= $this->promised_minutes;
    }

    /** Minutos de retraso, o 0 si llegó a tiempo. Null si no se puede saber. */
    public function getDelayMinutesAttribute(): ?int
    {
        $minutos = $this->delivery_minutes;

        if ($minutos === null || !$this->promised_minutes) {
            return null;
        }

        return max(0, $minutos - $this->promised_minutes);
    }

    public function paymentIntents()
    {
        return $this->hasMany(\App\Models\Payment\PaymentIntent::class, 'orderSales_id');
    }

    public function paymentTransactions()
    {
        return $this->hasManyThrough(
            PaymentTransaction::class,
            PaymentIntent::class,
            'orderSales_id',
            'payment_intent_id'
        );
    }


    public function buyer()
    {
        // el buyer_id de orderssales apunta al buyer_id de la tabla buyer
        return $this->belongsTo(Buyer::class, 'buyer_id', 'buyer_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    public function domiciliary()
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    public function details()
    {
        return $this->hasMany(OrdersSalesDetail::class, 'orderSales_id', 'orderSales_id');
    }

    public function promotions()
    {
        return $this->hasMany(OrderPromotion::class, 'orderSales_id', 'orderSales_id');
    }

    public function payments()
    {
        return $this->hasOne(Payment::class, 'orderSales_id', 'orderSales_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethods::class, 'methods_id');
    }

    public function paymentForm()
    {
        return $this->belongsTo(PaymentForms::class, 'forms_id');
    }

    public function address()
    {
        return $this->belongsTo(UserAddress::class, 'address_id', 'address_id');
    }

    /**
     * Transformar la orden para API frontend
     */
    public function toApi(): array
    {
        return [
            'order_id' => $this->orderSales_id,
            'buyer_id' => $this->buyer_id,
            'busines_id' => $this->busines_id,
            'total' => $this->total,
            'sale_date' => $this->sale_date,
            'delivery_date' => $this->delivery_date,
            'state' => $this->state,
            'is_scheduled' => $this->is_scheduled,
            'has_review' => $this->has_review,
            'dispatched_at' => $this->dispatched_at,
            // Compromiso y cumplimiento. `promised_minutes` es lo que el reloj
            // del seguimiento tiene que contar: antes la app usaba un 20 fijo
            // porque leía un campo que el servidor nunca mandó.
            'promised_minutes' => $this->promised_minutes,
            'delivery_minutes' => $this->delivery_minutes,
            'on_time' => $this->on_time,
            'delay_minutes' => $this->delay_minutes,
            'domiciliary' => $this->domiciliary ? [
                'domiciliary_id' => $this->domiciliary->domiciliary_id,
                'user_id' => $this->domiciliary->user->user_id ?? null,
                'name' => $this->domiciliary->user->name ?? null,
                'phone' => $this->domiciliary->user->phone ?? null,
                'qualification' => $this->domiciliary->qualification,
            ] : null,
            'business' => [
                'business_id' => $this->business->busines_id ?? null,
                'name' => $this->business->name ?? null,
                'address' => $this->business->address ?? null,
                'latitude' => $this->business->latitude ? (float) $this->business->latitude : null,
                'longitude' => $this->business->longitude ? (float) $this->business->longitude : null,
                'phone' => $this->business->phone ?? null,
                'city' => $this->business->city ?? null,
                'state' => $this->business->state ?? null,
                'logo' => $this->business->logo ?? null,
            ],
            'delivery_address' => $this->address ? [
                'address_id' => $this->address->address_id,
                'address' => $this->address->address ?? null,
                'alias' => $this->address->alias?->name,
                'municipality' => $this->address->municipality?->name,
                'department' => $this->address->department?->name,
                'country' => $this->address->country?->name,
                'latitude' => $this->address->latitude ? (float) $this->address->latitude : null,
                'longitude' => $this->address->longitude ? (float) $this->address->longitude : null,
            ] : null,
            'details' => $this->details->map(fn($d) => [
                'product_id' => $d->product->products_id ?? null,
                'name' => $d->product->name ?? null,
                'description' => $d->product->description ?? null,
                'category' => $d->product->category?->name ?? null,
                'image' => $d->product->image ?? null,
                'amount' => $d->amount,
                'unit_price' => $d->unit_price,
            ])->toArray(),
            'promotions' => $this->promotions ?? [],
            'payments' => $this->payments ?? null,
        ];
    }
}
