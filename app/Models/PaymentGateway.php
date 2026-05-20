<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    protected $table = 'payment_gateways';

    protected $fillable = [
        'name',
        'class',
        'state',
    ];

    protected $casts = [
        'state' => 'boolean',
    ];
}
