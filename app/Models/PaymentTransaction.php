<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Audit
{
    use HasFactory;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'payment_intent_id',
        'provider_transaction_id',
        'method',
        'status',
        'amount',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
    ];

    public function intent()
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}
