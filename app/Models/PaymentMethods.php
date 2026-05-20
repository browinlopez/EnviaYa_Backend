<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethods extends Model
{
    protected $table = 'payment_methods';
    public $timestamps = false;

    protected $fillable = ['name', 'state', 'logo'];

     public function forms()
    {
        return $this->belongsToMany(PaymentForms::class, 'payment_method_forms', 'payment_method_id', 'payment_form_id');
    }
}
