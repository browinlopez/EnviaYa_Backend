<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentAttempt extends Audit
{
    use HasFactory;

    protected $table = 'payment_attempts';

    protected $fillable = [
        'order_sale_id',
        'reference_id',
        'transaction_id',
        'payment_method_id',
        'payment_form_id',
        'status',
        'request_payload',
        'response_payload',
        'error_payload',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'error_payload' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(OrderSale::class, 'order_sale_id');
    }
}
