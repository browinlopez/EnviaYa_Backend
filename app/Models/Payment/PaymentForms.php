<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PaymentForms extends Audit
{
    protected $table = 'payment_forms';

    protected $primaryKey = 'forms_id';

    public $timestamps = false;

    protected $fillable = ['name', 'state'];

    /** @return BelongsToMany<PaymentMethods, $this> */
    public function methods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethods::class, 'payment_method_forms', 'forms_id', 'methods_id');
    }
}
