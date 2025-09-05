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
    public $timestamps = false;

    protected $fillable = [
        'orderSales_id',
        'methods_id',
        'forms_id',
        'amount',
        'payment_status',
        'payment_date',
        'state'
    ];

    public function order()
    {
        return $this->belongsTo(OrdersSales::class, 'orderSales_id', 'orderSales_id');
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
