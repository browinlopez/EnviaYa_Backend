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

    /*
     * `expiration_date` se queda en la lista pero YA NO SE USA: el
     * vencimiento se mudó a `products_business` porque es del lote de cada
     * tienda, no del producto compartido. Quitarlo de aquí rompería las
     * filas viejas que todavía lo traen.
     */
    protected $fillable = [
        'products_id',
        'active_ingredient',
        'dosage',
        'content',
        'presentation',
        'registro_invima',
        'requiere_formula',
        'expiration_date',
    ];

    protected $casts = ['requiere_formula' => 'boolean'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
