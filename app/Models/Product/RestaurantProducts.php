<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

class RestaurantProducts extends Model
{
    protected $primaryKey = 'restaurant_product_id';

    protected $fillable = [
        'products_id',
        'food_type',
        'portion_size',
        'is_vegan',
        'is_gluten_free',
        'allergens',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
