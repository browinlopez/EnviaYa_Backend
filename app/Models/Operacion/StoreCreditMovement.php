<?php

namespace App\Models\Operacion;

use App\Models\Order\OrdersSales;
use Illuminate\Database\Eloquent\Model;

/**
 * Un apunte del libro de crédito. El signo es lo que hace a lo USADO:
 * consumo suma, reverso y abono restan, ajuste va con el signo que traiga.
 */
class StoreCreditMovement extends Model
{
    protected $table = 'store_credit_movements';

    public const CONSUMO = 'consumo';
    public const REVERSO = 'reverso';
    public const ABONO   = 'abono';
    public const AJUSTE  = 'ajuste';

    protected $fillable = ['store_credit_id', 'type', 'amount', 'order_id', 'notes', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function credit()
    {
        return $this->belongsTo(StoreCredit::class, 'store_credit_id');
    }

    public function order()
    {
        return $this->belongsTo(OrdersSales::class, 'order_id', 'orderSales_id');
    }
}
