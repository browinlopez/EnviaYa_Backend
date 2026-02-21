<?php

namespace App\Models\Payment;

use App\Models\Audit\Audit;
use App\Models\Order\OrdersSales;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Audit
{
    use HasFactory;

    protected $table = 'payments';
    protected $primaryKey = 'payments_id';
    public $timestamps = true;

    protected $fillable = [
        'orderSales_id',
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
        return $this->belongsTo(\App\Models\Order\OrdersSales::class, 'orderSales_id');
    }

    public function intent()
    {
        return $this->hasOne(PaymentIntent::class, 'orderSales_id', 'orderSales_id');
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethods::class, 'methods_id', 'methods_id');
    }

    public function form()
    {
        return $this->belongsTo(PaymentForms::class, 'forms_id', 'forms_id');
    }
}
