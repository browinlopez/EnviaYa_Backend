<?php

namespace App\Models\Payment;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Model;

class PaymentMethods extends Audit
{
    protected $table = 'payment_methods';
    protected $primaryKey = 'methods_id';
    public $timestamps = false;

    protected $fillable = ['name', 'state'];

     public function forms()
    {
        return $this->belongsToMany(PaymentForms::class, 'payment_method_forms', 'methods_id', 'forms_id');
    }
}
