<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Audit
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'order_sale_id',
        'methods_id',
        'forms_id',
        'provider',
        'provider_payment_id',
        'amount',
        'subtotal',
        'total',
        'domicilio',
        'valor_promocion',
        'payment_status',
        'status',
        'provider_snapshot',
        'payment_date',
        'redirect_url',
        'qr_payload',
        'qr_expires_at',
        'state'
    ];

    protected $casts = [
        'provider_snapshot' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(\App\Models\OrderSale::class, 'order_sale_id');
    }

    public function intent()
    {
        return $this->hasOne(PaymentIntent::class, 'order_sale_id', 'order_sale_id');
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethods::class, 'methods_id');
    }

    public function form()
    {
        return $this->belongsTo(PaymentForms::class, 'forms_id', 'forms_id');
    }
}
