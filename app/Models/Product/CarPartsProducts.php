<?php

namespace App\Models\Product;

use App\Models\Audit\Audit;

class CarPartsProducts extends Audit
{
    protected $primaryKey = 'car_part_product_id';

    protected $fillable = [
        'products_id',
        'brand',
        'model',
        'year',
        'oem_code',
        'compatibility',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}