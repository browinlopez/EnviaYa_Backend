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

        /* Lo que le faltaba a un plato. `minutos_preparacion` es lo que hace
           que la promesa de entrega deje de ser una adivinanza, y
           `acompanamientos` es lo que HACE que un corrientazo sea un
           corrientazo. */
        'minutos_preparacion',
        'acompanamientos',
        'es_vegetariano',
    ];

    protected $casts = [
        'is_vegan' => 'boolean',
        'is_gluten_free' => 'boolean',
        'es_vegetariano' => 'boolean',
        'minutos_preparacion' => 'integer',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
