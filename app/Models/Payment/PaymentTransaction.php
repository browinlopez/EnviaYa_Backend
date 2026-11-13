<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<PaymentIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}
