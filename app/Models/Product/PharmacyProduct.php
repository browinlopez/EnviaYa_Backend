<?php

namespace App\Models\Product;

use App\Models\Audit\Audit;

class PharmacyProduct extends Audit
{
    protected $table = 'pharmacy_products';
    protected $primaryKey = 'pharmacy_product_id';
    public $timestamps = false;

    protected $fillable = ['products_id', 'active_ingredient', 'dosage', 'presentation', 'expiration_date'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
