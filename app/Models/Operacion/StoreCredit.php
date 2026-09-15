<?php

namespace App\Models\Operacion;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * El cupo que una tienda le da a un comprador. Lo usado NO vive acá: es la
 * suma de sus movimientos (ver `CreditoDeTienda`).
 */
class StoreCredit extends Model
{
    protected $table = 'store_credits';

    protected $fillable = ['busines_id', 'user_id', 'credit_limit', 'created_by'];

    protected $casts = [
        'busines_id'   => 'integer',
        'user_id'      => 'integer',
        'credit_limit' => 'decimal:2',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function movements()
    {
        return $this->hasMany(StoreCreditMovement::class, 'store_credit_id');
    }
}
