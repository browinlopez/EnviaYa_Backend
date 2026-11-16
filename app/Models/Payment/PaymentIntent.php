<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\Audit\Audit;
use App\Models\Order\OrdersSales;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return BelongsTo<OrdersSales, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrdersSales::class, 'orderSales_id');
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_intent_id');
    }
}
