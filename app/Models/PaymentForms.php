<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentForms extends Model
{
    protected $table = 'payment_forms';
    public $timestamps = false;

    protected $fillable = ['name', 'state'];

    public function methods()
    {
        return $this->belongsToMany(PaymentMethods::class, 'payment_method_forms', 'payment_form_id', 'payment_method_id');
    }
}
