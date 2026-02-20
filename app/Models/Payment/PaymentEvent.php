<?php

namespace App\Models\Payment;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Audit
{
     use HasFactory;

    protected $table = 'payment_events';

    protected $fillable = [
        'provider',
        'event_type',
        'reference_id',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
