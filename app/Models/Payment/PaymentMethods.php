<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\Audit\Audit;

class PaymentMethods extends Audit
{
    protected $table = 'payment_methods';

    protected $primaryKey = 'methods_id';

    public $timestamps = false;

    protected $fillable = ['name', 'state', 'logo'];

    public function forms()
    {
        return $this->belongsToMany(PaymentForms::class, 'payment_method_forms', 'methods_id', 'forms_id');
    }
}
