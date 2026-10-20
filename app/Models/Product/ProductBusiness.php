<?php

namespace App\Models\Product;

use App\Models\Audit\Audit;
use App\Models\Business;
use Illuminate\Database\Eloquent\Model;

class ProductBusiness extends Audit
{
    protected $table = 'products_business';
    protected $primaryKey = "busines_products_id"; // 👈 es tabla pivote con PK compuesta
    public $timestamps = false;

    protected $fillable = [
        'busines_id',
        'products_id',
        'price',
        'amount',
        'qualification',
        // El vencimiento es del LOTE de esta tienda, no del producto compartido.
        'expiration_date',
        /*
         * Como lo vende ESTA tienda: por unidad o al peso. Es del par
         * tienda-producto y no del producto, porque la misma referencia va
         * empacada en una tienda y al peso en la de al lado. Ver
         * `App\Support\UnidadDeVenta`.
         *
         * `price` no cambia de nombre ni de tipo: pasa a significar «precio
         * por `sale_unit`» --precio por kilo si se vende por kilo--. Dos
         * columnas de precio serian dos sitios diciendo lo mismo, y uno de
         * ellos acabaria desactualizado.
         */
        'sale_unit',
        'stock_weight',
        'min_qty',
        'step_qty',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'stock_weight' => 'decimal:3',
        'min_qty'      => 'decimal:3',
        'step_qty'     => 'decimal:3',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }
}
