<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBusiness extends Audit
{
    protected $table = 'product_businesses';
    public $timestamps = false;

    protected $fillable = [
        'busines_id',
        'products_id',
        'price',
        'quantity',
        'qualification',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'busines_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'id');
    }
}
