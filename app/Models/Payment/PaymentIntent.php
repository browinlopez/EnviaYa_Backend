<?php

namespace App\Models\Payment;

use App\Models\Audit\Audit;
use App\Models\Order\OrdersSales;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        return $this->belongsTo(OrdersSales::class, 'orderSales_id');
    }

    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_intent_id');
    }
}
