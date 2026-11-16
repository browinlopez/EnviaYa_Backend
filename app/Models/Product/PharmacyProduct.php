<?php

declare(strict_types=1);

namespace App\Models\Product;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacyProduct extends Audit
{
    protected $table = 'pharmacy_products';

    protected $primaryKey = 'pharmacy_product_id';

    public $timestamps = false;

    protected $fillable = ['products_id', 'active_ingredient', 'dosage', 'presentation', 'expiration_date'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
