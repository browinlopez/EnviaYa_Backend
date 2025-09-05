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
