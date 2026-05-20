<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentIntent extends Audit
{
    use HasFactory;

    protected $table = 'payment_intents';

    protected $fillable = [
        'order_sale_id',
        'provider',
        'bold_reference_id',
        'amount',
        'currency',
        'status',
        'payload',
        'response',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(\App\Models\OrderSale::class, 'order_sale_id');
    }

    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_intent_id');
    }
}
