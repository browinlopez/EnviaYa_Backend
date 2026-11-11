<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\Audit\Audit;

class PaymentForms extends Audit
{
    protected $table = 'payment_forms';

    protected $primaryKey = 'forms_id';

    public $timestamps = false;

    protected $fillable = ['name', 'state'];

    public function methods()
    {
        return $this->belongsToMany(PaymentMethods::class, 'payment_method_forms', 'forms_id', 'methods_id');
    }
}
