<?php

declare(strict_types=1);

namespace App\Models\Product;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroceryProduct extends Audit
{
    protected $table = 'grocery_products';

    protected $primaryKey = 'grocery_product_id';

    public $timestamps = false;

    protected $fillable = ['products_id', 'brand', 'size', 'expiration_date'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
