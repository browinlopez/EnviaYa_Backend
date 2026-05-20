<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSaleDetail extends Audit
{
    public $timestamps = false;

    protected $fillable = ['order_sales_id', 'product_id', 'quantity', 'unit_price'];

    protected $table = 'order_sales_details';

    public function order()
    {
        return $this->belongsTo(OrderSale::class, 'order_sales_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
