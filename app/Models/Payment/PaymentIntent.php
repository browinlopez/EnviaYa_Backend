<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Audit\Audit;

class PaymentIntent extends Audit
{
     use HasFactory;

    protected $table = 'payment_intents';

    protected $fillable = [
        'orderSales_id',
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
        return $this->belongsTo(\App\Models\Order\OrdersSales::class, 'orderSales_id');
    }

    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_intent_id');
    }
}
