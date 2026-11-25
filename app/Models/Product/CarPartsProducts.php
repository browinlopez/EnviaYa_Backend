<?php

declare(strict_types=1);

namespace App\Models\Product;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

        /* Las dos preguntas del mostrador: de que lado va, y si es original.
           Llevarse el freno del otro lado es el error clasico. */
        'posicion',
        'condicion',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
