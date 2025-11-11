<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

class CarPartsProducts extends Model
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