<?php

namespace App\Models\Order;

use App\Models\Audit\Audit;
use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;

class OrdersSalesDetail extends Audit
{
    protected $table = 'orderssales_detail';
    protected $primaryKey = 'orderDet_id';
    public $timestamps = false;

    protected $fillable = ['orderSales_id', 'product_id', 'amount', 'unit_price'];

    public function order()
    {
        return $this->belongsTo(OrdersSales::class, 'orderSales_id', 'orderSales_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'products_id');
    }
}
