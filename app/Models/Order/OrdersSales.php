<?php

namespace App\Models\Order;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\Domiciliary;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentForms;
use App\Models\Payment\PaymentMethods;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrdersSales extends Audit
{
     use HasFactory;

    protected $table = 'orderssales';
    protected $primaryKey = 'orderSales_id';
    public $timestamps = false;

    protected $fillable = [
        'buyer_id',
        'busines_id',
        'domiciliary_id',
        'delivery_address',
        'methods_id',
        'forms_id',
        'total',
        'sale_date',
        'state'
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id', 'user_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    public function domiciliary()
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    public function details()
    {
        return $this->hasMany(OrdersSalesDetail::class, 'orderSales_id', 'orderSales_id');
    }

    public function promotions()
    {
        return $this->hasMany(OrderPromotion::class, 'orderSales_id', 'orderSales_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'orderSales_id', 'orderSales_id');
    }

     public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethods::class, 'methods_id');
    }

    public function paymentForm()
    {
        return $this->belongsTo(PaymentForms::class, 'forms_id');
    }
}
