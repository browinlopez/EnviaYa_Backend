<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

class GroceryProduct extends Model
{
    protected $table = 'grocery_products';
    protected $primaryKey = 'grocery_product_id';
    public $timestamps = false;

    protected $fillable = ['products_id', 'brand', 'size', 'expiration_date'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
