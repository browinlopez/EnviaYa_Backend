<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderSale extends Audit
{
    use HasFactory;

    protected $table = 'orders_sales';
    public $timestamps = true;

    protected $fillable = [
        'buyer_id',
        'business_id',
        'domiciliary_id',
        'address_id',
        'methods_id',
        'forms_id',
        'total',
        'sale_date',
        'delivery_date',
        'is_scheduled',
        'pickup',
        'pickup_time',
        'has_review',
        'payment_state',
        'state'
    ];

    protected $casts = [
        'is_scheduled' => 'boolean',
        'sale_date' => 'datetime',
        'delivery_date' => 'datetime',
    ];

    public function paymentIntents()
    {
        return $this->hasMany(\App\Models\PaymentIntent::class, 'order_sale_id');
    }

    public function paymentTransactions()
    {
        return $this->hasManyThrough(
            PaymentTransaction::class,
            PaymentIntent::class,
            'order_sale_id',
            'payment_intent_id'
        );
    }


    public function buyer()
    {
        return $this->belongsTo(Buyer::class, 'buyer_id', 'id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id', 'id');
    }

    public function domiciliary()
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'id');
    }

    public function details()
    {
        return $this->hasMany(OrderSaleDetail::class, 'order_sales_id', 'id');
    }

    public function promotions()
    {
        return $this->hasMany(OrderPromotion::class, 'order_sales_id', 'id');
    }

    public function payments()
    {
        return $this->hasOne(Payment::class, 'order_sale_id', 'id');
    }

    public function paymentAttempts()
    {
        return $this->hasMany(\App\Models\PaymentAttempt::class, 'order_sale_id', 'id');
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
        return $this->belongsTo(UserAddress::class, 'address_id', 'id');
    }

    /**
     * Transformar la orden para API frontend
     */
    public function toApi(): array
    {
        return [
            'order_id' => $this->id,
            'buyer_id' => $this->buyer_id,
            'business_id' => $this->business_id,
            'total' => $this->total,
            'sale_date' => $this->sale_date,
            'delivery_date' => $this->delivery_date,
            'state' => $this->state,
            'is_scheduled' => $this->is_scheduled,
            'has_review' => $this->has_review,
            'business' => [
                'business_id' => $this->business->id ?? null,
                'name' => $this->business->name ?? null,
                'address' => $this->business->address ?? null,
                'latitude' => $this->business->latitude ? (float) $this->business->latitude : null,
                'longitude' => $this->business->longitude ? (float) $this->business->longitude : null,
                'phone' => $this->business->phone ?? null,
                'state' => $this->business->state ?? null,
                'logo' => $this->business->logo ?? null,
            ],
            'delivery_address' => $this->address ? [
                'address_id' => $this->address->id,
                'address' => $this->address->address ?? null,
                'alias' => $this->address->alias?->name,
                'municipality' => $this->address->municipality?->name,
                'department' => $this->address->department?->name,
                'country' => $this->address->country?->name,
                'latitude' => $this->address->latitude ? (float) $this->address->latitude : null,
                'longitude' => $this->address->longitude ? (float) $this->address->longitude : null,
            ] : null,
            'details' => $this->details->map(fn($d) => [
                'product_id' => $d->product->id ?? null,
                'name' => $d->product->name ?? null,
                'description' => $d->product->description ?? null,
                'category' => $d->product->category?->name ?? null,
                'image' => $d->product->image ?? null,
                'quantity' => $d->quantity,
                'unit_price' => $d->unit_price,
            ])->toArray(),
            'promotions' => $this->promotions ?? [],
            'payments' => $this->payments ?? null,
        ];
    }
}
