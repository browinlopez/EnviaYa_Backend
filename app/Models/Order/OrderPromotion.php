<?php

namespace App\Models\Order;

use App\Models\Audit\Audit;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderPromotion extends Audit
{
    use HasFactory;

    protected $table = 'orders_promotions';
    protected $primaryKey = 'promOrd_id';
    public $timestamps = false;

    protected $fillable = ['orderSales_id', 'promotion_id', 'state'];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class, 'promotion_id', 'promotion_id');
    }
}
