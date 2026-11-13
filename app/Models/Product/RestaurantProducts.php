<?php

declare(strict_types=1);

namespace App\Models\Product;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantProducts extends Audit
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
