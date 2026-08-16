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
        // Parte del domicilio que le queda al domiciliario (el resto es de la
        // plataforma). Se calcula al crear la orden y no se recalcula después.
        'domiciliary_fee',
        'sale_date',
        'delivery_date',
        'is_scheduled',
        'pickup',
        'pickup_time',
        'has_review',
        'state'
    ];

    protected $casts = [
        'is_scheduled' => 'boolean',
        'sale_date' => 'datetime',
        'delivery_date' => 'datetime',
    ];

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
